<?php
/**
 * Photo upload limits and saving listing photos to assets/uploads/.
 *
 * Used by: Add/Edit Listing, landlord/room_form.php, rooms.php and the listing
 * form (listing photos); avatars.php, auth/edit_profile.php and
 * admin/appearance.php (upload size limits).
 */

/** An uploaded file's name, cleaned so it is safe to show in an error message. */

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

/** Largest photo accepted: 5 MB, or PHP's upload limit if that is smaller. */

function max_upload_bytes()
{
    static $bytes = null;
    if ($bytes === null) {
        $bytes = min(5 * 1024 * 1024, ini_bytes(ini_get('upload_max_filesize')));
    }
    return $bytes;
}

/** Most photos per upload. */

function max_photos_per_upload()
{
    return 10;
}

/** Most pixels a photo may have (40 MP). A small file can still need a lot of memory to open. */
const MAX_PHOTO_PIXELS = 40000000;

/** Longest side of a stored listing photo, in pixels. */
const LISTING_PHOTO_MAX_SIDE = 1600;

/** Raise the memory limit for this request, enough to open an image this size. */

function make_room_for_image($width, $height)
{
    $limit = ini_bytes(ini_get('memory_limit'));
    if ($limit <= 0) {
        return; // -1: no limit
    }
    $needed = memory_get_usage() + (int) ($width * $height * 5) + 16 * 1024 * 1024;
    if ($needed > $limit) {
        @ini_set('memory_limit', (string) ceil($needed / (1024 * 1024)) . 'M');
    }
}

/** Open an image with GD, rotated upright. Null if GD can't read it or it is too large. */

function load_image_upright($path, $ext)
{
    if (!function_exists('imagecreatetruecolor')) {
        return null;
    }

    $readers = ['jpg' => 'imagecreatefromjpeg', 'png' => 'imagecreatefrompng', 'webp' => 'imagecreatefromwebp'];
    $reader = $readers[$ext] ?? null;
    if ($reader === null || !function_exists($reader)) {
        return null;
    }

    $size = @getimagesize($path);
    if ($size === false || $size[0] * $size[1] > MAX_PHOTO_PIXELS) {
        return null;
    }
    make_room_for_image($size[0], $size[1]);

    $image = @$reader($path);
    if (!$image) {
        return null;
    }

    // Phones store the rotation in EXIF instead of rotating the photo.
    if ($ext === 'jpg' && function_exists('exif_read_data')) {
        $exif = @exif_read_data($path);
        $orientation = (int) ($exif['Orientation'] ?? 0);
        $angle = [3 => 180, 6 => -90, 8 => 90][$orientation] ?? 0;
        if ($angle !== 0) {
            $rotated = @imagerotate($image, $angle, 0);
            if ($rotated) {
                $image = $rotated;
            }
        }
    }

    return $image;
}

/**
 * Re-save a photo through GD, at most $maxSide pixels on its longest side.
 * This removes EXIF data, which can include where the photo was taken.
 * Returns false (file unchanged) if GD can't read it.
 */

function shrink_photo($path, $ext, $maxSide)
{
    $source = load_image_upright($path, $ext);
    if (!$source) {
        error_log('RoomEase: could not save ' . basename($path) . ' again through GD, so it keeps its metadata.');
        return false;
    }

    $width = imagesx($source);
    $height = imagesy($source);
    $scale = min(1, $maxSide / max($width, $height));
    $newWidth = max(1, (int) round($width * $scale));
    $newHeight = max(1, (int) round($height * $scale));

    $canvas = imagecreatetruecolor($newWidth, $newHeight);
    if ($ext === 'png' || $ext === 'webp') {
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
    }
    imagecopyresampled($canvas, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

    // Write to a temp file first, so a failed write never breaks the photo.
    $writers = ['jpg' => 'imagejpeg', 'png' => 'imagepng', 'webp' => 'imagewebp'];
    $quality = ['jpg' => 85, 'png' => 6, 'webp' => 85];
    $temp = $path . '.tmp';
    if (!@$writers[$ext]($canvas, $temp, $quality[$ext]) || !@rename($temp, $path)) {
        @unlink($temp);
        error_log('RoomEase: could not save ' . basename($path) . ' again through GD, so it keeps its metadata.');
        return false;
    }
    return true;
}

/**
 * True when the upload was bigger than post_max_size. PHP then empties
 * $_POST, which would otherwise look like a CSRF error.
 */

function post_too_large()
{
    return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
        && empty($_POST)
        && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0;
}

/**
 * Save uploaded listing photos and return their paths. All files are checked
 * before any is saved, so one bad file saves none. Throws on a bad file.
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
        // It must really open as an image, not just look like one.
        $dimensions = @getimagesize($tmps[$i]);
        if ($dimensions === false) {
            throw new RuntimeException('"' . safe_filename($name) . '" is not a JPG, PNG, or WEBP image.');
        }
        if ($dimensions[0] * $dimensions[1] > MAX_PHOTO_PIXELS) {
            throw new RuntimeException('"' . safe_filename($name) . '" has too many pixels. Please use a photo under 40 megapixels.');
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
            // All or nothing: remove the ones already saved.
            foreach ($stored as $done) {
                @unlink(__DIR__ . '/../../' . $done);
            }
            throw new RuntimeException('Could not save the uploaded photos.');
        }
        shrink_photo($dir . '/' . $filename, $item['ext'], LISTING_PHOTO_MAX_SIDE);
        $stored[] = 'assets/uploads/boarding_houses/' . $boardingHouseId . '/' . $filename;
    }

    return $stored;
}
