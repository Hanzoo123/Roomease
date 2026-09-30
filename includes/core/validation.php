<?php
/**
 * Checks for typed values. Each returns an error message, or null when the value is fine.
 */

/* ---------------------------------------------------------------------------
 * Checking typed values
 *
 * One rule and one message per kind of value, shared by every form, so sign
 * up, the profile and the listing form cannot drift apart. Each returns the
 * message to show, or null when the value is fine. A length limit is the
 * column's size in database/roomease.sql, so nothing is cut off on save.
 * ------------------------------------------------------------------------ */

/** "First name must be 100 characters or fewer.", or null. */

function too_long($value, $max, $label)
{
    return mb_strlen((string) $value) > $max
        ? $label . ' must be ' . number_format($max) . ' characters or fewer.'
        : null;
}

/**
 * A phone number anyone could dial: digits, spaces and + - ( ), with 7 to 15
 * digits. That takes 0917 123 4567, +63 917 123 4567 and (053) 335-1234 alike.
 */

function phone_problem($phone, $label = 'Phone number')
{
    $phone = (string) $phone;
    if (!preg_match('/^[0-9+()\-\s]+$/', $phone)) {
        return $label . ' can only use digits, spaces and + - ( ), like 0917 123 4567.';
    }
    $digits = strlen(preg_replace('/\D/', '', $phone));
    if ($digits < 7 || $digits > 15) {
        return $label . ' must have 7 to 15 digits.';
    }
    // Only reachable with a great deal of spacing; users.phone_number is 30.
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
