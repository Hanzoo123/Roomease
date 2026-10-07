<?php
/**
 * CSRF tokens for forms. Add csrf_field() to every POST form, verify_csrf() in its handler.
 *
 * Used by: every page with a POST form in auth/, admin/, landlord/ and
 * boarder/, and the form components in includes/components/.
 */

/** This session's token, made on first use. */

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
 * True when the form sent this session's token. An empty token never matches.
 * Pages that answer with JSON call this first, so they can reply in JSON.
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
