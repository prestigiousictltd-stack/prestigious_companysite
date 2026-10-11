<?php
declare(strict_types=1);

/**
 * Stream a partner's profile photo.
 * Uses the path stored in partners.profile_image_path.
 * Only sends the file if it exists and is a valid image MIME type.
 * Never exposes the disk path to the client.
 */
function stream_partner_photo(int $partnerId): void
{
    $stmt = db()->prepare("SELECT profile_image_path FROM partners WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $partnerId]);
    $path = (string) ($stmt->fetchColumn() ?: '');

    if ($path === '') {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'No photo on file.';
        return;
    }

    // Only allow paths inside uploads/profiles/
    $rel = ltrim($path, '/');
    if (strpos($rel, 'uploads/profiles/') !== 0) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Forbidden.';
        return;
    }

    $fullPath = PICT_ROOT . '/' . $rel;
    if (!is_file($fullPath)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Photo file missing.';
        return;
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = (string) $finfo->file($fullPath);
    $allowedMimes = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];
    if (!isset($allowedMimes[$mime])) {
        http_response_code(415);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Unsupported image type.';
        return;
    }

    if (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string) filesize($fullPath));
    header('Cache-Control: private, max-age=3600');
    header('X-Content-Type-Options: nosniff');
    readfile($fullPath);
    exit;
}