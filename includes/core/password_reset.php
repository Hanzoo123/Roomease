<?php
/**
 * Password reset by emailed code: issuing, checking and redeeming codes.
 */

/* ---------------------------------------------------------------------------
 * Password reset by emailed code (database/roomease.sql)
 *
 * 1. auth/forgot_password.php takes an email address and emails a 6-digit
 *    code. The attempt is remembered in $_SESSION['password_reset'].
 * 2. auth/verify_code.php checks the code. A right code is swapped for a
 *    random token that lives only in that session.
 * 3. auth/reset_password.php finds the reset by that token and saves the new
 *    password.
 *
 * The profile page uses the same steps, so an account made with Google can set
 * its first password; there the scope is 'profile' instead of 'public' or
 * 'admin'. Codes go out through Gmail (includes/core/mailer.php).
 * ------------------------------------------------------------------------ */

/** Wrong guesses one code survives before it stops working. */

const RESET_CODE_MAX_TRIES = 5;

/** How long someone waits before asking for another code. */

const RESET_CODE_RESEND_SECONDS = 60;

/**
 * How long a reset code stays valid, and how long a right code then leaves to
 * choose the new password.
 */

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
 * True when a reset code that could not be emailed may be shown on screen
 * instead. A development convenience only: it needs ROOMEASE_SHOW_RESET_CODES
 * turned on in .env AND a request from this machine, and it is off otherwise.
 *
 * A request from this machine used to be enough on its own. It is not: share
 * the site through ngrok, Cloudflare Tunnel or any other proxy running on the
 * same computer and every visitor arrives from 127.0.0.1, so anyone could
 * have read the code for any account, an administrator's included.
 */

function show_reset_codes_on_screen()
{
    $setting = env_value('ROOMEASE_SHOW_RESET_CODES');
    return is_local_request() && filter_var($setting, FILTER_VALIDATE_BOOLEAN);
}

/**
 * The account a reset for $email may go to, or null. Administrators reset only
 * from the admin page and everyone else only from the public one; 'profile' is
 * always the signed-in user. Deactivated and archived accounts get nothing.
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
        $roleCheck = $scope === 'admin' ? "role = 'administrator'" : "role <> 'administrator'";
        $stmt = $pdo->prepare(
            "SELECT user_id, email, first_name, role FROM users
              WHERE email = ? AND deleted_at IS NULL AND is_active = 1 AND $roleCheck"
        );
        $stmt->execute([$email]);
    }

    return $stmt->fetch() ?: null;
}

/**
 * Email a fresh code for $email and remember the attempt in the session.
 *
 * Returns true if the email went out, false if it could not be sent, and null
 * if there is no such account. Only the profile page, where the account is the
 * visitor's own, may tell anyone which of those happened; the public pages say
 * the same thing every time.
 *
 * When sending fails and show_reset_codes_on_screen() allows it, the code is
 * kept in the session so the next page can show it. Nobody else ever sees it.
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
        // Talking to Gmail takes a second or two. Without a similar pause, how
        // fast the page answered would give away whether the account exists.
        usleep(random_int(1000000, 2500000));
    }

    $_SESSION['password_reset'] = [
        // The address as typed, not as stored: echoing back the stored
        // spelling would show that the account exists.
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
 * Store a new 6-digit code for a user and return it.
 *
 * The code is stored with password_hash(), not a plain SHA-256: there are
 * only a million codes, so a fast hash would fall to a guessing loop in
 * moments if the table ever leaked. Any earlier code for the same user is
 * dropped, so only the newest one works.
 */

function create_password_reset_code($userId)
{
    global $pdo;

    // Housekeeping: clear this user's outstanding codes plus anything stale.
    $pdo->prepare('DELETE FROM password_resets WHERE user_id = ?')->execute([$userId]);
    $pdo->exec('DELETE FROM password_resets WHERE expires_at < NOW() - INTERVAL 1 DAY');

    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

    // token_hash is set only once the code is proven. Until then it holds the
    // hash of random bytes nobody keeps, which no token can ever match.
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
 * Check a code. A right one is exchanged for a raw token, which is returned;
 * anything else returns null.
 *
 * A try is claimed before the code is compared, in one conditional UPDATE, so
 * guesses sent in parallel cannot squeeze past RESET_CODE_MAX_TRIES. A right
 * code is burned the moment it is used and a new token takes its place with a
 * fresh expiry, so the code cannot be used twice.
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

/**
 * Look up a proven, unused, unexpired reset by its token. Returns the reset row
 * joined to its user, or null. The lookup is by hash, so the raw token is never
 * compared against stored data.
 */

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

/**
 * Where "get a new code" leads for a reset in $scope: the page it started on.
 */

function password_reset_start_path($scope)
{
    if ($scope === 'profile') {
        return 'auth/change_password.php';
    }
    return $scope === 'admin' ? 'admin/forgot_password.php' : 'auth/forgot_password.php';
}

/**
 * Email a reset code through Gmail. Returns true only if Gmail accepted it.
 *
 * A record of the attempt is logged, deliberately WITHOUT the code, so the log
 * itself can never be used to take over an account.
 */

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

    // Mail apps ignore stylesheets and web fonts, so everything is inline and
    // every font has a system fallback. The code is the only thing that stands out.
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
