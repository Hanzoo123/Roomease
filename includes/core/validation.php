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
 * Words that make a password easy to guess however it is dressed up: with
 * capitals, digits or symbols around it, or letters swapped for look-alikes
 * (P@ssw0rd123! is still "password").
 */
const COMMON_PASSWORD_WORDS = [
    'password', 'passw', 'pass', 'qwerty', 'qwertyuiop', 'asdf', 'asdfgh', 'zxcvbn', 'abc', 'abcd', 'abcde',
    'abcdef', 'admin', 'administrator', 'welcome', 'letmein', 'iloveyou', 'loveyou', 'love', 'monkey', 'dragon',
    'sunshine', 'princess', 'football', 'baseball', 'basketball', 'master', 'login', 'user', 'test', 'secret',
    'hello', 'freedom', 'student', 'boarder', 'landlord', 'roomease', 'baybay', 'leyte', 'philippines', 'pilipinas',
];

/**
 * 8 to 72 characters with an uppercase letter, a lowercase letter, a number
 * and a symbol; not a common password; and not containing the person's own
 * name or email ($personal: their names, email or username, where the form
 * has them). At most 72 bytes because password_hash() reads only the first
 * 72, so anything typed after them would be silently ignored.
 * includes/scripts/password_checklist.php shows the first five rules as the
 * person types.
 */

function password_problem($password, $label = 'Password', array $personal = [])
{
    $password = (string) $password;
    if (strlen($password) < 8) {
        return $label . ' must be at least 8 characters.';
    }
    if (strlen($password) > 72) {
        return $label . ' must be 72 characters or fewer.';
    }

    $missing = [];
    if (!preg_match('/\p{Lu}/u', $password)) {
        $missing[] = 'an uppercase letter';
    }
    if (!preg_match('/\p{Ll}/u', $password)) {
        $missing[] = 'a lowercase letter';
    }
    if (!preg_match('/\d/', $password)) {
        $missing[] = 'a number';
    }
    if (!preg_match('/[^\p{L}\p{N}]/u', $password)) {
        $missing[] = 'a symbol, such as ! @ # or ?';
    }
    if ($missing) {
        $last = array_pop($missing);
        return $label . ' needs ' . ($missing ? implode(', ', $missing) . ' and ' : '') . $last . '.';
    }

    // The word underneath: digits and symbols trimmed off both ends, look-alikes
    // turned back into letters, anything else that is not a letter dropped.
    $word = trim(strtolower($password), '0123456789!@#$%^&*()_+-=[]{};:\'",.<>/?\\|`~ ');
    $word = preg_replace('/[^a-z]/', '', strtr($word, ['@' => 'a', '4' => 'a', '0' => 'o', '3' => 'e', '1' => 'i', '$' => 's', '5' => 's', '7' => 't']));
    if (in_array($word, COMMON_PASSWORD_WORDS, true)) {
        return $label . ' is too common. Avoid words like "password" or "qwerty", even with numbers and symbols added.';
    }

    // The person's names, and the parts of their email before the @.
    $lower = strtolower($password);
    foreach ($personal as $value) {
        $value = strtolower(trim((string) $value));
        $parts = preg_split('/[^a-z]+/', strstr($value, '@', true) ?: $value, -1, PREG_SPLIT_NO_EMPTY);
        foreach ($parts as $part) {
            if (strlen($part) >= 4 && strpos($lower, $part) !== false) {
                return $label . ' must not contain your name or email.';
            }
        }
    }

    return null;
}
