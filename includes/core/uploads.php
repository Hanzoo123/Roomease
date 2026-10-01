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
 * The most pixels a photo may have. A 2 MB file can still unpack to far more
 * memory than PHP is allowed, so the server checks this before opening one.
 * 40 megapixels is well above any phone camera.
 */
const MAX_PHOTO_PIXELS = 40000000;

/** The longest side, in pixels, a listing photo is stored at. */
const LISTING_PHOTO_MAX_SIDE = 1600;

/**
 * Raise PHP's memory limit, for this request only, far enough to open an
 * image of this size. A decoded image takes four bytes a pixel, and shrinking
 * it needs a second, smaller copy beside it.
 */

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

/**
 * Open an uploaded image with GD, turned the right way up. Returns the image,
 * or null when GD is not installed, cannot read the file, or the file is
 * larger than MAX_PHOTO_PIXELS. Used for listing photos and profile photos.
 */

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

    // Phone cameras record the rotation rather than applying it, so a portrait
    // photo arrives lying on its side unless the EXIF tag is honoured.
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
 * Save a stored photo again through GD, no wider or taller than $maxSide.
 *
 * Saving it again matters as much as shrinking it. A phone photo carries EXIF
 * data, often including the GPS position where it was taken, which for a
 * boarding house can be the landlord's own home. GD writes only the pixels,
 * so the copy that is kept carries none of it, and a 4000px photo becomes a
 * file a phone can load quickly.
 *
 * Returns false, leaving the file as it was, when GD is missing or cannot
 * read it; the upload still succeeds, and the error log says what happened.
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

    // Written beside the original and then moved over it, so a write that
    // fails half way never leaves a broken photo behind.
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
        // The same proof profile photos need: it has to open as an image, not
        // only start like one. Checked here, before anything is saved.
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
            // Keep the batch all-or-nothing: undo whatever already landed.
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
