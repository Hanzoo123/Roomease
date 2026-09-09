<?php
/**
 * Shared helper functions used across RoomEase.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

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
            throw new RuntimeException('"' . $name . '" is larger than the ' . format_bytes($maxBytes) . ' limit.');
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Upload failed for "' . $name . '". Please try again.');
        }
        if (count($queue) >= $maxFiles) {
            throw new RuntimeException('Please upload at most ' . $maxFiles . ' photos at a time.');
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $tmps[$i]);
        finfo_close($finfo);

        if (!isset($allowed[$mime])) {
            throw new RuntimeException('"' . $name . '" is not a JPG, PNG, or WEBP image.');
        }
        if ($sizes[$i] > $maxBytes) {
            throw new RuntimeException('"' . $name . '" is larger than the ' . format_bytes($maxBytes) . ' limit.');
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

/** Amenity checklist offered on the listing form, fetched from DB. */
function amenity_options()
{
    global $pdo;
    try {
        $stmt = $pdo->query('SELECT amenity_name FROM amenities ORDER BY amenity_id ASC');
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        return [
            'Wi-Fi',
            'Air Conditioning',
            'Private Bathroom',
            'Kitchen Access',
            'Laundry Area',
            'Study Table & Chair',
            'CCTV & 24/7 Security',
            'Refrigerator Access',
            'Gated Compound',
            'Near VSU / Transport Terminal',
        ];
    }
}

/** Utility checklist offered on the listing form, fetched from DB. */
function utility_options()
{
    global $pdo;
    try {
        $stmt = $pdo->query('SELECT utility_id, utility_name FROM utilities ORDER BY utility_id ASC');
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        return [
            ['utility_id' => 1, 'utility_name' => 'Water'],
            ['utility_id' => 2, 'utility_name' => 'Electricity'],
            ['utility_id' => 3, 'utility_name' => 'Trash Collection'],
            ['utility_id' => 4, 'utility_name' => 'Internet / Wi-Fi'],
            ['utility_id' => 5, 'utility_name' => 'Cooking Gas'],
        ];
    }
}

/**
 * Room types offered on the listing form and in the browse filter.
 * Both read from here so the two lists can never drift apart.
 */
function room_type_options()
{
    global $pdo;
    try {
        $stmt = $pdo->query('SELECT room_type_name FROM room_types ORDER BY room_type_id ASC');
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        return [
            'Single Room',
            'Double Sharing',
            'Bed Spacer',
            'Dormitory',
            'Private Room',
        ];
    }
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

/**
 * Render pagination controls, preserving existing URL query filters.
 */
function render_pagination($currentPage, $totalPages)
{
    if ($totalPages <= 1) {
        return;
    }

    $queryParams = $_GET;
    $buildUrl = function ($p) use ($queryParams) {
        $queryParams['page'] = $p;
        return '?' . http_build_query($queryParams);
    };

    echo '<div class="pagination">';
    if ($currentPage > 1) {
        echo '<a href="' . h($buildUrl($currentPage - 1)) . '" class="btn btn-ghost btn-sm">&larr; Prev</a>';
    } else {
        echo '<span class="btn btn-ghost btn-sm disabled">&larr; Prev</span>';
    }

    for ($i = 1; $i <= $totalPages; $i++) {
        $activeClass = ($i === $currentPage) ? 'btn-primary' : 'btn-ghost';
        echo '<a href="' . h($buildUrl($i)) . '" class="btn ' . $activeClass . ' btn-sm">' . $i . '</a>';
    }

    if ($currentPage < $totalPages) {
        echo '<a href="' . h($buildUrl($currentPage + 1)) . '" class="btn btn-ghost btn-sm">Next &rarr;</a>';
    } else {
        echo '<span class="btn btn-ghost btn-sm disabled">Next &rarr;</span>';
    }
    echo '</div>';
}
