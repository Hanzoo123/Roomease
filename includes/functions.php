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

/** A listing's own switches for being on the public site. */
const LIVE_STATUS_WHERE = "bh.availability_status = 'available' AND bh.moderation_status = 'approved'";

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

/* ---------------------------------------------------------------------------
 * Rooms (database/migration_rooms.sql)
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
          WHERE r.room_id = ? AND bh.landlord_id = ?'
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
          WHERE bh.landlord_id = ?
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
                u.email, u.first_name, u.is_active, u.role
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

/* ---------------------------------------------------------------------------
 * Site settings (database/migration_auth_extras.sql)
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

    if ($type === 'photo' && is_site_upload_path($image) && is_file(__DIR__ . '/../' . $image)) {
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
