<?php
/**
 * Cross-Site Request Forgery protection.
 * A single per-session token is used across all forms in the current
 * session. Tokens are compared with hash_equals to prevent timing leaks.
 */

declare(strict_types=1);

function csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_verify(?string $token): bool
{
    if (!is_string($token) || $token === '') {
        return false;
    }
    $expected = $_SESSION['_csrf'] ?? '';
    if ($expected === '') {
        return false;
    }
    return hash_equals($expected, $token);
}

function csrf_from_request(): ?string
{
    if (!empty($_POST['_csrf'])) {
        return (string) $_POST['_csrf'];
    }
    if (!empty($_SERVER['HTTP_X_CSRF_TOKEN'])) {
        return (string) $_SERVER['HTTP_X_CSRF_TOKEN'];
    }
    return null;
}

/** Abort the request with 419 if the CSRF token is invalid. */
function csrf_require(): void
{
    if (!csrf_verify(csrf_from_request())) {
        http_response_code(419);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'CSRF token invalid or expired. Please reload the page and try again.';
        exit;
    }
}

/** Rotate the CSRF token (call after login / privilege change). */
function csrf_rotate(): void
{
    $_SESSION['_csrf'] = bin2hex(random_bytes(32));
}