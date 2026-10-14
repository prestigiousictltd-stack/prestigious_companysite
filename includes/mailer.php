<?php
/**
 * Mail transport.
 *
 * Configuration is read from environment variables first, then the
 * system_settings table. This makes the platform deployable on hosts like
 * Railway where credentials are injected as env vars, without needing to
 * write them into the database.
 *
 * Transport selection, in priority order:
 *   1. If MAIL_TRANSPORT=api and MAIL_API_PROVIDER=resend, use Resend's
 *      HTTPS API (never blocked by the host).
 *   2. Else if any SMTP config is present (env or settings), use SMTP.
 *   3. Else fall back to PHP mail().
 *
 * Recognised env vars (all optional):
 *   MAIL_TRANSPORT          smtp | api | php
 *   MAIL_API_PROVIDER       resend
 *   MAIL_API_KEY            API key for the provider above
 *   MAIL_HOST / SMTP_HOST   SMTP host
 *   MAIL_PORT / SMTP_PORT   SMTP port (default 587)
 *   MAIL_USER / SMTP_USER   SMTP username
 *   MAIL_PASS / SMTP_PASS   SMTP password
 *   MAIL_ENCRYPTION         tls | ssl | none (auto-detected from port if unset)
 *   MAIL_FROM               default From email
 *   MAIL_FROM_NAME          default From display name
 */

declare(strict_types=1);

// =====================================================================
// Configuration readers
// =====================================================================

/** Read an env var. Accepts multiple names, returns the first non-empty. */
function mail_env(array $keys, ?string $default = null): ?string
{
    foreach ($keys as $k) {
        $v = getenv($k);
        if ($v !== false && $v !== '') {
            return $v;
        }
        if (isset($_ENV[$k]) && $_ENV[$k] !== '') {
            return (string) $_ENV[$k];
        }
        if (isset($_SERVER[$k]) && $_SERVER[$k] !== '') {
            return (string) $_SERVER[$k];
        }
    }
    return $default;
}

/** Read from system_settings, but let env vars win. */
function mail_setting(string $key, array $envKeys, ?string $default = null): ?string
{
    $env = mail_env($envKeys);
    if ($env !== null) {
        return $env;
    }
    if (function_exists('setting')) {
        $v = setting($key, null);
        if ($v !== null && $v !== '') {
            return (string) $v;
        }
    }
    return $default;
}

function mail_transport(): string
{
    $t = strtolower((string) mail_env(['MAIL_TRANSPORT'], ''));
    if (in_array($t, ['smtp', 'api', 'php'], true)) {
        return $t;
    }

    // If an API key is present and the provider is known, prefer API.
    $provider = strtolower((string) mail_env(['MAIL_API_PROVIDER'], ''));
    $apiKey   = (string) mail_env(['MAIL_API_KEY', 'RESEND_API_KEY'], '');
    if ($provider !== '' && $apiKey !== '') {
        return 'api';
    }

    // If any SMTP env var is present, use SMTP.
    if (mail_env(['MAIL_HOST', 'SMTP_HOST']) !== null) {
        return 'smtp';
    }

    // Fall back to the settings table.
    if (function_exists('setting_bool') && setting_bool('smtp_enabled', false)) {
        return 'smtp';
    }

    return 'php';
}

function mail_from_email(): string
{
    $v = mail_setting('smtp_from_email', ['MAIL_FROM', 'SMTP_FROM_EMAIL'], null);
    if ($v !== null && $v !== '') {
        return $v;
    }
    if (function_exists('setting')) {
        $companyEmail = (string) setting('company_email', '');
        if ($companyEmail !== '') {
            return $companyEmail;
        }
    }
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return 'no-reply@' . $host;
}

function mail_from_name(): string
{
    $v = mail_setting('smtp_from_name', ['MAIL_FROM_NAME', 'SMTP_FROM_NAME'], null);
    if ($v !== null && $v !== '') {
        return $v;
    }
    if (function_exists('setting')) {
        $companyName = (string) setting('company_name', '');
        if ($companyName !== '') {
            return $companyName;
        }
    }
    return 'Prestigious ICT';
}

// =====================================================================
// Public entry point
// =====================================================================

function send_mail(string $to, string $subject, string $htmlBody, ?string $textBody = null): bool
{
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $fromEmail = mail_from_email();
    $fromName  = mail_from_name();
    $textBody  = $textBody ?? strip_tags(
        str_replace(['<br>', '<br/>', '<br />'], "\n", $htmlBody)
    );

    $transport = mail_transport();

    switch ($transport) {
        case 'api':
            $ok = mail_via_api($to, $subject, $htmlBody, $textBody, $fromEmail, $fromName);
            if (!$ok) {
                error_log('[mailer] API transport failed for ' . $to . ' — subject: ' . $subject);
            }
            return $ok;

        case 'smtp':
            $ok = mail_via_smtp($to, $subject, $htmlBody, $textBody, $fromEmail, $fromName);
            if (!$ok) {
                error_log('[mailer] SMTP transport failed for ' . $to
                    . ' — check MAIL_HOST/MAIL_PORT/MAIL_USER/MAIL_PASS. '
                    . 'On Railway Free/Trial, outbound SMTP is blocked: use MAIL_TRANSPORT=api instead.');
            }
            return $ok;

        case 'php':
        default:
            $ok = mail_via_php($to, $subject, $htmlBody, $textBody, $fromEmail, $fromName);
            if (!$ok) {
                error_log('[mailer] PHP mail() failed for ' . $to
                    . ' (no SMTP configured and mail() is often disabled on shared hosts).');
            }
            return $ok;
    }
}

// =====================================================================
// Transport 1 — Resend HTTPS API
// =====================================================================

function mail_via_api(string $to, string $subject, string $html, string $text, string $fromEmail, string $fromName): bool
{
    $provider = strtolower((string) mail_env(['MAIL_API_PROVIDER'], 'resend'));
    $apiKey   = (string) mail_env(['MAIL_API_KEY', 'RESEND_API_KEY'], '');

    if ($apiKey === '') {
        error_log('[mailer] API transport selected but no API key is set');
        return false;
    }

    if ($provider !== 'resend') {
        error_log('[mailer] Unsupported API provider: ' . $provider);
        return false;
    }

    return mail_via_resend($apiKey, $to, $subject, $html, $text, $fromEmail, $fromName);
}

function mail_via_resend(string $apiKey, string $to, string $subject, string $html, string $text, string $fromEmail, string $fromName): bool
{
    $fromHeader = ($fromName !== '') ? ($fromName . ' <' . $fromEmail . '>') : $fromEmail;

    $payload = [
        'from'    => $fromHeader,
        'to'      => [$to],
        'subject' => $subject,
        'html'    => $html,
        'text'    => $text,
    ];

    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);

    $raw    = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err    = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        error_log('[mailer][resend] curl error: ' . $err);
        return false;
    }

    if ($status < 200 || $status >= 300) {
        error_log('[mailer][resend] HTTP ' . $status . ' — ' . (string) $raw);
        return false;
    }

    return true;
}

// =====================================================================
// Transport 2 — SMTP
// =====================================================================

function mail_via_smtp(string $to, string $subject, string $html, string $text, string $fromEmail, string $fromName): bool
{
    $host = (string) mail_setting('smtp_host', ['MAIL_HOST', 'SMTP_HOST'], '');
    $port = (int)    mail_setting('smtp_port', ['MAIL_PORT', 'SMTP_PORT'], '587');
    $user = (string) mail_setting('smtp_username', ['MAIL_USER', 'SMTP_USER'], '');
    $pass = (string) mail_setting('smtp_password', ['MAIL_PASS', 'SMTP_PASS', 'MAIL_PASSWORD'], '');

    if ($host === '') {
        error_log('[mailer][smtp] no host configured');
        return false;
    }

    $enc = strtolower((string) mail_setting('smtp_encryption', ['MAIL_ENCRYPTION', 'SMTP_ENCRYPTION'], ''));
    if ($enc === '') {
        // Infer from port when not specified
        if ($port === 465) {
            $enc = 'ssl';
        } elseif ($port === 587 || $port === 2587) {
            $enc = 'tls';
        } else {
            $enc = 'none';
        }
    }

    $transport = ($enc === 'ssl') ? 'ssl://' : '';
    $ctx = stream_context_create([
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);

    $fp = @stream_socket_client(
        $transport . $host . ':' . $port,
        $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx
    );
    if (!$fp) {
        error_log("[mailer][smtp] connect failed to {$host}:{$port}: {$errno} {$errstr}");
        return false;
    }
    stream_set_timeout($fp, 15);

    $read = function () use ($fp): string {
        $data = '';
        while (($line = fgets($fp, 515)) !== false) {
            $data .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        return $data;
    };
    $write = function (string $cmd) use ($fp): void {
        fwrite($fp, $cmd . "\r\n");
    };
    $code = function (string $resp): int {
        return (int) substr($resp, 0, 3);
    };

    $greeting = $read();
    if ($code($greeting) !== 220) { fclose($fp); error_log('[mailer][smtp] bad greeting: ' . trim($greeting)); return false; }

    $ehloHost = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $write('EHLO ' . $ehloHost);
    $ehlo = $read();
    if ($code($ehlo) !== 250) { fclose($fp); error_log('[mailer][smtp] EHLO rejected'); return false; }

    if ($enc === 'tls') {
        $write('STARTTLS');
        $r = $read();
        if ($code($r) !== 220) { fclose($fp); error_log('[mailer][smtp] STARTTLS rejected: ' . trim($r)); return false; }
        if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($fp);
            error_log('[mailer][smtp] TLS handshake failed');
            return false;
        }
        $write('EHLO ' . $ehloHost);
        $read();
    }

    if ($user !== '') {
        $write('AUTH LOGIN');
        if ($code($read()) !== 334) { fclose($fp); error_log('[mailer][smtp] AUTH LOGIN not accepted'); return false; }
        $write(base64_encode($user));
        if ($code($read()) !== 334) { fclose($fp); error_log('[mailer][smtp] username not accepted'); return false; }
        $write(base64_encode($pass));
        $authResp = $read();
        if ($code($authResp) !== 235) {
            fclose($fp);
            error_log('[mailer][smtp] auth failed: ' . trim($authResp));
            return false;
        }
    }

    $write('MAIL FROM:<' . $fromEmail . '>');
    if ($code($read()) !== 250) { fclose($fp); error_log('[mailer][smtp] MAIL FROM rejected'); return false; }

    $write('RCPT TO:<' . $to . '>');
    $rcpt = $read();
    if (!in_array($code($rcpt), [250, 251], true)) {
        fclose($fp);
        error_log('[mailer][smtp] RCPT TO rejected: ' . trim($rcpt));
        return false;
    }

    $write('DATA');
    if ($code($read()) !== 354) { fclose($fp); error_log('[mailer][smtp] DATA not accepted'); return false; }

    $boundary = '=_pict_' . bin2hex(random_bytes(8));
    $headers  = [];
    $headers[] = 'From: ' . mail_encode_name($fromName) . ' <' . $fromEmail . '>';
    $headers[] = 'To: <' . $to . '>';
    $headers[] = 'Subject: ' . mail_encode_subject($subject);
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
    $headers[] = 'Date: ' . date('r');

    $body  = implode("\r\n", $headers) . "\r\n\r\n";
    $body .= "--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n" . $text . "\r\n\r\n";
    $body .= "--{$boundary}\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n" . $html . "\r\n\r\n";
    $body .= "--{$boundary}--";
    $body  = preg_replace('/^\./m', '..', $body);

    fwrite($fp, $body . "\r\n.\r\n");
    if ($code($read()) !== 250) { fclose($fp); error_log('[mailer][smtp] message rejected after DATA'); return false; }

    $write('QUIT');
    fclose($fp);
    return true;
}

// =====================================================================
// Transport 3 — PHP mail()
// =====================================================================

function mail_via_php(string $to, string $subject, string $html, string $text, string $fromEmail, string $fromName): bool
{
    $boundary = '=_pict_' . bin2hex(random_bytes(8));
    $headers  = [];
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'From: ' . mail_encode_name($fromName) . ' <' . $fromEmail . '>';
    $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';

    $body  = "--{$boundary}\r\n";
    $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $body .= $text . "\r\n\r\n";
    $body .= "--{$boundary}\r\n";
    $body .= "Content-Type: text/html; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $body .= $html . "\r\n\r\n";
    $body .= "--{$boundary}--";

    return @mail($to, mail_encode_subject($subject), $body, implode("\r\n", $headers));
}

// =====================================================================
// Helpers
// =====================================================================

function mail_encode_name(string $name): string
{
    if (preg_match('/[^\x20-\x7E]/', $name)) {
        return '=?UTF-8?B?' . base64_encode($name) . '?=';
    }
    return '"' . str_replace('"', '\\"', $name) . '"';
}

function mail_encode_subject(string $subject): string
{
    if (preg_match('/[^\x20-\x7E]/', $subject)) {
        return '=?UTF-8?B?' . base64_encode($subject) . '?=';
    }
    return $subject;
}

// =====================================================================
// Branded HTML wrapper (unchanged public signature)
// =====================================================================

function send_branded_mail(string $to, string $subject, string $introHtml, ?string $ctaLabel = null, ?string $ctaUrl = null): bool
{
    $companyName = function_exists('setting')
        ? (string) setting('company_name', 'Prestigious ICT Investment Limited')
        : 'Prestigious ICT Investment Limited';

    $brand = htmlspecialchars($companyName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $intro = $introHtml;

    $button = '';
    if ($ctaLabel !== null && $ctaUrl !== null) {
        $label = htmlspecialchars($ctaLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $href  = htmlspecialchars($ctaUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $button = '<p style="margin:28px 0"><a href="' . $href
                . '" style="background:#0b5ed7;color:#fff;text-decoration:none;'
                . 'padding:12px 22px;border-radius:6px;display:inline-block;font-weight:600">'
                . $label . '</a></p>';
    }

    $year = date('Y');
    $html = '<!doctype html><html><body style="margin:0;background:#f3f4f6;'
          . 'font-family:Arial,Helvetica,sans-serif;color:#1f2937">'
          . '<div style="max-width:600px;margin:32px auto;background:#fff;border-radius:10px;'
          . 'overflow:hidden;box-shadow:0 2px 10px rgba(0,0,0,.05)">'
          . '<div style="background:#0b5ed7;color:#fff;padding:18px 24px;font-size:18px;'
          . 'font-weight:600">' . $brand . '</div>'
          . '<div style="padding:24px;line-height:1.6;font-size:15px">' . $intro . $button
          . '<p style="color:#6b7280;font-size:13px;margin-top:32px">'
          . 'If you did not request this email, you can safely ignore it.</p>'
          . '</div>'
          . '<div style="background:#f9fafb;color:#6b7280;padding:14px 24px;font-size:12px;'
          . 'text-align:center">&copy; ' . $year . ' ' . $brand . '</div>'
          . '</div></body></html>';

    return send_mail($to, $subject, $html);
}
