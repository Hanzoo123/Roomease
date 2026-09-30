<?php
/**
 * CSRF tokens for forms. Add csrf_field() to every POST form, verify_csrf() in its handler.
 */

/** Simple CSRF token helpers. */

function csrf_token()
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field()
{
    return '<input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '">';
}

/**
 * True when the form sent the token this session was given. An empty token on
 * either side is never a match: a session that has not shown a form since
 * signing in has no token yet, and '' equal to '' would let a form with no
 * token through. The pages that answer fetch() with JSON check this before
 * verify_csrf(), so they can reply in JSON.
 */

function csrf_ok()
{
    $expected = $_SESSION['csrf_token'] ?? '';
    $sent = $_POST['csrf_token'] ?? '';
    return is_string($expected) && is_string($sent) && $expected !== '' && hash_equals($expected, $sent);
}

function verify_csrf()
{
    if (!csrf_ok()) {
        http_response_code(403);
        die('Invalid or expired form submission. Please go back and try again.');
    }
}
