<?php
declare(strict_types=1);

require_once __DIR__ . '/../helpers.php';

/**
 * send_sms(): SMS_DRIVER=log writes to storage/logs/sms.log (testing, no cost),
 * SMS_DRIVER=arkesel calls a Ghana SMS provider using your registered sender ID.
 * To use another provider (Hubtel, Africa's Talking, ...), add a branch here.
 * Check the provider's current API docs: the request format below follows Arkesel's v2 API.
 */
function send_sms(string $e164_number, string $message): bool
{
    $driver = cfg('SMS_DRIVER', 'log');
    if ($driver === 'log') {
        plain_log('sms.log', "TO: $e164_number | $message");
        return true;
    }
    if ($driver === 'arkesel') {
        $payload = json_encode([
            'sender'     => (string)cfg('SMS_SENDER_ID', 'App'),
            'message'    => $message,
            'recipients' => [ltrim($e164_number, '+')],
        ]);
        $ch = curl_init((string)cfg('ARKESEL_URL', 'https://sms.arkesel.com/api/v2/sms/send'));
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'api-key: ' . cfg('ARKESEL_API_KEY', '')],
        ]);
        $res  = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($res === false || $code >= 400) {
            plain_log('sms.log', "ARKESEL FAILED ($code) to $e164_number: " . (string)$res);
            return false;
        }
        return true;
    }
    plain_log('sms.log', "Unknown SMS_DRIVER '$driver'");
    return false;
}

/**
 * Sends a 6-digit code to a phone. Cost controls: 60s between sends, daily cap per number,
 * and only accounts with a verified email may trigger an SMS (so bots cannot drain credit).
 */
function send_phone_code(array $user, string $phone): void
{
    if (!$user['email_verified_at']) {
        fail(403, 'Verify your email first.', 'email_not_verified');
    }
    $last = val('SELECT MAX(sent_at) FROM sms_log WHERE phone = ?', [$phone]);
    if ($last && ts($last) > time() - 60) {
        fail(429, 'Please wait 60 seconds before asking for another code.', 'rate_limited');
    }
    $cap = (int)setting('sms_daily_cap_per_number', '3');
    $today = (int)val('SELECT COUNT(*) FROM sms_log WHERE phone = ? AND sent_at > ?', [$phone, plus('-1 day')]);
    if ($today >= $cap) {
        fail(429, 'Daily code limit reached for this number. Try again tomorrow or use the email fallback.', 'sms_cap');
    }
    $code = (string)random_int(100000, 999999);
    q("UPDATE verification_codes SET used_at = ? WHERE user_id = ? AND channel = 'phone' AND used_at IS NULL", [now(), $user['id']]);
    q(
        "INSERT INTO verification_codes (user_id, channel, target, code_hash, expires_at) VALUES (?, 'phone', ?, ?, ?)",
        [$user['id'], $phone, hash('sha256', $code), plus('+' . (int)setting('sms_code_minutes', '10') . ' minutes')]
    );
    q('INSERT INTO sms_log (user_id, phone, purpose, device_id) VALUES (?,?,?,?)', [$user['id'], $phone, 'payment_verification', $_SERVER['HTTP_X_DEVICE_ID'] ?? null]);
    send_sms($phone, (string)cfg('APP_NAME', 'App') . ": your verification code is $code. It expires in " . (int)setting('sms_code_minutes', '10') . ' minutes.');
}
