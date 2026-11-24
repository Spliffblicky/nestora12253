<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers.php';

/**
 * send_mail(): MAIL_DRIVER=log writes to storage/logs/mail.log (local testing),
 * MAIL_DRIVER=smtp sends through your SMTP provider (Gmail, SendGrid, Mailgun, ...).
 */
function send_mail(string $to, string $subject, string $body): bool
{
    if (cfg('MAIL_DRIVER', 'log') !== 'smtp') {
        plain_log('mail.log', "TO: $to | SUBJECT: $subject | BODY: " . str_replace("\n", ' / ', $body));
        return true;
    }
    try {
        return smtp_send($to, $subject, $body);
    } catch (Throwable $e) {
        plain_log('mail.log', "SMTP FAILED to $to: " . $e->getMessage());
        return false;
    }
}

function smtp_read($fp): string
{
    $data = '';
    while (($line = fgets($fp, 515)) !== false) {
        $data .= $line;
        if (strlen($line) < 4 || $line[3] === ' ') {
            break;
        }
    }
    return $data;
}

function smtp_cmd($fp, string $cmd, array $ok): string
{
    fwrite($fp, $cmd . "\r\n");
    $r = smtp_read($fp);
    if (!in_array((int)substr($r, 0, 3), $ok, true)) {
        throw new RuntimeException("SMTP error after '" . substr($cmd, 0, 12) . "': " . trim($r));
    }
    return $r;
}

function smtp_send(string $to, string $subject, string $body): bool
{
    $host   = (string)cfg('SMTP_HOST');
    $port   = (int)cfg('SMTP_PORT', 587);
    $secure = (string)cfg('SMTP_SECURE', 'tls');
    $from   = (string)cfg('MAIL_FROM', 'no-reply@example.com');
    $name   = (string)cfg('MAIL_FROM_NAME', (string)cfg('APP_NAME', 'App'));

    $fp = stream_socket_client(($secure === 'ssl' ? 'ssl://' : 'tcp://') . "$host:$port", $en, $es, 15);
    if (!$fp) {
        throw new RuntimeException("Cannot connect to SMTP: $es");
    }
    stream_set_timeout($fp, 15);
    smtp_read($fp);
    smtp_cmd($fp, 'EHLO localhost', [250]);
    if ($secure === 'tls') {
        smtp_cmd($fp, 'STARTTLS', [220]);
        if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            throw new RuntimeException('STARTTLS failed');
        }
        smtp_cmd($fp, 'EHLO localhost', [250]);
    }
    $user = (string)cfg('SMTP_USER', '');
    if ($user !== '') {
        smtp_cmd($fp, 'AUTH LOGIN', [334]);
        smtp_cmd($fp, base64_encode($user), [334]);
        smtp_cmd($fp, base64_encode((string)cfg('SMTP_PASS', '')), [235]);
    }
    smtp_cmd($fp, "MAIL FROM:<$from>", [250]);
    smtp_cmd($fp, "RCPT TO:<$to>", [250, 251]);
    smtp_cmd($fp, 'DATA', [354]);

    $headers = [
        'From: ' . mb_encode_mimeheader($name) . " <$from>",
        "To: <$to>",
        'Subject: ' . mb_encode_mimeheader($subject),
        'Date: ' . gmdate('r'),
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ];
    $msg = implode("\r\n", $headers) . "\r\n\r\n" . preg_replace('/^\./m', '..', str_replace(["\r\n", "\r"], "\n", $body));
    $msg = str_replace("\n", "\r\n", $msg);
    fwrite($fp, $msg . "\r\n.\r\n");
    $r = smtp_read($fp);
    smtp_cmd($fp, 'QUIT', [221]);
    fclose($fp);
    return (int)substr($r, 0, 3) === 250;
}

function email_code_message(string $code): array
{
    $app = (string)cfg('APP_NAME', 'the app');
    return [
        "Your $app verification code",
        "Your verification code is $code.\n\nIt expires in " . setting('verification_code_minutes', '15') . " minutes. If you did not sign up, ignore this email.",
    ];
}
