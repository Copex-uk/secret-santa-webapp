<?php
declare(strict_types=1);

/**
 * mailer.php — dependency-free SMTP client for shared hosting.
 * Supports implicit TLS (port 465) and STARTTLS (port 587/25) with AUTH LOGIN.
 */

/**
 * Send a plain-text email through the SMTP server from config.
 * Returns true on success, false on failure (errors are logged, not shown).
 */
function smtp_send(string $toEmail, string $subject, string $body, ?string $htmlBody = null, array $inlineImages = []): bool
{
    $cfg = config()['smtp'] ?? null;
    if (!$cfg) {
        error_log('smtp_send: SMTP not configured');
        return false;
    }
    try {
        smtp_send_raw(
            (string)$cfg['host'],
            (int)$cfg['port'],
            (string)$cfg['user'],
            (string)$cfg['pass'],
            (string)$cfg['from_email'],
            (string)$cfg['from_name'],
            $toEmail,
            $subject,
            $body,
            $htmlBody,
            $inlineImages
        );
        return true;
    } catch (Throwable $t) {
        error_log('smtp_send failed to ' . $toEmail . ': ' . $t->getMessage());
        return false;
    }
}

/** Low-level SMTP conversation. Throws RuntimeException on any protocol error. */
function smtp_send_raw(
    string $host,
    int $port,
    string $user,
    string $pass,
    string $fromEmail,
    string $fromName,
    string $toEmail,
    string $subject,
    string $body,
    ?string $htmlBody = null,
    array $inlineImages = []
): void {
    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Invalid recipient email.');
    }

    $implicitTls = ($port === 465);
    $remote = ($implicitTls ? 'ssl://' : 'tcp://') . $host . ':' . $port;
    $ctx = stream_context_create(['ssl' => [
        'verify_peer'      => true,
        'verify_peer_name' => true,
        'SNI_enabled'      => true,
    ]]);
    $fp = @stream_socket_client($remote, $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) {
        throw new RuntimeException("Connect failed: $errstr ($errno)");
    }
    stream_set_timeout($fp, 20);

    $read = function () use ($fp): string {
        $data = '';
        while (($line = fgets($fp, 1024)) !== false) {
            $data .= $line;
            // Multi-line replies use "250-", final line uses "250 "
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        return $data;
    };
    $cmd = function (string $c, array $expect) use ($fp, $read): string {
        fwrite($fp, $c . "\r\n");
        $resp = $read();
        $code = (int)substr($resp, 0, 3);
        if (!in_array($code, $expect, true)) {
            throw new RuntimeException("SMTP error after '" . strtok($c, ' ') . "': " . trim($resp));
        }
        return $resp;
    };

    $greeting = $read();
    if ((int)substr($greeting, 0, 3) !== 220) {
        throw new RuntimeException('Bad SMTP greeting: ' . trim($greeting));
    }

    $me = gethostname() ?: 'localhost';
    $cmd('EHLO ' . $me, [250]);

    if (!$implicitTls) {
        $cmd('STARTTLS', [220]);
        if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            throw new RuntimeException('STARTTLS negotiation failed.');
        }
        $cmd('EHLO ' . $me, [250]);
    }

    if ($user !== '') {
        $cmd('AUTH LOGIN', [334]);
        $cmd(base64_encode($user), [334]);
        $cmd(base64_encode($pass), [235]);
    }

    // Envelope
    $cmd('MAIL FROM:<' . $fromEmail . '>', [250]);
    $cmd('RCPT TO:<' . $toEmail . '>', [250, 251]);
    $cmd('DATA', [354]);

    // Headers — strip CR/LF from injected values to prevent header injection
    $clean = fn(string $s): string => str_replace(["\r", "\n"], '', $s);
    $encodedSubject = '=?UTF-8?B?' . base64_encode($clean($subject)) . '?=';
    $encodedFrom = '=?UTF-8?B?' . base64_encode($clean($fromName)) . '?=';

    $headers = [
        'Date: ' . date('r'),
        'From: ' . $encodedFrom . ' <' . $clean($fromEmail) . '>',
        'To: <' . $clean($toEmail) . '>',
        'Subject: ' . $encodedSubject,
        'MIME-Version: 1.0',
        'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $clean($host) . '>',
    ];

    if ($htmlBody !== null) {
        // multipart/alternative: plain-text part first, HTML preferred.
        $boundary = 'b' . bin2hex(random_bytes(16));
        $alt = "--$boundary\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n\r\n"
            . $body . "\r\n"
            . "--$boundary\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n\r\n"
            . $htmlBody . "\r\n"
            . "--$boundary--";

        if ($inlineImages) {
            /*
             * multipart/related wraps the alternative part plus any inline
             * images, referenced from the HTML as <img src="cid:...">.
             * Attached images display by default in most clients, unlike
             * remote ones which are usually blocked.
             */
            $rel = 'r' . bin2hex(random_bytes(16));
            $headers[] = 'Content-Type: multipart/related; boundary="' . $rel . '"';
            $payload = "--$rel\r\n"
                . 'Content-Type: multipart/alternative; boundary="' . $boundary . "\"\r\n\r\n"
                . $alt . "\r\n";
            foreach ($inlineImages as $cid => $img) {
                $cidSafe  = preg_replace('/[^A-Za-z0-9._-]/', '', (string)$cid) ?? 'img';
                $mimeType = (string)($img['mime'] ?? 'image/png');
                $mimeType = in_array($mimeType, ['image/png', 'image/jpeg'], true) ? $mimeType : 'image/png';
                $payload .= "--$rel\r\n"
                    . 'Content-Type: ' . $mimeType . "\r\n"
                    . "Content-Transfer-Encoding: base64\r\n"
                    . 'Content-ID: <' . $cidSafe . ">\r\n"
                    . 'Content-Disposition: inline; filename="' . $cidSafe . '.'
                    . ($mimeType === 'image/jpeg' ? 'jpg' : 'png') . "\"\r\n\r\n"
                    . chunk_split(base64_encode((string)($img['data'] ?? '')), 76, "\r\n");
            }
            $payload .= "--$rel--";
        } else {
            $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
            $payload = $alt;
        }
    } else {
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        $headers[] = 'Content-Transfer-Encoding: 8bit';
        $payload = $body;
    }

    // Dot-stuffing per RFC 5321
    $bodyLines = preg_split('/\r\n|\r|\n/', $payload) ?: [];
    $stuffed = array_map(
        fn(string $l): string => (isset($l[0]) && $l[0] === '.') ? '.' . $l : $l,
        $bodyLines
    );

    fwrite($fp, implode("\r\n", $headers) . "\r\n\r\n" . implode("\r\n", $stuffed) . "\r\n.\r\n");
    $resp = $read();
    if ((int)substr($resp, 0, 3) !== 250) {
        throw new RuntimeException('Message not accepted: ' . trim($resp));
    }
    fwrite($fp, "QUIT\r\n");
    fclose($fp);
}
