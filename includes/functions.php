<?php
/**
 * Shared helper functions used across RoomEase.
 */

// Session cookie flags can only be chosen before the session exists, so the
// hardening file is loaded and applied first. It also sends the response
// security headers, and defines the login/reset throttle helpers.
require_once __DIR__ . "/security.php";

configure_session_security();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

send_security_headers();

/** Escape output for safe HTML display. */
function h($value)
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

/** Redirect to a given path relative to the app root and stop execution. */
function redirect($path)
{
    header('Location: ' . base_url($path));
    exit;
}

/** Build a URL relative to the app's base path, so it works in any subfolder. */
function base_url($path = '')
{
    static $base = null;
    if ($base === null) {
        // includes/functions.php is one level deep, so the app root is one up
        // from wherever this is required from; we instead compute from SCRIPT_NAME's
        // known app-root marker (the folder that contains index.php).
        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        $appRoot = preg_replace('#/(auth|landlord|boarder|admin)/[^/]*$#', '', $script);
        if ($appRoot === $script) {
            $appRoot = rtrim(dirname($script), '/');
        }
        $base = $appRoot;
    }
    return $base . '/' . ltrim($path, '/');
}

/** True if a user is currently logged in. */
function is_logged_in()
{
    return isset($_SESSION['user_id']);
}

/** Get the logged-in user's role, or null. */
function current_role()
{
    return $_SESSION['role'] ?? null;
}

/** Force login; optionally restrict to specific roles. Redirects otherwise. */
function require_login($roles = null)
{
    if (!is_logged_in()) {
        redirect('auth/login.php');
    }
    if ($roles !== null) {
        $roles = (array) $roles;
        // Support 'admin' alias for 'administrator'
        if (in_array('admin', $roles, true) && !in_array('administrator', $roles, true)) {
            $roles[] = 'administrator';
        }
        if (in_array('administrator', $roles, true) && !in_array('admin', $roles, true)) {
            $roles[] = 'admin';
        }
        if (!in_array(current_role(), $roles, true)) {
            redirect('index.php');
        }
    }
}

/** Check if current user is an administrator. */
function is_admin()
{
    return in_array(current_role(), ['administrator', 'admin'], true);
}

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

function verify_csrf()
{
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(403);
        die('Invalid or expired form submission. Please go back and try again.');
    }
}

/** Flash message helpers (one-time messages shown after redirect). */
function flash_set($message, $type = 'success')
{
    $_SESSION['flash'] = ['message' => $message, 'type' => $type];
}

function flash_get()
{
    if (empty($_SESSION['flash'])) {
        return null;
    }
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
}

/**
 * A client-supplied filename, reduced to something safe to show back.
 *
 * These names end up inside error messages, and those messages are rendered as
 * a flash notification, so the browser must never be handed the raw string.
 */
function safe_filename($name)
{
    $name = basename((string) $name);
    $name = preg_replace('/[^A-Za-z0-9._ -]/', '', $name);
    $name = trim($name);
    if ($name === '') {
        return 'that file';
    }
    return mb_strimwidth($name, 0, 60, '...');
}

/** Convert a php.ini shorthand size such as "2M" into bytes. */
function ini_bytes($value)
{
    $value = trim((string) $value);
    if ($value === '') {
        return 0;
    }
    $number = (int) $value;
    switch (strtolower(substr($value, -1))) {
        case 'g':
            return $number * 1024 * 1024 * 1024;
        case 'm':
            return $number * 1024 * 1024;
        case 'k':
            return $number * 1024;
        default:
            return $number;
    }
}

/** Human-readable byte size, e.g. "2 MB". */
function format_bytes($bytes)
{
    if ($bytes >= 1024 * 1024) {
        return rtrim(rtrim(number_format($bytes / (1024 * 1024), 1), '0'), '.') . ' MB';
    }
    return max(1, (int) round($bytes / 1024)) . ' KB';
}

/**
 * Largest single photo we will accept: our own 5MB policy, but never more
 * than PHP itself is configured to take, so the limit shown on the form is
 * the limit actually enforced.
 */
function max_upload_bytes()
{
    static $bytes = null;
    if ($bytes === null) {
        $bytes = min(5 * 1024 * 1024, ini_bytes(ini_get('upload_max_filesize')));
    }
    return $bytes;
}

/** How many photos may be attached to one submission. */
function max_photos_per_upload()
{
    return 10;
}

/**
 * True when the browser sent more data than post_max_size allows. PHP then
 * discards $_POST and $_FILES entirely, which otherwise surfaces as a
 * confusing CSRF failure rather than "your photos were too large".
 */
function post_too_large()
{
    return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
        && empty($_POST)
        && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0;
}

/**
 * Handle one or more uploaded property images. Every file is validated
 * before any of them is moved, so one bad file in a batch cannot leave a
 * half-uploaded set behind. Accepts both the single-file and multi-file
 * shapes of $_FILES. Returns the stored relative paths, in submitted order.
 * Throws on validation failure.
 */
function handle_photo_uploads($fileField, $boardingHouseId)
{
    if (empty($_FILES[$fileField])) {
        return [];
    }

    $files = $_FILES[$fileField];
    $names = (array) $files['name'];
    $tmps  = (array) $files['tmp_name'];
    $errs  = (array) $files['error'];
    $sizes = (array) $files['size'];

    $allowed  = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $maxBytes = max_upload_bytes();
    $maxFiles = max_photos_per_upload();
    $queue    = [];

    foreach ($names as $i => $name) {
        $error = $errs[$i] ?? UPLOAD_ERR_NO_FILE;
        if ($name === '' || $error === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw new RuntimeException('"' . safe_filename($name) . '" is larger than the ' . format_bytes($maxBytes) . ' limit.');
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Upload failed for "' . safe_filename($name) . '". Please try again.');
        }
        if (count($queue) >= $maxFiles) {
            throw new RuntimeException('Please upload at most ' . $maxFiles . ' photos at a time.');
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $tmps[$i]);
        finfo_close($finfo);

        if (!isset($allowed[$mime])) {
            throw new RuntimeException('"' . safe_filename($name) . '" is not a JPG, PNG, or WEBP image.');
        }
        if ($sizes[$i] > $maxBytes) {
            throw new RuntimeException('"' . safe_filename($name) . '" is larger than the ' . format_bytes($maxBytes) . ' limit.');
        }

        $queue[] = ['tmp' => $tmps[$i], 'ext' => $allowed[$mime]];
    }

    if (!$queue) {
        return [];
    }

    $dir = __DIR__ . '/../assets/uploads/boarding_houses/' . $boardingHouseId;
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create the photo folder for this listing.');
    }

    $stored = [];
    foreach ($queue as $item) {
        $filename = uniqid('bh_', true) . '.' . $item['ext'];
        if (!move_uploaded_file($item['tmp'], $dir . '/' . $filename)) {
            // Keep the batch all-or-nothing: undo whatever already landed.
            foreach ($stored as $done) {
                @unlink(__DIR__ . '/../' . $done);
            }
            throw new RuntimeException('Could not save the uploaded photos.');
        }
        $stored[] = 'assets/uploads/boarding_houses/' . $boardingHouseId . '/' . $filename;
    }

    return $stored;
}

/** Format a peso amount for display. */
function peso($amount)
{
    if ($amount === null || $amount === '') {
        return '—';
    }
    return '₱' . number_format((float) $amount, 2);
}

/**
 * Peso with the centavos dropped when the amount is a whole number.
 *
 * Rents are whole pesos in practice, so a column of them all ending in ".00"
 * is two characters of noise on every row of the listing board. peso() keeps
 * the exact figure for anywhere a centavo could matter; this is for display.
 */
function peso_round($amount)
{
    if ($amount === null || $amount === '') {
        return '—';
    }
    $value = (float) $amount;
    $decimals = (abs($value - round($value)) < 0.005) ? 0 : 2;
    return '₱' . number_format($value, $decimals);
}

/**
 * Read a lookup table, or log why it could not be read and return nothing.
 *
 * These three lists used to fall back to a hard-coded copy of the seed data.
 * That made a missing table invisible: the form still rendered a full set of
 * checkboxes, the landlord ticked them, and the insert into the junction table
 * failed silently afterwards. An empty list is worse-looking but honest, and
 * the callers say so on the page.
 */
function lookup_options($sql, $mode = PDO::FETCH_COLUMN)
{
    global $pdo;
    try {
        return $pdo->query($sql)->fetchAll($mode);
    } catch (PDOException $e) {
        error_log('RoomEase: lookup query failed - ' . $e->getMessage());
        return [];
    }
}

/** Amenity checklist offered on the listing form. Empty if unreadable. */
function amenity_options()
{
    return lookup_options('SELECT amenity_name FROM amenities ORDER BY amenity_id ASC');
}

/** Utility checklist offered on the listing form. Empty if unreadable. */
function utility_options()
{
    return lookup_options(
        'SELECT utility_id, utility_name FROM utilities ORDER BY utility_id ASC',
        PDO::FETCH_ASSOC
    );
}

/**
 * Room types offered on the listing form and in the browse filter, as
 * room_type_id => room_type_name.
 *
 * Both read from here so the two lists cannot drift apart, and since
 * boarding_houses.room_type_id is a foreign key onto this table, a value
 * that is not in this list can no longer be stored at all.
 */
function room_type_options()
{
    $rows = lookup_options(
        'SELECT room_type_id, room_type_name FROM room_types ORDER BY room_type_id ASC',
        PDO::FETCH_ASSOC
    );

    $options = [];
    foreach ($rows as $row) {
        $options[(int) $row['room_type_id']] = $row['room_type_name'];
    }
    return $options;
}

/**
 * The SELECT fragment and JOIN that expose a listing's room type name under
 * the key every page already reads, so display code did not have to change
 * when the column became a foreign key.
 */
const ROOM_TYPE_SELECT = 'rt.room_type_name AS room_type';
const ROOM_TYPE_JOIN   = 'LEFT JOIN room_types rt ON rt.room_type_id = bh.room_type_id';

/**
 * The JOIN that hides listings whose landlord is deactivated or archived.
 *
 * Browse never used to look at the landlord's account at all, so a
 * deactivated landlord's listings stayed on the public site. Soft deletion
 * would have inherited exactly the same hole.
 */
const LIVE_LANDLORD_JOIN =
    'JOIN users lu ON lu.user_id = bh.landlord_id AND lu.is_active = 1 AND lu.deleted_at IS NULL';

/** What else a listing needs to be on the public site. Pair with LIVE_LANDLORD_JOIN. */
const LIVE_LISTING_WHERE = "bh.availability_status = 'available' AND bh.moderation_status = 'approved'";

/**
 * How many listings the public site is showing right now. The home page quotes
 * this, so it has to be the real figure browse would return, never a rounded
 * or padded one.
 */
function live_listing_count()
{
    global $pdo;
    try {
        return (int) $pdo->query(
            'SELECT COUNT(*) FROM boarding_houses bh ' . LIVE_LANDLORD_JOIN . ' WHERE ' . LIVE_LISTING_WHERE
        )->fetchColumn();
    } catch (PDOException $e) {
        error_log('RoomEase: live listing count failed - ' . $e->getMessage());
        return 0;
    }
}

/**
 * Every room type with its number of live listings, in the same order as the
 * browse filter. Types with no listings are kept, with a count of 0.
 */
function room_type_counts()
{
    return lookup_options(
        'SELECT rt.room_type_id, rt.room_type_name, COUNT(bh.boarding_house_id) AS listings
           FROM room_types rt
           LEFT JOIN (boarding_houses bh ' . LIVE_LANDLORD_JOIN . ')
                  ON bh.room_type_id = rt.room_type_id AND ' . LIVE_LISTING_WHERE . '
          GROUP BY rt.room_type_id, rt.room_type_name
          ORDER BY rt.room_type_id ASC',
        PDO::FETCH_ASSOC
    );
}

/* ---------------------------------------------------------------------------
 * Stay terms (database/migration_stay_terms.sql)
 *
 * What a boarder asks before visiting: curfew, deposit, minimum stay, how rent
 * is paid, who the house accepts, and whether visitors, pets and cooking are
 * allowed, plus the listing's map pin. Every one is optional, and NULL means
 * the landlord has not said, so the listing page leaves it out rather than
 * guessing.
 * ------------------------------------------------------------------------ */

/** The stay-term columns on boarding_houses, in the order the forms write them. */
const STAY_TERM_COLUMNS = [
    'curfew', 'security_deposit', 'minimum_stay_months', 'payment_methods', 'gender_policy',
    'visitors_allowed', 'pets_allowed', 'cooking_allowed', 'latitude', 'longitude',
];

/** Payment methods a landlord can tick, as stored value => label. */
function payment_method_options()
{
    return ['cash' => 'Cash', 'gcash' => 'GCash', 'maya' => 'Maya', 'bank_transfer' => 'Bank transfer'];
}

/** Who a listing accepts, as stored value => label. */
function gender_policy_options()
{
    return ['any' => 'All genders', 'female' => 'Female only', 'male' => 'Male only'];
}

/** "cash,gcash" as "Cash, GCash". Unknown values are dropped. */
function payment_methods_label($stored)
{
    $options = payment_method_options();
    $labels = [];
    foreach (explode(',', (string) $stored) as $value) {
        if (isset($options[$value])) {
            $labels[] = $options[$value];
        }
    }
    return implode(', ', $labels);
}

/**
 * Read and validate the stay-term fields from a submitted listing form.
 *
 * Returns [$values, $errors, $echo]:
 *   $values  exactly STAY_TERM_COLUMNS, normalised for the database: blanks
 *            become NULL, yes/no rules become 1, 0 or NULL, payment methods
 *            are filtered to the known list, and coordinates are kept only as
 *            a valid pair.
 *   $errors  messages for the form.
 *   $echo    what to put back in the form if it is shown again, so a typo is
 *            corrected rather than silently cleared.
 */
function stay_terms_from_post(array $post)
{
    $errors = [];
    $values = array_fill_keys(STAY_TERM_COLUMNS, null);
    $text = function ($key) use ($post) {
        return is_string($post[$key] ?? null) ? trim($post[$key]) : '';
    };

    $curfew = $text('curfew');
    if (mb_strlen($curfew) > 60) {
        $errors[] = 'Curfew must be 60 characters or fewer.';
    } elseif ($curfew !== '') {
        $values['curfew'] = $curfew;
    }

    $deposit = $text('security_deposit');
    if ($deposit !== '') {
        if (!is_numeric($deposit) || (float) $deposit < 0) {
            $errors[] = 'Security deposit must be a valid amount, 0 if none is required, or left blank.';
        } else {
            $values['security_deposit'] = $deposit;
        }
    }

    $stay = $text('minimum_stay_months');
    if ($stay !== '') {
        if (!ctype_digit($stay) || (int) $stay < 1 || (int) $stay > 60) {
            $errors[] = 'Minimum stay must be between 1 and 60 months, or left blank.';
        } else {
            $values['minimum_stay_months'] = (int) $stay;
        }
    }

    $ticked = array_filter((array) ($post['payment_methods'] ?? []), 'is_string');
    $methods = array_values(array_intersect(array_keys(payment_method_options()), $ticked));
    $values['payment_methods'] = $methods ? implode(',', $methods) : null;

    $gender = $text('gender_policy');
    $values['gender_policy'] = isset(gender_policy_options()[$gender]) ? $gender : null;

    foreach (['visitors_allowed', 'pets_allowed', 'cooking_allowed'] as $rule) {
        $answer = $text($rule);
        $values[$rule] = $answer === '1' ? 1 : ($answer === '0' ? 0 : null);
    }

    $lat = $text('latitude');
    $lng = $text('longitude');
    if ($lat !== '' || $lng !== '') {
        if (!is_numeric($lat) || !is_numeric($lng) || abs((float) $lat) > 90 || abs((float) $lng) > 180) {
            $errors[] = 'Place the map pin by clicking the map, or clear the location.';
        } else {
            $values['latitude'] = round((float) $lat, 6);
            $values['longitude'] = round((float) $lng, 6);
        }
    }

    $echo = $values;
    foreach (['curfew', 'security_deposit', 'minimum_stay_months', 'latitude', 'longitude'] as $typed) {
        $echo[$typed] = $text($typed);
    }

    return [$values, $errors, $echo];
}

/**
 * The message shown in place of a lookup checklist that came back empty, so a
 * missing or unimported table is visible on the page instead of silently
 * costing the landlord their selections.
 */
function lookup_unavailable_notice($what, $table)
{
    return '<div class="alert alert-warning py-2 px-3 small mb-3">'
        . '<i class="fas fa-exclamation-triangle mr-1"></i> '
        . 'No ' . h($what) . ' are available to choose from. The <code>' . h($table)
        . '</code> table is empty or could not be read &mdash; import <code>database/roomease.sql</code>'
        . ' and check the PHP error log.'
        . '</div>';
}

/**
 * Bootstrap badge for a listing's moderation state. Used by the admin queue
 * and the landlord dashboard so both describe a listing the same way.
 */
function moderation_badge($status)
{
    switch ($status) {
        case 'approved':
            return '<span class="badge badge-success px-2 py-1"><i class="fas fa-check-circle mr-1"></i> Approved</span>';
        case 'rejected':
            return '<span class="badge badge-danger px-2 py-1"><i class="fas fa-times-circle mr-1"></i> Rejected</span>';
        default:
            return '<span class="badge badge-warning px-2 py-1"><i class="fas fa-clock mr-1"></i> Pending</span>';
    }
}

/**
 * The boarding_house_id values the given user has saved, as a lookup set.
 * Fetched once per page rather than queried per listing.
 */
function saved_listing_ids($userId)
{
    global $pdo;
    if (!$userId) {
        return [];
    }
    $stmt = $pdo->prepare('SELECT boarding_house_id FROM favorites WHERE user_id = ?');
    $stmt->execute([$userId]);
    return array_flip($stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * True when the current user may save listings. Saving is a boarder feature;
 * landlords and administrators manage listings instead.
 */
function can_save_listings()
{
    return is_logged_in() && current_role() === 'boarder';
}

/** How long a password reset link stays valid. */
function password_reset_ttl_minutes()
{
    return 60;
}

/**
 * True when the request came from this machine. Reset links are only ever
 * shown on screen for loopback requests, so a deployed copy of RoomEase can
 * never hand a stranger a working link just by typing somebody's email.
 */
function is_local_request()
{
    return in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
}

/**
 * Issue a password reset token for a user and return the raw token.
 *
 * Only the SHA-256 hash is stored, exactly as passwords are hashed: the value
 * that travels in the link is never written to the database. Any earlier
 * unused tokens for the same user are dropped so only the newest link works.
 */
function create_password_reset($userId)
{
    global $pdo;

    // Housekeeping: clear this user's outstanding links plus anything stale.
    $pdo->prepare('DELETE FROM password_resets WHERE user_id = ?')->execute([$userId]);
    $pdo->exec('DELETE FROM password_resets WHERE expires_at < NOW() - INTERVAL 1 DAY');

    $token = bin2hex(random_bytes(32));
    $stmt = $pdo->prepare(
        'INSERT INTO password_resets (user_id, token_hash, expires_at)
         VALUES (?, ?, NOW() + INTERVAL ? MINUTE)'
    );
    $stmt->execute([$userId, hash('sha256', $token), password_reset_ttl_minutes()]);

    return $token;
}

/**
 * Look up an unused, unexpired reset token. Returns the reset row joined to
 * its user, or null. The lookup is by hash, so the raw token is never compared
 * against stored data.
 */
function find_valid_reset($token)
{
    global $pdo;

    if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT pr.reset_id, pr.user_id, pr.expires_at,
                u.email, u.first_name, u.is_active
           FROM password_resets pr
           JOIN users u ON u.user_id = pr.user_id
          WHERE pr.token_hash = ?
            AND pr.used_at IS NULL
            AND pr.expires_at > NOW()'
    );
    $stmt->execute([hash('sha256', $token)]);

    return $stmt->fetch() ?: null;
}

/** Absolute URL for a reset link, which is what has to go in an email. */
function password_reset_url($token)
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host . base_url('auth/reset_password.php?token=' . $token);
}

/**
 * Try to email a reset link. Returns true only if PHP accepted the message.
 *
 * A stock WAMP/XAMPP install has no mail server, so this normally fails; the
 * calling page falls back to showing the link for local requests. Either way a
 * record of the attempt is logged, deliberately WITHOUT the token, so the log
 * itself can never be used to take over an account.
 */
function send_password_reset_email($email, $firstName, $url)
{
    $minutes = password_reset_ttl_minutes();
    $subject = 'Reset your RoomEase password';
    $body = "Hi " . $firstName . ",\r\n\r\n"
        . "Someone asked to reset the password for your RoomEase account.\r\n"
        . "Open the link below within " . $minutes . " minutes to choose a new one:\r\n\r\n"
        . $url . "\r\n\r\n"
        . "If this wasn't you, ignore this message; your password stays unchanged.\r\n\r\n"
        . "RoomEase\r\n";
    $headers = "From: no-reply@roomease.local\r\nContent-Type: text/plain; charset=UTF-8\r\n";

    $sent = false;
    try {
        $sent = @mail($email, $subject, $body, $headers);
    } catch (Throwable $e) {
        $sent = false;
    }

    $logDir = __DIR__ . '/../storage';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    @file_put_contents(
        $logDir . '/password_resets.log',
        sprintf("[%s] reset requested for %s - mail(): %s%s",
            date('Y-m-d H:i:s'), $email, $sent ? 'accepted' : 'FAILED', PHP_EOL),
        FILE_APPEND
    );

    return (bool) $sent;
}

/**
 * Applied last, once every helper above exists: age the session out, and
 * re-check the account behind it against the database on every request.
 */
enforce_session_policy();
