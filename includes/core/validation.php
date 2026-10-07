<?php
/**
 * Checks for typed values, shared by every form. Each returns an error
 * message, or null when the value is fine. Length limits match the columns.
 *
 * Used by: listings.php and rooms.php (listing and room checks), the
 * account forms in auth/ and admin/, and legal/contact.php.
 */

/** "First name must be 100 characters or fewer.", or null. */

function too_long($value, $max, $label)
{
    return mb_strlen((string) $value) > $max
        ? $label . ' must be ' . number_format($max) . ' characters or fewer.'
        : null;
}

/** A Philippine mobile number: 11 digits starting with 09, spaces and dashes allowed, e.g. 0917 123 4567. */

function phone_problem($phone, $label = 'Phone number')
{
    $phone = (string) $phone;
    if (!preg_match('/^[0-9\-\s]+$/', $phone)) {
        return $label . ' can only use digits, spaces and dashes, like 0917 123 4567.';
    }
    if (!preg_match('/^09\d{9}$/', preg_replace('/\D/', '', $phone))) {
        return $label . ' must be an 11-digit mobile number starting with 09, like 0917 123 4567.';
    }
    // The column holds 30 characters.
    return too_long($phone, 30, $label);
}

/** A peso amount from 0 to 1,000,000, the same cap as a room's rent. */

function money_problem($value, $label)
{
    if (!is_numeric($value) || (float) $value < 0 || (float) $value > 1000000) {
        return $label . ' must be an amount from 0 to 1,000,000.';
    }
    return null;
}

/**
 * At least 8 characters, and at most 72 bytes: password_hash() reads only the
 * first 72 bytes, so anything typed after them would be silently ignored.
 */

function password_problem($password, $label = 'Password')
{
    $password = (string) $password;
    if (strlen($password) < 8) {
        return $label . ' must be at least 8 characters.';
    }
    if (strlen($password) > 72) {
        return $label . ' must be 72 characters or fewer.';
    }
    return null;
}
