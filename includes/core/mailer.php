<?php
/**
 * Sends email through Gmail's SMTP server (encrypted), without a library.
 * PHP's mail() doesn't work on WAMP, which has no mail server.
 *
 * Credentials: .env (ROOMEASE_MAIL_USERNAME / _PASSWORD, a Gmail App
 * Password) or config/mail.local.php. Each send is logged to storage/mail.log,
 * never with the message or password.
 */

require_once __DIR__ . '/env.php';

function mail_config()
{
    static $config = null;

    if ($config === null) {
        $config = [
            'username'  => (string) env_value('ROOMEASE_MAIL_USERNAME'),
            'password'  => (string) env_value('ROOMEASE_MAIL_PASSWORD'),
            'from_name' => 'roomease',
            'host'      => 'smtp.gmail.com',
            // 465 = encrypted from the start; 587 = STARTTLS (for networks that block 465).
            'port'      => (int) (env_value('ROOMEASE_MAIL_PORT') ?: 465),
            'ca_bundle' => '',
        ];

        $local = __DIR__ . '/../../config/mail.local.php';
        if (is_file($local)) {
            $file = require $local;
            if (is_array($file)) {
                // .env wins; the file only fills what is missing.
                foreach (['username', 'password'] as $key) {
                    if ($config[$key] === '' && isset($file[$key]) && is_string($file[$key])) {
                        $config[$key] = trim($file[$key]);
                    }
                }
                foreach (['from_name', 'host', 'ca_bundle'] as $key) {
                    if (isset($file[$key]) && is_string($file[$key]) && trim($file[$key]) !== '') {
                        $config[$key] = trim($file[$key]);
                    }
                }
                // Same for the port.
                if (env_value('ROOMEASE_MAIL_PORT') === '' && isset($file['port']) && (int) $file['port'] > 0) {
                    $config['port'] = (int) $file['port'];
                }
            }
        }

        // Remove spaces people copy from Google's "abcd efgh ..." format.
        $config['password'] = preg_replace('/\s+/', '', $config['password']);
    }

    return $config;
}

/** True if the example values from mail.local.example.php are still in place. */
function mail_is_placeholder(array $config)
{
    return $config['password'] === 'abcdefghijklmnop'
        || strpos($config['username'], 'yourname@') === 0
        || $config['username'] === 'roomease.baybay@gmail.com';
}

/** True once a real Gmail address and App Password are configured. */
function mail_enabled()
{
    $config = mail_config();
    return $config['username'] !== '' && $config['password'] !== '' && !mail_is_placeholder($config);
}

/** Send one email. True if Gmail accepted it. $htmlBody is optional. */
function send_mail($to, $subject, $textBody, $htmlBody = null)
{
    if (!mail_enabled()) {
        mail_log($to, mail_is_placeholder(mail_config())
            ? 'not sent - config/mail.local.php still holds the example address or App Password'
            : 'not sent - Gmail is not set up (config/mail.local.php)');
        return false;
    }

    // No line breaks allowed: they could inject extra email headers.
    if (!filter_var($to, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $to . $subject)) {
        mail_log($to, 'not sent - invalid recipient or subject');
        return false;
    }

    $config = mail_config();
    $smtp = null;

    try {
        $smtp = smtp_open($config);

        smtp_command($smtp, 'AUTH LOGIN', 334, 'AUTH');
        smtp_command($smtp, base64_encode($config['username']), 334, 'AUTH username');
        smtp_command($smtp, base64_encode($config['password']), 235, 'AUTH password');

        smtp_command($smtp, 'MAIL FROM:<' . $config['username'] . '>', 250, 'MAIL FROM');
        smtp_command($smtp, 'RCPT TO:<' . $to . '>', [250, 251], 'RCPT TO');
        smtp_command($smtp, 'DATA', 354, 'DATA');

        // SMTP: double leading dots; a lone "." ends the message.
        $message = mail_build_message($config, $to, $subject, $textBody, $htmlBody);
        smtp_command($smtp, preg_replace('/^\./m', '..', $message) . "\r\n.", 250, 'message');

        @fwrite($smtp, "QUIT\r\n");
        fclose($smtp);

        mail_log($to, 'sent');
        return true;
    } catch (RuntimeException $e) {
        if (is_resource($smtp)) {
            fclose($smtp);
        }
        mail_log($to, 'FAILED - ' . $e->getMessage());
        return false;
    }
}

/** Connect to Gmail and encrypt the connection before the password is sent. */
function smtp_open(array $config)
{
    $implicitTls = (int) $config['port'] === 465;

    $ssl = [
        'verify_peer'      => true,
        'verify_peer_name' => true,
        'peer_name'        => $config['host'],
    ];
    if ($config['ca_bundle'] !== '') {
        $ssl['cafile'] = $config['ca_bundle'];
    }

    $address = ($implicitTls ? 'ssl://' : 'tcp://') . $config['host'] . ':' . (int) $config['port'];
    $smtp = @stream_socket_client($address, $errno, $errstr, 15, STREAM_CLIENT_CONNECT,
        stream_context_create(['ssl' => $ssl]));
    if (!$smtp) {
        $reason = $errstr !== '' ? $errstr : (error_get_last()['message'] ?? 'unknown error');
        throw new RuntimeException('could not connect to ' . $address . ' (' . $reason . ')');
    }
    stream_set_timeout($smtp, 15);

    smtp_expect($smtp, 220, 'greeting');
    $hello = 'EHLO ' . mail_hostname();
    smtp_command($smtp, $hello, 250, 'EHLO');

    if (!$implicitTls) {
        smtp_command($smtp, 'STARTTLS', 220, 'STARTTLS');
        $method = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
            $method |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
        }
        if (!@stream_socket_enable_crypto($smtp, true, $method)) {
            fclose($smtp);
            throw new RuntimeException('STARTTLS: could not start encryption');
        }
        smtp_command($smtp, $hello, 250, 'EHLO');
    }

    return $smtp;
}

/** Send one SMTP command and check the reply. Errors name $step, never the command (it may be the password). */
function smtp_command($smtp, $line, $expect, $step)
{
    $data = $line . "\r\n";
    while ($data !== '') {
        $written = @fwrite($smtp, $data);
        if ($written === false || $written === 0) {
            throw new RuntimeException($step . ': connection lost while sending');
        }
        $data = (string) substr($data, $written);
    }
    return smtp_expect($smtp, $expect, $step);
}

/** Read one (possibly multi-line) reply and check its code. */
function smtp_expect($smtp, $expect, $step)
{
    $reply = '';
    while (($line = fgets($smtp, 1024)) !== false) {
        $reply .= $line;
        // "250-..." continues the reply; "250 ..." is its last line.
        if (strlen($line) < 4 || $line[3] !== '-') {
            break;
        }
    }

    if ($reply === '') {
        $meta = stream_get_meta_data($smtp);
        throw new RuntimeException($step . ': ' . ($meta['timed_out'] ? 'no reply (timed out)' : 'connection closed'));
    }
    if (!in_array((int) substr($reply, 0, 3), (array) $expect, true)) {
        throw new RuntimeException($step . ': ' . trim(preg_replace('/\s+/', ' ', $reply)));
    }

    return $reply;
}

/** The complete message: headers, then a plain-text and optional HTML part. */
function mail_build_message(array $config, $to, $subject, $textBody, $htmlBody)
{
    $domain = substr(strrchr($config['username'], '@'), 1) ?: 'localhost';
    $fromName = str_replace(['"', '\\', "\r", "\n"], '', $config['from_name']);

    $headers = [
        'Date: ' . date(DATE_RFC2822),
        'From: ' . mail_encode_header($fromName, true) . ' <' . $config['username'] . '>',
        'To: <' . $to . '>',
        'Subject: ' . mail_encode_header($subject),
        'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $domain . '>',
        'MIME-Version: 1.0',
    ];

    $text = mail_part('text/plain', $textBody);

    if ($htmlBody === null) {
        return implode("\r\n", $headers) . "\r\n" . $text;
    }

    $boundary = 'roomease-' . bin2hex(random_bytes(12));
    $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';

    return implode("\r\n", $headers) . "\r\n\r\n"
        . '--' . $boundary . "\r\n" . $text . "\r\n"
        . '--' . $boundary . "\r\n" . mail_part('text/html', $htmlBody) . "\r\n"
        . '--' . $boundary . '--';
}

/** One body part, base64 encoded. */
function mail_part($type, $body)
{
    $body = preg_replace('/\r\n|\r|\n/', "\r\n", (string) $body);
    return 'Content-Type: ' . $type . '; charset=UTF-8' . "\r\n"
        . 'Content-Transfer-Encoding: base64' . "\r\n\r\n"
        . rtrim(chunk_split(base64_encode($body), 76, "\r\n"));
}

/** A header value, encoded only if it is not plain ASCII. */
function mail_encode_header($value, $quoted = false)
{
    $value = str_replace(["\r", "\n"], '', (string) $value);
    if (preg_match('/^[\x20-\x7E]*$/', $value)) {
        return $quoted ? '"' . $value . '"' : $value;
    }
    return '=?UTF-8?B?' . base64_encode($value) . '?=';
}

/** The name sent to Gmail in the greeting (not checked). */
function mail_hostname()
{
    $name = $_SERVER['SERVER_NAME'] ?? (gethostname() ?: '');
    return preg_match('/^[A-Za-z0-9.-]{1,253}$/', $name) ? $name : 'localhost';
}

function mail_log($to, $outcome)
{
    $logDir = __DIR__ . '/../../storage';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    @file_put_contents(
        $logDir . '/mail.log',
        sprintf('[%s] to %s - %s%s', date('Y-m-d H:i:s'), str_replace(["\r", "\n"], '', (string) $to), $outcome, PHP_EOL),
        FILE_APPEND
    );
}
