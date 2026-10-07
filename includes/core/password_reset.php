<?php
/**
 * Password reset by emailed code:
 * 1. auth/forgot_password.php emails a 6-digit code.
 * 2. auth/verify_code.php checks it and gives the session a one-time token.
 * 3. auth/reset_password.php uses the token to save the new password.
 *
 * $scope is 'public', 'admin', or 'profile' (a signed-in Google account
 * setting its first password).
 *
 * Used by: the three pages above, auth/change_password.php, and
 * legal/privacy.php (how long a code lasts).
 */

/** Wrong guesses allowed per code. */

const RESET_CODE_MAX_TRIES = 5;

/** Seconds to wait before asking for another code. */

const RESET_CODE_RESEND_SECONDS = 60;

/** Minutes a code is valid, and then minutes to choose the new password. */

function password_reset_ttl_minutes()
{
    return 10;
}

/** True when the request came from this machine. */

function is_local_request()
{
    return in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
}

/**
 * Development only: show a code on screen when the email failed. Needs
 * ROOMEASE_SHOW_RESET_CODES=true in .env AND a request from this computer.
 * (Being local alone isn't enough: through ngrok, every visitor looks local.)
 */

function show_reset_codes_on_screen()
{
    $setting = env_value('ROOMEASE_SHOW_RESET_CODES');
    return is_local_request() && filter_var($setting, FILTER_VALIDATE_BOOLEAN);
}

/**
 * The account to reset, or null. Admins only reset from the admin page, others
 * from the public page. Deactivated and removed accounts get nothing.
 */

function password_reset_account($email, $scope)
{
    global $pdo;

    if ($scope === 'profile') {
        if (!is_logged_in()) {
            return null;
        }
        $stmt = $pdo->prepare(
            'SELECT user_id, email, first_name, role FROM users
              WHERE user_id = ? AND deleted_at IS NULL AND is_active = 1'
        );
        $stmt->execute([$_SESSION['user_id']]);
    } else {
        $roleCheck = $scope === 'admin' ? 'role IN ' . ADMIN_ROLES_SQL : 'role NOT IN ' . ADMIN_ROLES_SQL;
        $stmt = $pdo->prepare(
            "SELECT user_id, email, first_name, role FROM users
              WHERE email = ? AND deleted_at IS NULL AND is_active = 1 AND $roleCheck"
        );
        $stmt->execute([$email]);
    }

    return $stmt->fetch() ?: null;
}

/**
 * Email a new code and remember the attempt in the session.
 * Returns true (sent), false (failed) or null (no such account). Public pages
 * show the same message either way, so nobody learns which emails exist.
 */

function issue_password_reset_code($email, $scope)
{
    $account = password_reset_account($email, $scope);
    $sent = null;
    $localCode = null;

    if ($account) {
        $code = create_password_reset_code($account['user_id']);
        $sent = send_password_reset_code($account['email'], $account['first_name'], $code, $scope);
        if (!$sent && show_reset_codes_on_screen()) {
            $localCode = $code;
        }
    } elseif (mail_enabled()) {
        // Pause like a real send would, so the timing doesn't reveal the account exists.
        usleep(random_int(1000000, 2500000));
    }

    $_SESSION['password_reset'] = [
        // As typed, not as stored, for the same reason.
        'email'      => $scope === 'profile' && $account ? $account['email'] : $email,
        'scope'      => $scope,
        'user_id'    => $scope === 'profile' && $account ? (int) $account['user_id'] : null,
        'sent_at'    => time(),
        'tries'      => 0,
        'local_code' => $localCode,
        'token'      => null,
    ];

    return $sent;
}

/**
 * Make a new 6-digit code, store its hash, and return it. Older codes stop working.
 * Hashed with password_hash() (slow), since a million codes are quick to guess with a fast hash.
 */

function create_password_reset_code($userId)
{
    global $pdo;

    // Remove this user's old codes, and anyone's expired ones.
    $pdo->prepare('DELETE FROM password_resets WHERE user_id = ?')->execute([$userId]);
    $pdo->exec('DELETE FROM password_resets WHERE expires_at < NOW() - INTERVAL 1 DAY');

    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

    // token_hash gets a random placeholder until the code is entered correctly.
    $pdo->prepare(
        'INSERT INTO password_resets (user_id, token_hash, code_hash, expires_at)
         VALUES (?, ?, ?, NOW() + INTERVAL ? MINUTE)'
    )->execute([
        $userId,
        hash('sha256', random_bytes(32)),
        password_hash($code, PASSWORD_DEFAULT),
        password_reset_ttl_minutes(),
    ]);

    return $code;
}

/**
 * Check a code. Right: returns a one-time token (the code can't be used again).
 * Wrong: null. Each try is counted in the database first, so fast parallel
 * guesses can't get past the limit.
 */

function redeem_password_reset_code($userId, $code)
{
    global $pdo;

    $stmt = $pdo->prepare(
        'SELECT reset_id, code_hash FROM password_resets
          WHERE user_id = ? AND code_hash IS NOT NULL AND verified_at IS NULL
            AND used_at IS NULL AND expires_at > NOW()
          ORDER BY reset_id DESC LIMIT 1'
    );
    $stmt->execute([$userId]);
    $reset = $stmt->fetch();
    if (!$reset) {
        return null;
    }

    $claim = $pdo->prepare('UPDATE password_resets SET attempts = attempts + 1 WHERE reset_id = ? AND attempts < ?');
    $claim->execute([$reset['reset_id'], RESET_CODE_MAX_TRIES]);
    if ($claim->rowCount() !== 1 || !password_verify($code, $reset['code_hash'])) {
        return null;
    }

    $token = bin2hex(random_bytes(32));
    $swap = $pdo->prepare(
        'UPDATE password_resets
            SET code_hash = NULL, verified_at = NOW(), token_hash = ?,
                expires_at = NOW() + INTERVAL ? MINUTE
          WHERE reset_id = ? AND verified_at IS NULL'
    );
    $swap->execute([hash('sha256', $token), password_reset_ttl_minutes(), $reset['reset_id']]);

    return $swap->rowCount() === 1 ? $token : null;
}

/** The valid, unused reset for this token (looked up by its hash), with its user, or null. */

function find_valid_reset($token)
{
    global $pdo;

    if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT pr.reset_id, pr.user_id, pr.expires_at,
                u.email, u.first_name, u.is_active, u.role
           FROM password_resets pr
           JOIN users u ON u.user_id = pr.user_id
          WHERE pr.token_hash = ?
            AND pr.verified_at IS NOT NULL
            AND pr.used_at IS NULL
            AND pr.expires_at > NOW()'
    );
    $stmt->execute([hash('sha256', $token)]);

    return $stmt->fetch() ?: null;
}

/** The page a reset in $scope started on. */

function password_reset_start_path($scope)
{
    if ($scope === 'profile') {
        return 'auth/change_password.php';
    }
    return $scope === 'admin' ? 'admin/forgot_password.php' : 'auth/forgot_password.php';
}

/** Email a reset code. True if Gmail accepted it. The log never contains the code. */

function send_password_reset_code($email, $firstName, $code, $scope = 'public')
{
    $minutes = password_reset_ttl_minutes();
    $action = $scope === 'profile' ? 'set a password for' : 'reset the password for';

    $subject = 'Your RoomEase password code';
    $text = "Hi " . $firstName . ",\r\n\r\n"
        . "Use this code to " . $action . " your RoomEase account:\r\n\r\n"
        . "    " . $code . "\r\n\r\n"
        . "It expires in " . $minutes . " minutes. Never share it; RoomEase will not ask you for it.\r\n\r\n"
        . "If you didn't ask for this, ignore this email. Your password stays the same.\r\n\r\n"
        . "RoomEase\r\n";

    // Email apps ignore stylesheets, so styles are inline.
    $html = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"></head>'
        . '<body style="margin:0;padding:32px 16px;background:#FAF8F3;">'
        . '<div style="max-width:440px;margin:0 auto;font-family:\'IBM Plex Sans\',\'Segoe UI\',Helvetica,Arial,sans-serif;'
        . 'font-size:15px;line-height:1.6;color:#1F2A28;">'
        . '<p style="margin:0 0 28px;font-family:Georgia,\'Times New Roman\',serif;font-size:20px;color:#184A3F;">RoomEase</p>'
        . '<p style="margin:0 0 16px;">Hi ' . h($firstName) . ',</p>'
        . '<p style="margin:0 0 20px;">Use this code to ' . h($action) . ' your RoomEase account:</p>'
        . '<p style="margin:0 0 20px;font-family:Consolas,\'Courier New\',monospace;font-size:36px;font-weight:700;'
        . 'letter-spacing:10px;color:#184A3F;">' . h($code) . '</p>'
        . '<p style="margin:0 0 16px;">It expires in ' . $minutes . ' minutes. Never share it; RoomEase will not ask you for it.</p>'
        . '<p style="margin:0;color:#5C6B66;font-size:13.5px;">If you didn\'t ask for this, ignore this email. '
        . 'Your password stays the same.</p>'
        . '</div></body></html>';

    $sent = send_mail($email, $subject, $text, $html);

    $logDir = __DIR__ . '/../../storage';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    @file_put_contents(
        $logDir . '/password_resets.log',
        sprintf("[%s] reset code requested for %s - email: %s%s",
            date('Y-m-d H:i:s'), $email, $sent ? 'sent' : 'FAILED', PHP_EOL),
        FILE_APPEND
    );

    return $sent;
}
