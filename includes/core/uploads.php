<?php
/**
 * Photo upload limits and saving listing photos to assets/uploads/.
 */

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
