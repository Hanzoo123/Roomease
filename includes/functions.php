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

/**
 * Handle an uploaded property image. Validates type/size and moves it into
 * assets/uploads/boarding_houses/{boarding_house_id}/. Returns the stored relative path,
 * or null if no file was uploaded. Throws on validation failure.
 */
function handle_photo_upload($fileField, $boardingHouseId)
{
    if (empty($_FILES[$fileField]['name'])) {
        return null;
    }
    $file = $_FILES[$fileField];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Photo upload failed. Please try again.');
    }

    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!isset($allowed[$mime])) {
        throw new RuntimeException('Only JPG, PNG, or WEBP photos are allowed.');
    }
    if ($file['size'] > 5 * 1024 * 1024) {
        throw new RuntimeException('Photo must be smaller than 5MB.');
    }

    $dir = __DIR__ . '/../assets/uploads/boarding_houses/' . $boardingHouseId;
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $filename = uniqid('bh_', true) . '.' . $allowed[$mime];
    $destination = $dir . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        throw new RuntimeException('Could not save the uploaded photo.');
    }

    return 'assets/uploads/boarding_houses/' . $boardingHouseId . '/' . $filename;
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
