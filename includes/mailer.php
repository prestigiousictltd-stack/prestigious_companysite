<?php
/**
 * Minimal mail transport.
 * Uses PHP mail() when SMTP is disabled; otherwise uses a compact SMTP client.
 * No external dependencies.
 */

declare(strict_types=1);

function send_mail(string $to, string $subject, string $htmlBody, ?string $textBody = null): bool
{
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    $fromEmail = (string) setting('smtp_from_email', 'no-reply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $fromName  = (string) setting('smtp_from_name', (string) setting('company_name', 'Prestigious ICT'));
    $textBody  = $textBody ?? strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $htmlBody));

    if (!setting_bool('smtp_enabled', false)) {
        return mail_via_php($to, $subject, $htmlBody, $textBody, $fromEmail, $fromName);
    }
    return mail_via_smtp($to, $subject, $htmlBody, $textBody, $fromEmail, $fromName);
}

function mail_via_php(string $to, string $subject, string $html, string $text, string $fromEmail, string $fromName): bool
{
    $boundary = '=_pict_' . bin2hex(random_bytes(8));
    $headers  = [];
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'From: ' . mail_encode_name($fromName) . ' <' . $fromEmail . '>';
    $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';

    $body  = "--$boundary\r\n";
    $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $body .= $text . "\r\n\r\n";
    $body .= "--$boundary\r\n";
    $body .= "Content-Type: text/html; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $body .= $html . "\r\n\r\n";
    $body .= "--$boundary--";

    return @mail($to, mail_encode_subject($subject), $body, implode("\r\n", $headers));
}

function mail_via_smtp(string $to, string $subject, string $html, string $text, string $fromEmail, string $fromName): bool
{
    $host = (string) setting('smtp_host', '');
    $port = (int) setting('smtp_port', 587);
    $user = (string) setting('smtp_username', '');
    $pass = (string) setting('smtp_password', '');
    $enc  = strtolower((string) setting('smtp_encryption', 'tls'));

    if ($host === '') {
        return false;
    }

    $transport = $enc === 'ssl' ? 'ssl://' : '';
    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
    $fp = @stream_socket_client($transport . $host . ':' . $port, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) {
        error_log("[mailer] SMTP connect failed: $errno $errstr");
        return false;
    }
    stream_set_timeout($fp, 15);

    $read = function () use ($fp): string {
        $data = '';
        while (($line = fgets($fp, 515)) !== false) {
            $data .= $line;
            if (isset($line[3]) && $line[3] === ' ') break;
        }
        return $data;
    };
    $write = function (string $cmd) use ($fp): void {
        fwrite($fp, $cmd . "\r\n");
    };
    $code = function (string $resp): int { return (int) substr($resp, 0, 3); };

    $greeting = $read();
    if ($code($greeting) !== 220) { fclose($fp); return false; }

    $ehloHost = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $write('EHLO ' . $ehloHost);
    $ehlo = $read();
    if ($code($ehlo) !== 250) { fclose($fp); return false; }

    if ($enc === 'tls') {
        $write('STARTTLS');
        $r = $read();
        if ($code($r) !== 220) { fclose($fp); return false; }
        if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($fp); return false;
        }
        $write('EHLO ' . $ehloHost);
        $read();
    }

    if ($user !== '') {
        $write('AUTH LOGIN');
        if ($code($read()) !== 334) { fclose($fp); return false; }
        $write(base64_encode($user));
        if ($code($read()) !== 334) { fclose($fp); return false; }
        $write(base64_encode($pass));
        if ($code($read()) !== 235) { fclose($fp); return false; }
    }

    $write('MAIL FROM:<' . $fromEmail . '>');
    if ($code($read()) !== 250) { fclose($fp); return false; }
    $write('RCPT TO:<' . $to . '>');
    $rcpt = $read();
    if (!in_array($code($rcpt), [250, 251], true)) { fclose($fp); return false; }
    $write('DATA');
    if ($code($read()) !== 354) { fclose($fp); return false; }

    $boundary = '=_pict_' . bin2hex(random_bytes(8));
    $headers  = [];
    $headers[] = 'From: ' . mail_encode_name($fromName) . ' <' . $fromEmail . '>';
    $headers[] = 'To: <' . $to . '>';
    $headers[] = 'Subject: ' . mail_encode_subject($subject);
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
    $headers[] = 'Date: ' . date('r');

    $body  = implode("\r\n", $headers) . "\r\n\r\n";
    $body .= "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n" . $text . "\r\n\r\n";
    $body .= "--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n" . $html . "\r\n\r\n";
    $body .= "--$boundary--";

    // Dot-stuffing
    $body = preg_replace('/^\./m', '..', $body);

    fwrite($fp, $body . "\r\n.\r\n");
    if ($code($read()) !== 250) { fclose($fp); return false; }
    $write('QUIT');
    fclose($fp);
    return true;
}

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

/** Convenience wrapper with branded HTML shell. */
function send_branded_mail(string $to, string $subject, string $introHtml, ?string $ctaLabel = null, ?string $ctaUrl = null): bool
{
    $companyName = (string) setting('company_name', 'Prestigious ICT Investment Limited');
    $brand = e($companyName);
    $intro = $introHtml;
    $button = '';
    if ($ctaLabel !== null && $ctaUrl !== null) {
        $label = e($ctaLabel);
        $href  = e($ctaUrl);
        $button = <<<HTML
        <p style="margin:28px 0">
          <a href="$href" style="background:#0b5ed7;color:#fff;text-decoration:none;padding:12px 22px;border-radius:6px;display:inline-block;font-weight:600">$label</a>
        </p>
        HTML;
    }
    $html = <<<HTML
<!doctype html><html><body style="margin:0;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif;color:#1f2937">
  <div style="max-width:600px;margin:32px auto;background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 2px 10px rgba(0,0,0,.05)">
    <div style="background:#0b5ed7;color:#fff;padding:18px 24px;font-size:18px;font-weight:600">$brand</div>
    <div style="padding:24px;line-height:1.6;font-size:15px">
      $intro
      $button
      <p style="color:#6b7280;font-size:13px;margin-top:32px">If you did not request this email, you can safely ignore it.</p>
    </div>
    <div style="background:#f9fafb;color:#6b7280;padding:14px 24px;font-size:12px;text-align:center">&copy; " . date('Y') . " $brand</div>
  </div>
</body></html>
HTML;
    return send_mail($to, $subject, $html);
}