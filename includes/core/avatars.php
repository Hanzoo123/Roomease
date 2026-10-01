<?php
/**
 * Profile photos: saving, cropping, deleting and drawing initials as a fallback.
 */

/* ---------------------------------------------------------------------------
 * Photos are saved in assets/uploads/avatars/ (PHP can't run there, see its
 * .htaccess), with the path in users.avatar_path. They are cropped to a 512px
 * square on upload, since they are always shown as circles.
 * ------------------------------------------------------------------------ */

/** Folder for profile photos. */

const AVATAR_UPLOAD_DIR = 'assets/uploads/avatars';

/** Size of a stored profile photo, in pixels. */

const AVATAR_SIZE = 512;

/** True only for a file this app saved in AVATAR_UPLOAD_DIR. */

function is_avatar_path($path)
{
    return is_string($path)
        && preg_match('#^assets/uploads/avatars/av-[0-9]+-[a-f0-9]{16}\.(jpg|png|webp)$#', $path) === 1;
}

/** Initials shown when there is no photo: first letters of the first two words. */

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

/** Crop to a square and shrink to AVATAR_SIZE. False if GD can't (the original is kept). */

function resize_avatar_square($sourcePath, $destPath, $ext)
{
    $source = load_image_upright($sourcePath, $ext);
    if (!$source) {
        return false;
    }

    $width  = imagesx($source);
    $height = imagesy($source);
    $side   = min($width, $height);
    // Crop a bit above centre, where a face usually is.
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

    // No imagedestroy(): not needed since PHP 8.0, and deprecated in 8.5.
    return (bool) $ok;
}

/**
 * Save an uploaded profile photo and return its path (null if none was sent).
 * The extension comes from the file's real type, never its name. Throws on a bad file.
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

    // Without GD the original is kept; CSS crops it to a circle.
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
