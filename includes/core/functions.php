<?php
/**
 * Shared helper functions used across RoomEase.
 */

// Session cookie flags can only be chosen before the session exists, so the
// hardening file is loaded and applied first. It also sends the response
// security headers, and defines the login/reset throttle helpers.
require_once __DIR__ . "/security.php";

// Outgoing email through Gmail, used for password reset codes.
require_once __DIR__ . "/mailer.php";

// Profile photos are drawn on nearly every page of both the panel and the
// public site, so the one renderer is loaded with the helpers rather than
// required page by page. It calls h(), base_url() and is_avatar_path() below,
// which are all defined by the time anything calls it.
require_once __DIR__ . "/../components/avatar.php";

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
        // Worked out from SCRIPT_NAME, the page being served, rather than from
        // where this file sits: the app root is the folder above auth/,
        // landlord/, boarder/ or admin/, or the page's own folder otherwise.
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

/**
 * Force login; optionally restrict to specific roles. Redirects otherwise.
 *
 * Administrators have their own sign-in page, so a signed-out visitor to a
 * page only administrators can use is sent there; every other page sends them
 * to the public login.
 */
function require_login($roles = null)
{
    if (!is_logged_in()) {
        $adminOnly = $roles !== null
            && !array_diff((array) $roles, ['admin', 'administrator']);
        redirect($adminOnly ? ADMIN_LOGIN_PATH : 'auth/login.php');
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

/** The administrators' sign-in page, relative to the app root. */
const ADMIN_LOGIN_PATH = 'admin/login.php';

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

    $dir = __DIR__ . '/../../assets/uploads/boarding_houses/' . $boardingHouseId;
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create the photo folder for this listing.');
    }

    $stored = [];
    foreach ($queue as $item) {
        $filename = uniqid('bh_', true) . '.' . $item['ext'];
        if (!move_uploaded_file($item['tmp'], $dir . '/' . $filename)) {
            // Keep the batch all-or-nothing: undo whatever already landed.
            foreach ($stored as $done) {
                @unlink(__DIR__ . '/../../' . $done);
            }
            throw new RuntimeException('Could not save the uploaded photos.');
        }
        $stored[] = 'assets/uploads/boarding_houses/' . $boardingHouseId . '/' . $filename;
    }

    return $stored;
}

/* ---------------------------------------------------------------------------
 * Profile photos (database/migration_avatars.sql)
 *
 * An account's photo is a file under assets/uploads/avatars/, and the path to
 * it lives in users.avatar_path. That folder is covered by the same
 * assets/uploads/.htaccess as listing photos, which takes PHP off the folder
 * and pins the content type, so a profile photo cannot be made to run.
 *
 * A photo is always stored as a square, because every place that draws one
 * draws a circle. Cropping once on upload beats cropping in CSS on every page,
 * and it also caps what the server keeps: a 4000px phone photo becomes a
 * 512px file of a few tens of kilobytes.
 * ------------------------------------------------------------------------ */

/** Where profile photos live, relative to the app root. */
const AVATAR_UPLOAD_DIR = 'assets/uploads/avatars';

/** The side, in pixels, of a stored profile photo. */
const AVATAR_SIZE = 512;

/** True for a path this app wrote into AVATAR_UPLOAD_DIR, and nothing else. */
function is_avatar_path($path)
{
    return is_string($path)
        && preg_match('#^assets/uploads/avatars/av-[0-9]+-[a-f0-9]{16}\.(jpg|png|webp)$#', $path) === 1;
}

/**
 * The initials drawn in place of a photo: the first letter of the first two
 * words of the name. Used by every avatar, on the panel and the public site,
 * so an account without a photo looks the same everywhere.
 */
function avatar_initials($name)
{
    $words = preg_split('/\s+/u', trim((string) $name), -1, PREG_SPLIT_NO_EMPTY);
    if (!$words) {
        return '?';
    }
    $letters = '';
    foreach (array_slice($words, 0, 2) as $word) {
        $letters .= mb_substr($word, 0, 1);
    }
    return mb_strtoupper($letters);
}

/**
 * Square-crop and shrink an uploaded image to AVATAR_SIZE, writing it back in
 * its own format. Returns false when GD cannot handle the file, which leaves
 * the caller to store the original untouched rather than reject the upload:
 * GD is not guaranteed to be installed on every machine this project runs on.
 */
function resize_avatar_square($sourcePath, $destPath, $ext)
{
    if (!function_exists('imagecreatetruecolor')) {
        return false;
    }

    $readers = ['jpg' => 'imagecreatefromjpeg', 'png' => 'imagecreatefrompng', 'webp' => 'imagecreatefromwebp'];
    $reader = $readers[$ext] ?? null;
    if ($reader === null || !function_exists($reader)) {
        return false;
    }

    $source = @$reader($sourcePath);
    if (!$source) {
        return false;
    }

    // Phone cameras record the rotation rather than applying it, so a portrait
    // photo arrives lying on its side unless the EXIF tag is honoured.
    if ($ext === 'jpg' && function_exists('exif_read_data')) {
        $exif = @exif_read_data($sourcePath);
        $orientation = (int) ($exif['Orientation'] ?? 0);
        $angle = [3 => 180, 6 => -90, 8 => 90][$orientation] ?? 0;
        if ($angle !== 0) {
            $rotated = @imagerotate($source, $angle, 0);
            if ($rotated) {
                $source = $rotated;
            }
        }
    }

    $width  = imagesx($source);
    $height = imagesy($source);
    $side   = min($width, $height);
    // Crop from the centre horizontally, but from a third of the way down
    // vertically: in a portrait photo of a person the face sits above centre.
    $srcX = (int) (($width - $side) / 2);
    $srcY = (int) (($height - $side) / 3);

    $size = min(AVATAR_SIZE, $side);
    $canvas = imagecreatetruecolor($size, $size);
    if ($ext === 'png' || $ext === 'webp') {
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
    }

    imagecopyresampled($canvas, $source, 0, 0, $srcX, $srcY, $size, $size, $side, $side);

    $writers = ['jpg' => 'imagejpeg', 'png' => 'imagepng', 'webp' => 'imagewebp'];
    $quality = ['jpg' => 88, 'png' => 6, 'webp' => 88];
    $ok = @$writers[$ext]($canvas, $destPath, $quality[$ext]);

    // No imagedestroy() here on purpose: a GdImage is an object and is freed
    // when it goes out of scope. The call has done nothing since PHP 8.0 and
    // raises a deprecation notice on PHP 8.5, which would print into the page.
    return (bool) $ok;
}

/**
 * Store one uploaded profile photo for $userId and return its relative path.
 * Returns null when nothing was uploaded. Throws on validation failure, with a
 * message meant to be shown to whoever tried.
 *
 * Every check runs before anything is written, in the same order as the
 * listing photo upload above: the extension is decided by the sniffed MIME
 * type and never by the name the browser sent.
 */
function handle_avatar_upload($fileField, $userId)
{
    $upload = $_FILES[$fileField] ?? null;
    if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    $maxBytes = max_upload_bytes();

    if ($upload['error'] === UPLOAD_ERR_INI_SIZE || $upload['error'] === UPLOAD_ERR_FORM_SIZE) {
        throw new RuntimeException('That photo is larger than the ' . format_bytes($maxBytes) . ' limit.');
    }
    if ($upload['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name'])) {
        throw new RuntimeException('The photo did not upload. Please try again.');
    }
    if ($upload['size'] > $maxBytes) {
        throw new RuntimeException('That photo is larger than the ' . format_bytes($maxBytes) . ' limit.');
    }

    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $upload['tmp_name']);
    finfo_close($finfo);

    if (!isset($allowed[$mime]) || @getimagesize($upload['tmp_name']) === false) {
        throw new RuntimeException('Your photo must be a JPG, PNG, or WEBP image.');
    }

    $ext = $allowed[$mime];
    $dir = __DIR__ . '/../../' . AVATAR_UPLOAD_DIR;
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create the folder for profile photos.');
    }

    $stored = AVATAR_UPLOAD_DIR . '/av-' . (int) $userId . '-' . bin2hex(random_bytes(8)) . '.' . $ext;
    $target = __DIR__ . '/../../' . $stored;

    if (!move_uploaded_file($upload['tmp_name'], $target)) {
        throw new RuntimeException('Could not save your photo.');
    }

    // Squaring is an improvement, not a requirement: on a machine without GD
    // the original is kept and the circle crops it in CSS instead.
    resize_avatar_square($target, $target, $ext);

    return $stored;
}

/** Delete a profile photo from disk. Ignores anything outside the avatars folder. */
function delete_avatar($path)
{
    if (is_avatar_path($path)) {
        @unlink(__DIR__ . '/../../' . $path);
    }
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

/**
 * Room types offered on the room form and in the browse filter, as
 * room_type_id => room_type_name.
 *
 * Both read from here so the two lists cannot drift apart, and since
 * rooms.room_type_id is a foreign key onto this table, a value that is not in
 * this list can no longer be stored at all.
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
 * The JOIN that hides listings whose landlord is deactivated or archived.
 *
 * Browse never used to look at the landlord's account at all, so a
 * deactivated landlord's listings stayed on the public site. Soft deletion
 * would have inherited exactly the same hole.
 */
const LIVE_LANDLORD_JOIN =
    'JOIN users lu ON lu.user_id = bh.landlord_id AND lu.is_active = 1 AND lu.deleted_at IS NULL';

/**
 * A listing's own switches for being on the public site. A listing an
 * administrator removed stays in the table, archived, and never shows.
 */
const LIVE_STATUS_WHERE = "bh.availability_status = 'available' AND bh.moderation_status = 'approved'"
    . ' AND bh.deleted_at IS NULL';

/**
 * Everything else a listing needs to be on the public site: approved, switched
 * on, and at least one room. Pair with LIVE_LANDLORD_JOIN.
 */
const LIVE_LISTING_WHERE = LIVE_STATUS_WHERE
    . ' AND EXISTS (SELECT 1 FROM rooms hr WHERE hr.boarding_house_id = bh.boarding_house_id)';

/**
 * A listing's cover photo: its house cover first, then any house photo, and
 * only then a room photo, so a listing with only room photos still has one.
 */
const COVER_PHOTO_SELECT = '(SELECT img.image_path FROM images img
       WHERE img.boarding_house_id = bh.boarding_house_id
       ORDER BY img.room_id IS NULL DESC, img.is_primary DESC, img.image_id ASC
       LIMIT 1) AS cover_photo';

/**
 * A listing's cover photo at thumbnail size, for the administrator's queue and
 * tables. $l needs cover_photo (COVER_PHOTO_SELECT).
 *
 * A listing with no photo gets a drawn placeholder rather than an empty gap,
 * because "this one has nothing to look at" is itself something a moderator
 * wants to see at a glance. The picture is decorative: the listing's name is
 * always the link beside it.
 */
function listing_thumb_html(array $l, $class = 'queue-thumb')
{
    if (!empty($l['cover_photo'])) {
        return '<img class="' . h($class) . '" src="' . h(base_url($l['cover_photo']))
            . '" alt="" loading="lazy" decoding="async">';
    }
    return '<span class="' . h($class) . ' ' . h($class) . '--empty" aria-hidden="true">'
        . '<i class="fas fa-camera"></i></span>';
}

/* ---------------------------------------------------------------------------
 * Rooms (database/boardinghouse.sql)
 *
 * Rent, room type and capacity belong to each room. Whether a room is
 * available is never stored: it is open with a slot left, full, or closed.
 * ------------------------------------------------------------------------ */

/** The room summary columns room_summary_join() provides, for a SELECT list. */
const ROOM_SUMMARY_COLUMNS = 'rs.room_count, rs.rooms_available, rs.rooms_open,
       rs.rent_from_available, rs.rent_from_all, rs.room_types';

/**
 * One row of room figures per listing, joined as `rs`. Pass $inner = true
 * where a listing with no rooms should drop out of the results.
 */
function room_summary_join($inner = false)
{
    return ($inner ? 'JOIN' : 'LEFT JOIN') . " (
        SELECT r.boarding_house_id,
               COUNT(*) AS room_count,
               SUM(r.is_open = 1 AND r.slots_taken < r.capacity) AS rooms_available,
               SUM(r.is_open = 1) AS rooms_open,
               MIN(CASE WHEN r.is_open = 1 AND r.slots_taken < r.capacity THEN r.monthly_rent END) AS rent_from_available,
               MIN(r.monthly_rent) AS rent_from_all,
               GROUP_CONCAT(DISTINCT rt.room_type_name ORDER BY rt.room_type_id SEPARATOR ', ') AS room_types
          FROM rooms r
          JOIN room_types rt ON rt.room_type_id = r.room_type_id
         GROUP BY r.boarding_house_id
    ) rs ON rs.boarding_house_id = bh.boarding_house_id";
}

/**
 * Where a room stands, for any row with capacity, slots_taken and is_open.
 *
 * Returns key (available|full|closed), label, pill (public CSS class), badge
 * (Bootstrap class for the panel), slots_left, and note, the one line a
 * boarder reads under the room.
 */
function room_state(array $room)
{
    $capacity = max(1, (int) $room['capacity']);
    $taken = min($capacity, max(0, (int) $room['slots_taken']));
    $left = $capacity - $taken;

    if (empty($room['is_open'])) {
        return ['key' => 'closed', 'label' => 'Not available', 'pill' => 'pill--rejected', 'badge' => 'badge-secondary',
            'slots_left' => $left, 'note' => 'Not taking tenants right now'];
    }
    if ($left === 0) {
        return ['key' => 'full', 'label' => 'Full', 'pill' => 'pill--unavailable', 'badge' => 'badge-warning',
            'slots_left' => 0, 'note' => $capacity === 1 ? 'Occupied' : 'Fully occupied'];
    }
    return ['key' => 'available', 'label' => 'Available', 'pill' => 'pill--available', 'badge' => 'badge-success',
        'slots_left' => $left,
        'note' => $capacity === 1 ? 'Vacant' : $left . ' of ' . $capacity . ' slots left'];
}

/** Sort order for rooms shown to boarders: available, then full, then closed. */
function room_state_rank(array $room)
{
    return ['available' => 0, 'full' => 1, 'closed' => 2][room_state($room)['key']];
}

/**
 * Where a whole listing stands, from the room_summary_join() columns.
 *
 * Returns key (available|full|closed|none), label and pill for the badge,
 * summary ("3 of 5 rooms available"), rent_from, and room_count.
 */
function listing_availability(array $listing)
{
    $count = (int) ($listing['room_count'] ?? 0);
    $available = (int) ($listing['rooms_available'] ?? 0);
    $open = (int) ($listing['rooms_open'] ?? 0);
    $rentFrom = $listing['rent_from_available'] ?? null;
    if ($rentFrom === null) {
        $rentFrom = $listing['rent_from_all'] ?? null;
    }

    $base = ['room_count' => $count, 'rent_from' => $rentFrom];

    if ($count === 0) {
        return $base + ['key' => 'none', 'label' => 'No rooms yet', 'pill' => 'pill--unavailable',
            'summary' => 'No rooms added yet'];
    }
    if ($available > 0) {
        return $base + ['key' => 'available', 'label' => 'Available', 'pill' => 'pill--available',
            'summary' => $count === 1 ? '1 room, available' : $available . ' of ' . $count . ' rooms available'];
    }
    if ($open > 0) {
        // "All 5 rooms taken" only when every room really is full; with some
        // closed as well, say how many are available instead.
        $summary = $count === 1 ? '1 room, occupied'
            : ($open === $count ? 'All ' . $count . ' rooms taken' : '0 of ' . $count . ' rooms available');
        return $base + ['key' => 'full', 'label' => 'Fully occupied', 'pill' => 'pill--unavailable',
            'summary' => $summary];
    }
    return $base + ['key' => 'closed', 'label' => 'Not available', 'pill' => 'pill--rejected',
        'summary' => 'Not taking tenants right now'];
}

/**
 * How many listings the public site is showing right now, and how many rooms
 * in them are available. The home page quotes both, so they have to be the
 * real figures, never rounded or padded.
 */
function live_listing_stats()
{
    global $pdo;
    try {
        $row = $pdo->query(
            'SELECT COUNT(*) AS listings, COALESCE(SUM(rs.rooms_available), 0) AS rooms_available
               FROM boarding_houses bh ' . LIVE_LANDLORD_JOIN . ' ' . room_summary_join(true) . '
              WHERE ' . LIVE_STATUS_WHERE
        )->fetch();
        return ['listings' => (int) $row['listings'], 'rooms_available' => (int) $row['rooms_available']];
    } catch (PDOException $e) {
        error_log('RoomEase: live listing stats failed - ' . $e->getMessage());
        return ['listings' => 0, 'rooms_available' => 0];
    }
}

/**
 * Every room type with the number of live listings that have an open room of
 * that type, which is exactly what browse returns for that filter. Types with
 * none are kept, with a count of 0, in the same order as the browse filter.
 */
function room_type_counts()
{
    return lookup_options(
        'SELECT rt.room_type_id, rt.room_type_name, COUNT(DISTINCT bh.boarding_house_id) AS listings
           FROM room_types rt
           LEFT JOIN rooms r ON r.room_type_id = rt.room_type_id AND r.is_open = 1
           LEFT JOIN (boarding_houses bh ' . LIVE_LANDLORD_JOIN . ')
                  ON bh.boarding_house_id = r.boarding_house_id AND ' . LIVE_STATUS_WHERE . '
          GROUP BY rt.room_type_id, rt.room_type_name
          ORDER BY rt.room_type_id ASC',
        PDO::FETCH_ASSOC
    );
}

/**
 * A landlord's own room, joined to its listing, or false. Ownership is part of
 * the query, so a room id from another landlord's listing simply is not found.
 */
function find_landlord_room($roomId, $landlordId)
{
    global $pdo;
    $stmt = $pdo->prepare(
        'SELECT r.*, bh.name AS house_name, bh.landlord_id
           FROM rooms r
           JOIN boarding_houses bh ON bh.boarding_house_id = r.boarding_house_id
          WHERE r.room_id = ? AND bh.landlord_id = ? AND bh.deleted_at IS NULL'
    );
    $stmt->execute([(int) $roomId, (int) $landlordId]);
    return $stmt->fetch();
}

/**
 * Every listing a landlord owns, newest first, with its room figures and a
 * photo count each. Shown on the dashboard and on My Boarding Houses.
 */
function landlord_listings($landlordId)
{
    global $pdo;
    $stmt = $pdo->prepare(
        'SELECT bh.*, ' . ROOM_SUMMARY_COLUMNS . ',
                (SELECT COUNT(*) FROM images img
                   WHERE img.boarding_house_id = bh.boarding_house_id) AS photo_count
           FROM boarding_houses bh
           ' . room_summary_join() . '
          WHERE bh.landlord_id = ? AND bh.deleted_at IS NULL
          ORDER BY bh.created_at DESC'
    );
    $stmt->execute([(int) $landlordId]);
    return $stmt->fetchAll();
}

/** The fields of a room that has not been filled in yet. */
function blank_room($name = '')
{
    return ['name' => $name, 'room_type_id' => '', 'monthly_rent' => '', 'capacity' => '1',
        'slots_taken' => '0', 'is_open' => true, 'description' => ''];
}

/**
 * Read and check one room's fields as submitted, from the room page or from a
 * row of the Add Listing form. Returns [$room, $errors]: $room keeps what was
 * typed so the form can be shown again, and $errors are ready to display.
 * $label, such as "Room 2", starts each message when several rooms are
 * checked at once. Whether the name is already used is up to the caller,
 * since that depends on which listing the room belongs to.
 */
function room_from_input(array $input, array $roomTypes, $label = '')
{
    $text = function ($key) use ($input) {
        return is_string($input[$key] ?? null) ? trim($input[$key]) : '';
    };
    $room = [
        'name' => normalise_lookup_name($text('name')),
        'room_type_id' => $text('room_type_id'),
        'monthly_rent' => $text('monthly_rent'),
        'capacity' => $text('capacity'),
        'slots_taken' => $text('slots_taken'),
        'is_open' => ($input['is_open'] ?? '') === '1',
        'description' => $text('description'),
    ];
    $p = $label !== '' ? $label . ': ' : '';
    $errors = [];

    if ($room['name'] === '') {
        $errors[] = $p . 'Give the room a name, such as "Room 1" or "2nd floor front".';
    } elseif (mb_strlen($room['name']) > 60) {
        $errors[] = $p . 'Room names must be 60 characters or fewer.';
    }
    if (!isset($roomTypes[(int) $room['room_type_id']])) {
        $errors[] = $p . 'Choose a room type from the list.';
    }
    if (!is_numeric($room['monthly_rent']) || (float) $room['monthly_rent'] < 0 || (float) $room['monthly_rent'] > 1000000) {
        $errors[] = $p . 'Enter a valid monthly rent.';
    }
    if (!ctype_digit($room['capacity']) || (int) $room['capacity'] < 1 || (int) $room['capacity'] > 100) {
        $errors[] = $p . 'Capacity must be between 1 and 100 people.';
    }
    if (!ctype_digit($room['slots_taken'])) {
        $errors[] = $p . 'Slots taken must be a whole number, 0 if the room is empty.';
    } elseif (ctype_digit($room['capacity']) && (int) $room['slots_taken'] > (int) $room['capacity']) {
        $errors[] = $p . 'Slots taken cannot be more than the room\'s capacity.';
    }
    if (mb_strlen($room['description']) > 500) {
        $errors[] = $p . 'The room description must be 500 characters or fewer.';
    }

    return [$room, $errors];
}

/** The values room_from_input() checked, in the order insert and update use. */
function room_values(array $room)
{
    return [
        $room['name'], (int) $room['room_type_id'], $room['monthly_rent'], (int) $room['capacity'],
        (int) $room['slots_taken'], $room['is_open'] ? 1 : 0,
        $room['description'] !== '' ? $room['description'] : null,
    ];
}

/** Add a checked room to a listing. Returns the new room_id. */
function insert_room($houseId, array $room)
{
    global $pdo;
    $pdo->prepare(
        'INSERT INTO rooms (name, room_type_id, monthly_rent, capacity, slots_taken, is_open, description, boarding_house_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute(array_merge(room_values($room), [(int) $houseId]));
    return (int) $pdo->lastInsertId();
}

/**
 * Store the photos uploaded in $fileField as a room's photos. The first one
 * becomes the room's main photo when it has none. Returns how many were
 * saved; throws RuntimeException, from handle_photo_uploads(), if a file is
 * refused.
 */
function attach_room_photos($houseId, $roomId, $fileField)
{
    global $pdo;
    $paths = handle_photo_uploads($fileField, (int) $houseId);
    if (!$paths) {
        return 0;
    }

    $hasMain = $pdo->prepare('SELECT COUNT(*) FROM images WHERE room_id = ? AND is_primary = 1');
    $hasMain->execute([(int) $roomId]);
    $needsMain = (int) $hasMain->fetchColumn() === 0;

    $insImg = $pdo->prepare(
        'INSERT INTO images (boarding_house_id, room_id, image_path, is_primary) VALUES (?, ?, ?, ?)'
    );
    foreach ($paths as $i => $path) {
        $insImg->execute([(int) $houseId, (int) $roomId, $path, ($needsMain && $i === 0) ? 1 : 0]);
    }
    return count($paths);
}

/* ---------------------------------------------------------------------------
 * Utilities and amenities
 *
 * Both lists work the same way. An item with a NULL landlord_id was made by
 * the administrator and every landlord can use it. An item with a landlord_id
 * was made by that landlord, and only that landlord sees it or can put it on a
 * listing. Boarders see whatever a listing has, whoever made it.
 * ------------------------------------------------------------------------ */

/** Table and column names for each kind of list. */
function lookup_kind($kind)
{
    static $kinds = [
        'utility' => ['table' => 'utilities', 'id' => 'utility_id', 'name' => 'utility_name',
            'junction' => 'boarding_house_utilities', 'singular' => 'utility', 'plural' => 'utilities',
            'Singular' => 'Utility', 'Plural' => 'Utilities'],
        'amenity' => ['table' => 'amenities', 'id' => 'amenity_id', 'name' => 'amenity_name',
            'junction' => 'boarding_house_amenities', 'singular' => 'amenity', 'plural' => 'amenities',
            'Singular' => 'Amenity', 'Plural' => 'Amenities'],
    ];
    if (!isset($kinds[$kind])) {
        throw new InvalidArgumentException('Unknown list: ' . $kind);
    }
    return $kinds[$kind];
}

/** A typed item name with its spacing tidied, as it will be stored. */
function normalise_lookup_name($name)
{
    return trim(preg_replace('/\s+/u', ' ', (string) $name));
}

/**
 * The items a landlord may put on a listing: the administrator's, then their
 * own. Each row is ['id', 'name', 'own']. With $landlordId null, only the
 * administrator's.
 */
function lookup_choices($kind, $landlordId = null)
{
    global $pdo;
    $k = lookup_kind($kind);
    try {
        $stmt = $pdo->prepare(
            "SELECT {$k['id']} AS id, {$k['name']} AS name, landlord_id IS NOT NULL AS own
               FROM {$k['table']}
              WHERE landlord_id IS NULL OR landlord_id = ?
              ORDER BY landlord_id IS NOT NULL, (CASE WHEN landlord_id IS NULL THEN {$k['id']} END), {$k['name']}"
        );
        $stmt->execute([$landlordId === null ? 0 : (int) $landlordId]);
        return array_map(function ($row) {
            return ['id' => (int) $row['id'], 'name' => $row['name'], 'own' => (bool) $row['own']];
        }, $stmt->fetchAll());
    } catch (PDOException $e) {
        error_log('RoomEase: ' . $k['plural'] . ' lookup failed - ' . $e->getMessage());
        return [];
    }
}

/**
 * An existing item whose name clashes with $name, or null. A landlord's item
 * clashes with the administrator's list and with their own; an administrator
 * item clashes only with the administrator's list. The column collation
 * ignores case, so "water" clashes with "Water".
 */
function lookup_name_clash($kind, $name, $landlordId = null, $exceptId = null)
{
    global $pdo;
    $k = lookup_kind($kind);
    $stmt = $pdo->prepare(
        "SELECT {$k['id']} AS id, {$k['name']} AS name, landlord_id
           FROM {$k['table']}
          WHERE {$k['name']} = ?
            AND (landlord_id IS NULL" . ($landlordId === null ? '' : ' OR landlord_id = ?') . ")
            AND {$k['id']} <> ?
          LIMIT 1"
    );
    $params = [$name];
    if ($landlordId !== null) {
        $params[] = (int) $landlordId;
    }
    $params[] = (int) $exceptId;
    $stmt->execute($params);
    return $stmt->fetch() ?: null;
}

/** Why a typed name cannot be used, or null when it can. */
function lookup_name_problem($kind, $name)
{
    $k = lookup_kind($kind);
    if ($name === '') {
        return 'Enter a name for the ' . $k['singular'] . '.';
    }
    if (mb_strlen($name) > 100) {
        return $k['Singular'] . ' names must be 100 characters or fewer.';
    }
    return null;
}

/**
 * Move every landlord's copy of a name onto an administrator item, so no
 * landlord ends up with the same thing listed twice. Listings keep what they
 * had: their rows are pointed at the administrator item, and where a listing
 * already had both, the administrator item's row (and billing policy) stays.
 */
function merge_lookup_copies($kind, $globalId)
{
    global $pdo;
    $k = lookup_kind($kind);

    $name = $pdo->prepare("SELECT {$k['name']} FROM {$k['table']} WHERE {$k['id']} = ? AND landlord_id IS NULL");
    $name->execute([(int) $globalId]);
    $globalName = $name->fetchColumn();
    if ($globalName === false) {
        return 0;
    }

    $copies = $pdo->prepare(
        "SELECT {$k['id']} FROM {$k['table']} WHERE {$k['name']} = ? AND landlord_id IS NOT NULL"
    );
    $copies->execute([$globalName]);

    $move = $pdo->prepare("UPDATE IGNORE {$k['junction']} SET {$k['id']} = ? WHERE {$k['id']} = ?");
    $drop = $pdo->prepare("DELETE FROM {$k['table']} WHERE {$k['id']} = ?");
    $merged = 0;
    foreach ($copies->fetchAll(PDO::FETCH_COLUMN) as $copyId) {
        $move->execute([(int) $globalId, (int) $copyId]);
        $drop->execute([(int) $copyId]);
        $merged++;
    }
    return $merged;
}

/**
 * Add an item. Returns [id, error]. With $reuse, a name that already exists
 * in what the landlord can use returns that item's id instead of an error,
 * which is what the listing form wants when a landlord types "Water".
 */
function create_lookup($kind, $name, $landlordId = null, $reuse = false)
{
    global $pdo;
    $k = lookup_kind($kind);
    $name = normalise_lookup_name($name);

    if ($problem = lookup_name_problem($kind, $name)) {
        return [null, $problem];
    }
    if ($clash = lookup_name_clash($kind, $name, $landlordId)) {
        if ($reuse) {
            return [(int) $clash['id'], null];
        }
        return [null, $clash['landlord_id'] === null
            ? '"' . $clash['name'] . '" is already on the list everyone uses.'
            : 'You already have "' . $clash['name'] . '".'];
    }

    $pdo->prepare("INSERT INTO {$k['table']} (landlord_id, {$k['name']}) VALUES (?, ?)")
        ->execute([$landlordId === null ? null : (int) $landlordId, $name]);
    $id = (int) $pdo->lastInsertId();

    if ($landlordId === null) {
        merge_lookup_copies($kind, $id);
    }
    return [$id, null];
}

/** Rename an item. Returns an error message, or null on success. */
function rename_lookup($kind, $id, $name, $landlordId = null)
{
    global $pdo;
    $k = lookup_kind($kind);
    $name = normalise_lookup_name($name);

    if ($problem = lookup_name_problem($kind, $name)) {
        return $problem;
    }
    if ($clash = lookup_name_clash($kind, $name, $landlordId, $id)) {
        return $clash['landlord_id'] === null
            ? '"' . $clash['name'] . '" is already on the list everyone uses.'
            : 'You already have "' . $clash['name'] . '".';
    }

    $owner = $landlordId === null ? 'landlord_id IS NULL' : 'landlord_id = ' . (int) $landlordId;
    $pdo->prepare("UPDATE {$k['table']} SET {$k['name']} = ? WHERE {$k['id']} = ? AND $owner")
        ->execute([$name, (int) $id]);

    if ($landlordId === null) {
        merge_lookup_copies($kind, $id);
    }
    return null;
}

/**
 * Make a landlord's item available to every landlord. If the administrator
 * already has one by that name, the landlord's copies are merged into it.
 * Returns an error message, or null on success.
 */
function promote_lookup($kind, $id)
{
    global $pdo;
    $k = lookup_kind($kind);

    $stmt = $pdo->prepare("SELECT {$k['name']} AS name FROM {$k['table']} WHERE {$k['id']} = ? AND landlord_id IS NOT NULL");
    $stmt->execute([(int) $id]);
    $item = $stmt->fetch();
    if (!$item) {
        return 'That ' . $k['singular'] . ' was not found, or is already available to everyone.';
    }

    // Joins a transaction the caller already opened rather than nesting one.
    $ownTransaction = !$pdo->inTransaction();
    if ($ownTransaction) {
        $pdo->beginTransaction();
    }
    try {
        $existing = lookup_name_clash($kind, $item['name']);
        if ($existing) {
            $globalId = (int) $existing['id'];
        } else {
            $pdo->prepare("UPDATE {$k['table']} SET landlord_id = NULL WHERE {$k['id']} = ?")->execute([(int) $id]);
            $globalId = (int) $id;
        }
        merge_lookup_copies($kind, $globalId);
        if ($ownTransaction) {
            $pdo->commit();
        }
    } catch (PDOException $e) {
        if ($ownTransaction) {
            $pdo->rollBack();
        }
        error_log('RoomEase: promoting a ' . $k['singular'] . ' failed - ' . $e->getMessage());
        return 'That ' . $k['singular'] . ' could not be made available to everyone.';
    }
    return null;
}

/** How many listings use each item, as id => count. */
function lookup_usage_counts($kind)
{
    global $pdo;
    $k = lookup_kind($kind);
    try {
        return array_map('intval', $pdo->query(
            "SELECT {$k['id']}, COUNT(*) FROM {$k['junction']} GROUP BY {$k['id']}"
        )->fetchAll(PDO::FETCH_KEY_PAIR));
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * Read the utilities and amenities part of a submitted listing form.
 *
 * Returns ['amenity_ids' => int[], 'utilities' => [id => policy],
 * 'new_amenities' => string[], 'new_utilities' => [['name', 'policy']],
 * 'errors' => string[]]. Only items this landlord may use are kept, so a
 * forged id for another landlord's item is dropped rather than stored.
 */
function listing_lookups_from_post(array $post, $landlordId)
{
    $allowed = function ($kind) use ($landlordId) {
        return array_flip(array_column(lookup_choices($kind, $landlordId), 'id'));
    };
    $allowedAmenities = $allowed('amenity');
    $allowedUtilities = $allowed('utility');
    $errors = [];

    $amenityIds = [];
    foreach ((array) ($post['amenities'] ?? []) as $id) {
        if (is_scalar($id) && isset($allowedAmenities[(int) $id])) {
            $amenityIds[(int) $id] = (int) $id;
        }
    }

    $utilities = [];
    $policies = (array) ($post['billing_policy'] ?? []);
    foreach ((array) ($post['utilities'] ?? []) as $id) {
        if (is_scalar($id) && isset($allowedUtilities[(int) $id])) {
            $policy = is_string($policies[(int) $id] ?? null) ? trim($policies[(int) $id]) : '';
            $utilities[(int) $id] = mb_substr($policy, 0, 150);
        }
    }

    $newAmenities = [];
    foreach ((array) ($post['new_amenity_name'] ?? []) as $name) {
        $name = normalise_lookup_name(is_string($name) ? $name : '');
        if ($name === '') {
            continue;
        }
        if ($problem = lookup_name_problem('amenity', $name)) {
            $errors[] = $problem;
            continue;
        }
        $newAmenities[mb_strtolower($name)] = $name;
    }

    $newUtilities = [];
    $newPolicies = (array) ($post['new_utility_policy'] ?? []);
    foreach ((array) ($post['new_utility_name'] ?? []) as $i => $name) {
        $name = normalise_lookup_name(is_string($name) ? $name : '');
        if ($name === '') {
            continue;
        }
        if ($problem = lookup_name_problem('utility', $name)) {
            $errors[] = $problem;
            continue;
        }
        $policy = is_string($newPolicies[$i] ?? null) ? trim($newPolicies[$i]) : '';
        $newUtilities[mb_strtolower($name)] = ['name' => $name, 'policy' => mb_substr($policy, 0, 150)];
    }

    return [
        'amenity_ids' => array_values($amenityIds),
        'utilities' => $utilities,
        'new_amenities' => array_values($newAmenities),
        'new_utilities' => array_values($newUtilities),
        'errors' => $errors,
    ];
}

/**
 * Store a listing's utilities and amenities from listing_lookups_from_post().
 * Items the landlord typed in are created as theirs first (or matched to one
 * they can already use). With $replace, the listing's current rows are
 * cleared first, as an edit does.
 */
function save_listing_lookups($houseId, $landlordId, array $lookups, $replace)
{
    global $pdo;
    $houseId = (int) $houseId;

    $amenityIds = $lookups['amenity_ids'];
    foreach ($lookups['new_amenities'] as $name) {
        [$id] = create_lookup('amenity', $name, $landlordId, true);
        if ($id) {
            $amenityIds[] = $id;
        }
    }

    $utilities = $lookups['utilities'];
    foreach ($lookups['new_utilities'] as $new) {
        [$id] = create_lookup('utility', $new['name'], $landlordId, true);
        if ($id && !isset($utilities[$id])) {
            $utilities[$id] = $new['policy'];
        }
    }

    if ($replace) {
        $pdo->prepare('DELETE FROM boarding_house_amenities WHERE boarding_house_id = ?')->execute([$houseId]);
        $pdo->prepare('DELETE FROM boarding_house_utilities WHERE boarding_house_id = ?')->execute([$houseId]);
    }

    $insAmen = $pdo->prepare(
        'INSERT IGNORE INTO boarding_house_amenities (boarding_house_id, amenity_id, is_available) VALUES (?, ?, 1)'
    );
    foreach (array_unique($amenityIds) as $id) {
        $insAmen->execute([$houseId, $id]);
    }

    $insUtil = $pdo->prepare(
        'INSERT IGNORE INTO boarding_house_utilities (boarding_house_id, utility_id, billing_policy) VALUES (?, ?, ?)'
    );
    foreach ($utilities as $id => $policy) {
        $insUtil->execute([$houseId, $id, $policy !== '' ? $policy : 'Included in Rent']);
    }
}

/* ---------------------------------------------------------------------------
 * Stay terms (database/boardinghouse.sql)
 *
 * What a boarder asks before visiting: curfew, deposit, minimum stay, how rent
 * is paid, who the house accepts, and whether visitors, pets and cooking are
 * allowed, plus the listing's map pin. Every one is optional, and NULL means
 * the landlord has not said, so the listing page leaves it out rather than
 * guessing.
 * ------------------------------------------------------------------------ */

/* ---------------------------------------------------------------------------
 * Administration (database/boardinghouse.sql)
 *
 * The activity log records what administrators do to listings and accounts,
 * and listing decisions are passed on to the landlord: by email, and on their
 * dashboard, which reads the same log.
 * ------------------------------------------------------------------------ */

/**
 * Every action the activity log records: how it reads, the badge it wears,
 * and what kind of thing it acts on.
 */
function admin_action_types()
{
    return [
        'listing_approve' => ['label' => 'Approved listing',    'badge' => 'badge-success',   'target' => 'listing'],
        'listing_reject'  => ['label' => 'Rejected listing',    'badge' => 'badge-warning',   'target' => 'listing'],
        'listing_remove'  => ['label' => 'Removed listing',     'badge' => 'badge-danger',    'target' => 'listing'],
        'listing_restore' => ['label' => 'Restored listing',    'badge' => 'badge-info',      'target' => 'listing'],
        'user_activate'   => ['label' => 'Activated account',   'badge' => 'badge-success',   'target' => 'user'],
        'user_deactivate' => ['label' => 'Deactivated account', 'badge' => 'badge-warning',   'target' => 'user'],
        'user_remove'     => ['label' => 'Removed account',     'badge' => 'badge-danger',    'target' => 'user'],
        'user_restore'    => ['label' => 'Restored account',    'badge' => 'badge-info',      'target' => 'user'],
        'export_users'    => ['label' => 'Exported users',      'badge' => 'badge-secondary', 'target' => 'export'],
        'export_listings' => ['label' => 'Exported listings',   'badge' => 'badge-secondary', 'target' => 'export'],
    ];
}

/**
 * Write one entry to the activity log, as the signed-in administrator.
 *
 * $targetLabel is the listing or account name as it is now, so the entry still
 * reads correctly after a rename. A log that cannot be written is reported to
 * the PHP error log but never undoes or blocks the action it describes.
 */
function log_admin_action($action, $targetId, $targetLabel, $detail = null)
{
    global $pdo;

    $types = admin_action_types();
    if (!isset($types[$action])) {
        error_log('RoomEase: unknown activity log action ' . $action);
        return;
    }

    try {
        $pdo->prepare(
            'INSERT INTO admin_actions (admin_id, action, target_type, target_id, target_label, detail)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null,
            $action,
            $types[$action]['target'],
            $targetId === null ? null : (int) $targetId,
            mb_substr((string) $targetLabel, 0, 200),
            ($detail === null || trim((string) $detail) === '') ? null : mb_substr(trim((string) $detail), 0, 500),
        ]);
    } catch (PDOException $e) {
        error_log('RoomEase: could not write the activity log - ' . $e->getMessage());
    }
}

/**
 * The activity log for one listing or account, newest first, with the name of
 * the administrator behind each entry.
 */
function admin_actions_for($targetType, $targetId, $limit = 20)
{
    global $pdo;
    try {
        $stmt = $pdo->prepare(
            "SELECT a.*, CONCAT(u.first_name, ' ', u.last_name) AS admin_name
               FROM admin_actions a
               LEFT JOIN users u ON u.user_id = a.admin_id
              WHERE a.target_type = ? AND a.target_id = ?
              ORDER BY a.created_at DESC, a.action_id DESC
              LIMIT " . (int) $limit
        );
        $stmt->execute([$targetType, (int) $targetId]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

/* ---------------------------------------------------------------------------
 * Administrator notes on an account (database/migration_account_notes.sql)
 *
 * What an administrator observed about a landlord or boarder, as opposed to
 * what the activity log records them doing. Never shown outside the admin
 * panel: the person the note is about does not see it, so the wording stays
 * between administrators.
 *
 * Every read is wrapped against a missing table, as the sign-in counters on
 * admin/user.php are, so a database that has skipped this migration loses the
 * notes card rather than the whole page.
 * ------------------------------------------------------------------------ */

/** Longest note accepted, matching account_notes.body. */
const ACCOUNT_NOTE_MAX = 1000;

/**
 * Notes on one account, newest first, with each author's name and photo so
 * the card can draw them the same way every other person is drawn.
 */
function account_notes($userId, $limit = 50)
{
    global $pdo;
    try {
        $stmt = $pdo->prepare(
            "SELECT n.*, CONCAT(u.first_name, ' ', u.last_name) AS admin_name, u.avatar_path
               FROM account_notes n
               LEFT JOIN users u ON u.user_id = n.admin_id
              WHERE n.user_id = ?
              ORDER BY n.created_at DESC, n.note_id DESC
              LIMIT " . (int) $limit
        );
        $stmt->execute([(int) $userId]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log('RoomEase: account notes lookup failed - ' . $e->getMessage());
        return [];
    }
}

/**
 * Write a note about $userId as the signed-in administrator. Returns false
 * when the note is empty or the table is missing, so the caller can say so.
 */
function add_account_note($userId, $body)
{
    global $pdo;
    $body = trim((string) $body);
    if ($body === '') {
        return false;
    }
    $body = mb_substr($body, 0, ACCOUNT_NOTE_MAX);

    try {
        $pdo->prepare('INSERT INTO account_notes (user_id, admin_id, body) VALUES (?, ?, ?)')
            ->execute([(int) $userId, (int) $_SESSION['user_id'], $body]);
        return true;
    } catch (PDOException $e) {
        error_log('RoomEase: could not write account note - ' . $e->getMessage());
        return false;
    }
}

/**
 * Delete one note, but only if the signed-in administrator wrote it.
 *
 * Any administrator could have been allowed to delete any note, and on a team
 * this small that would rarely matter. Author-only is the rule because a note
 * is a record of what one person observed: someone else disagreeing with it
 * should add their own note, not quietly remove the first. Returns false when
 * the note is missing or belongs to someone else.
 */
function delete_account_note($noteId)
{
    global $pdo;
    try {
        $stmt = $pdo->prepare('DELETE FROM account_notes WHERE note_id = ? AND admin_id = ?');
        $stmt->execute([(int) $noteId, (int) $_SESSION['user_id']]);
        return $stmt->rowCount() > 0;
    } catch (PDOException $e) {
        error_log('RoomEase: could not delete account note - ' . $e->getMessage());
        return false;
    }
}

/**
 * The decisions an administrator made on a landlord's listings in the last
 * $days days, newest first. Removed listings are included: telling the
 * landlord why a listing disappeared is the point.
 */
function landlord_recent_decisions($landlordId, $days = 30, $limit = 5)
{
    global $pdo;
    try {
        $stmt = $pdo->prepare(
            "SELECT a.action, a.detail, a.created_at, bh.boarding_house_id, bh.name, bh.deleted_at
               FROM admin_actions a
               JOIN boarding_houses bh ON bh.boarding_house_id = a.target_id
              WHERE a.target_type = 'listing' AND bh.landlord_id = ?
                AND a.action IN ('listing_approve', 'listing_reject', 'listing_remove', 'listing_restore')
                AND a.created_at > NOW() - INTERVAL " . (int) $days . " DAY
              ORDER BY a.created_at DESC, a.action_id DESC
              LIMIT " . (int) $limit
        );
        $stmt->execute([(int) $landlordId]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

/** Where the activity log links an entry to, or null for an export. */
function admin_target_url($targetType, $targetId)
{
    if ($targetId === null) {
        return null;
    }
    switch ($targetType) {
        case 'listing':
            return base_url('admin/listing.php?id=' . (int) $targetId);
        case 'user':
            return base_url('admin/user.php?id=' . (int) $targetId);
        default:
            return null;
    }
}

/**
 * The database's clock, as 'Y-m-d H:i:s', read once per request.
 *
 * PHP here runs on UTC (date.timezone in php.ini) while MySQL runs on the
 * machine's local time, eight hours ahead in the Philippines. A timestamp read
 * from the database is therefore only ever compared with this, never with
 * time(): mixing the two made an action taken a minute ago read "just now" for
 * eight hours, and put late-evening sign-ups on the wrong day.
 */
function db_now()
{
    global $pdo;
    static $now = null;
    if ($now === null) {
        try {
            $now = (string) $pdo->query('SELECT NOW()')->fetchColumn();
        } catch (PDOException $e) {
            $now = date('Y-m-d H:i:s');
        }
    }
    return $now;
}

/** "3 days ago", "just now": how long ago a database timestamp was. */
function time_ago($datetime)
{
    $seconds = max(0, strtotime(db_now()) - strtotime((string) $datetime));
    if ($seconds < 60) {
        return 'just now';
    }
    foreach ([['day', 86400], ['hour', 3600], ['minute', 60]] as [$unit, $size]) {
        if ($seconds >= $size) {
            $n = (int) floor($seconds / $size);
            return $n . ' ' . $unit . ($n === 1 ? '' : 's') . ' ago';
        }
    }
    return 'just now';
}

/** An absolute URL to a page of this site, which is what an email link needs. */
function absolute_url($path)
{
    $scheme = is_https_request() ? 'https' : 'http';
    return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . base_url($path);
}

/**
 * Email a landlord about an administrator's decision on one of their listings.
 * Returns true if Gmail accepted it and false if sending failed. Returns null,
 * without trying, when the landlord's account is deactivated or removed.
 */
function notify_landlord_of_decision($listingId, $action, $reason = null)
{
    global $pdo;

    $stmt = $pdo->prepare(
        'SELECT bh.name, bh.moderation_status, u.email, u.first_name, u.is_active, u.deleted_at
           FROM boarding_houses bh
           JOIN users u ON u.user_id = bh.landlord_id
          WHERE bh.boarding_house_id = ?'
    );
    $stmt->execute([(int) $listingId]);
    $row = $stmt->fetch();
    if (!$row || (int) $row['is_active'] !== 1 || $row['deleted_at'] !== null) {
        return null;
    }

    $name = '"' . $row['name'] . '"';
    $reason = trim((string) $reason);

    switch ($action) {
        case 'listing_approve':
            $subject = 'Your listing is approved';
            $paragraphs = [$name . ' is approved. Boarders can now find it on RoomEase.'];
            break;
        case 'listing_reject':
            $subject = 'Your listing needs changes';
            $paragraphs = [
                $name . ' was not approved yet.',
                'What to change: ' . $reason,
                'Edit the listing from your dashboard. When you save your changes, it goes back for review.',
            ];
            break;
        case 'listing_remove':
            $subject = 'Your listing was removed';
            $paragraphs = array_values(array_filter([
                'An administrator removed ' . $name . ' from RoomEase, so boarders can no longer see it.',
                $reason !== '' ? 'Reason: ' . $reason : null,
                'If you think this is a mistake, contact the RoomEase administrator.',
            ]));
            break;
        case 'listing_restore':
            $subject = 'Your listing is back';
            $paragraphs = [
                $name . ' was restored and is on your dashboard again.'
                . ($row['moderation_status'] === 'approved' ? ' Boarders can see it again.' : ''),
            ];
            break;
        default:
            return false;
    }

    $dashboard = absolute_url('landlord/dashboard.php');

    $text = 'Hi ' . $row['first_name'] . ",\r\n\r\n"
        . implode("\r\n\r\n", $paragraphs) . "\r\n\r\n"
        . 'Your dashboard: ' . $dashboard . "\r\n\r\n"
        . "RoomEase\r\n";

    $html = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"></head>'
        . '<body style="margin:0;padding:32px 16px;background:#FAF8F3;">'
        . '<div style="max-width:480px;margin:0 auto;font-family:\'IBM Plex Sans\',\'Segoe UI\',Helvetica,Arial,sans-serif;'
        . 'font-size:15px;line-height:1.6;color:#1F2A28;">'
        . '<p style="margin:0 0 28px;font-family:Georgia,\'Times New Roman\',serif;font-size:20px;color:#184A3F;">RoomEase</p>'
        . '<p style="margin:0 0 16px;">Hi ' . h($row['first_name']) . ',</p>';
    foreach ($paragraphs as $paragraph) {
        $html .= '<p style="margin:0 0 16px;">' . h($paragraph) . '</p>';
    }
    $html .= '<p style="margin:24px 0 0;"><a href="' . h($dashboard) . '" style="display:inline-block;padding:10px 18px;'
        . 'border-radius:8px;background:#184A3F;color:#FFFFFF;text-decoration:none;font-weight:600;">Open your dashboard</a></p>'
        . '</div></body></html>';

    return send_mail($row['email'], $subject, $text, $html);
}

/**
 * Listings waiting for an administrator's decision. A listing whose landlord
 * is removed or deactivated is not counted: it cannot be approved until the
 * account is back, so it is not waiting on anyone.
 */
function pending_listing_count()
{
    global $pdo;
    try {
        return (int) $pdo->query(
            "SELECT COUNT(*) FROM boarding_houses bh
               JOIN users u ON u.user_id = bh.landlord_id AND u.is_active = 1 AND u.deleted_at IS NULL
              WHERE bh.moderation_status = 'pending' AND bh.deleted_at IS NULL"
        )->fetchColumn();
    } catch (PDOException $e) {
        error_log('RoomEase: pending listing count failed - ' . $e->getMessage());
        return 0;
    }
}

/**
 * What is still waiting for a decision once $exceptId is set aside, so an
 * administrator can go from one review straight to the next.
 *
 * Returns ['count' => int, 'next' => ['boarding_house_id' => int, 'name' =>
 * string]|null]. Ordered oldest change first, matching the dashboard's queue
 * and the sidebar's count, so "next" is genuinely the one that has waited
 * longest rather than whichever the database happened to return.
 *
 * $exceptId is excluded whatever its state: called before a decision it skips
 * the listing being looked at, and called after one it skips a listing whose
 * new state may not have landed in this connection's view yet.
 */
function pending_queue_after($exceptId = 0)
{
    global $pdo;
    $empty = ['count' => 0, 'next' => null];
    try {
        $stmt = $pdo->prepare(
            "SELECT bh.boarding_house_id, bh.name
               FROM boarding_houses bh
               JOIN users u ON u.user_id = bh.landlord_id AND u.is_active = 1 AND u.deleted_at IS NULL
              WHERE bh.moderation_status = 'pending' AND bh.deleted_at IS NULL
                AND bh.boarding_house_id <> ?
              ORDER BY bh.updated_at ASC"
        );
        $stmt->execute([(int) $exceptId]);
        $rows = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log('RoomEase: pending queue lookup failed - ' . $e->getMessage());
        return $empty;
    }

    return ['count' => count($rows), 'next' => $rows[0] ?? null];
}

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

/* ---------------------------------------------------------------------------
 * Password reset by emailed code (database/boardinghouse.sql)
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
    $setting = $_ENV['ROOMEASE_SHOW_RESET_CODES'] ?? getenv('ROOMEASE_SHOW_RESET_CODES');
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
        return 'auth/profile.php';
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

/* ---------------------------------------------------------------------------
 * Site settings (database/boardinghouse.sql)
 * ------------------------------------------------------------------------ */

/**
 * One administrator-controlled setting, or $default. All settings are read in
 * a single query the first time any is asked for. If the table has not been
 * created yet every setting simply reads as its default.
 */
function site_setting($key, $default = null, $reload = false)
{
    global $pdo;
    static $settings = null;

    if ($settings === null || $reload) {
        $settings = [];
        if (isset($pdo) && $pdo instanceof PDO) {
            try {
                $settings = $pdo->query('SELECT setting_key, setting_value FROM site_settings')
                    ->fetchAll(PDO::FETCH_KEY_PAIR);
            } catch (PDOException $e) {
                $settings = [];
            }
        }
    }

    return array_key_exists($key, $settings) && $settings[$key] !== null ? $settings[$key] : $default;
}

/** Save settings as key => value. A null value deletes the setting. */
function save_site_settings(array $values)
{
    global $pdo;
    $upsert = $pdo->prepare(
        'INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );
    $delete = $pdo->prepare('DELETE FROM site_settings WHERE setting_key = ?');

    foreach ($values as $key => $value) {
        if ($value === null) {
            $delete->execute([$key]);
        } else {
            $upsert->execute([$key, (string) $value]);
        }
    }
    site_setting('', null, true);
}

/** The sign-in pages' default background. */
const AUTH_BACKGROUND_DEFAULT = '#FAF8F3';

/** Where an uploaded sign-in background lives, relative to the app root. */
const SITE_UPLOAD_DIR = 'assets/uploads/site';

/** True for a path this app wrote into SITE_UPLOAD_DIR, and nothing else. */
function is_site_upload_path($path)
{
    return is_string($path)
        && preg_match('#^assets/uploads/site/auth-bg-[a-f0-9]{16}\.(jpg|png|webp)$#', $path) === 1;
}

/**
 * The sign-in pages' background, chosen in admin/appearance.php.
 *
 * Returns ['style' => inline CSS for <body>, 'tone' => 'light'|'dark'].
 * "dark" means the brand and the links around the card are drawn in white.
 * A photo always gets a forest tint and dark tone so the text above the card
 * stays readable whatever the photo is.
 */
function auth_background()
{
    $type = site_setting('auth_background_type', 'colour');
    $image = site_setting('auth_background_image');

    if ($type === 'photo' && is_site_upload_path($image) && is_file(__DIR__ . '/../../' . $image)) {
        return [
            'style' => "background-image: linear-gradient(rgba(15, 58, 49, .55), rgba(15, 58, 49, .72)), url('"
                . base_url($image) . "');",
            'tone' => 'dark',
        ];
    }

    $colour = site_setting('auth_background_colour', AUTH_BACKGROUND_DEFAULT);
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', (string) $colour)) {
        $colour = AUTH_BACKGROUND_DEFAULT;
    }

    return [
        'style' => 'background-color: ' . $colour . ';',
        'tone' => colour_prefers_light_text($colour) ? 'dark' : 'light',
    ];
}

/**
 * True when white text reads better than forest-green text on a colour,
 * compared by WCAG contrast ratio.
 */
function colour_prefers_light_text($hex)
{
    $channel = function ($c) {
        $c = $c / 255;
        return $c <= 0.03928 ? $c / 12.92 : pow(($c + 0.055) / 1.055, 2.4);
    };
    $luminance = function ($hex) use ($channel) {
        return 0.2126 * $channel(hexdec(substr($hex, 1, 2)))
            + 0.7152 * $channel(hexdec(substr($hex, 3, 2)))
            + 0.0722 * $channel(hexdec(substr($hex, 5, 2)));
    };

    $bg = $luminance($hex);
    $againstWhite = 1.05 / ($bg + 0.05);
    $forest = $luminance('#184A3F');
    $againstForest = (max($bg, $forest) + 0.05) / (min($bg, $forest) + 0.05);

    return $againstWhite > $againstForest;
}

/**
 * Applied last, once every helper above exists: age the session out, and
 * re-check the account behind it against the database on every request.
 */
enforce_session_policy();
