<?php
/**
 * Administrator-controlled site settings, such as the sign-in page background.
 */

/* ---------------------------------------------------------------------------
 * Site settings (database/roomease.sql)
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
        'INSERT INTO site_settings (setting_key, setting_value, created_by, updated_by) VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)'
    );
    $delete = $pdo->prepare('DELETE FROM site_settings WHERE setting_key = ?');

    foreach ($values as $key => $value) {
        if ($value === null) {
            $delete->execute([$key]);
        } else {
            $upsert->execute([$key, (string) $value, current_user_id(), current_user_id()]);
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
