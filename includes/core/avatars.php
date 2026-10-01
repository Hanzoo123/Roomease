<?php
/**
 * Profile photos: saving, cropping, deleting and drawing initials as a fallback.
 */

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
    // Opened the same way as listing photos (includes/core/uploads.php),
    // turned upright from the EXIF tag.
    $source = load_image_upright($sourcePath, $ext);
    if (!$source) {
        return false;
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

    $dimensions = @getimagesize($upload['tmp_name']);
    if (!isset($allowed[$mime]) || $dimensions === false) {
        throw new RuntimeException('Your photo must be a JPG, PNG, or WEBP image.');
    }
    if ($dimensions[0] * $dimensions[1] > MAX_PHOTO_PIXELS) {
        throw new RuntimeException('That photo has too many pixels. Please use one under 40 megapixels.');
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
