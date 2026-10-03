<?php
/**
 * Startup file. Every page starts with one line:
 *
 *     require __DIR__ . '/../includes/init.php';   // pages in a subfolder
 *     require __DIR__ . '/includes/init.php';      // index.php and 404.php
 *
 * Where things live (includes/core/):
 *   helpers.php         escaping, redirects, URLs, flash messages, dates, money
 *   auth.php            who is signed in, role checks, admin usernames
 *   csrf.php            form tokens
 *   validation.php      checks for typed values
 *   uploads.php         listing photo uploads
 *   avatars.php         profile photos
 *   lookups.php         amenities, utilities, room types
 *   listings.php        listing queries, availability, browse filters
 *   rooms.php           rooms inside a listing
 *   audit.php           audit log and landlord notices
 *   password_reset.php  reset by emailed code
 *   site_settings.php   settings an admin changes
 *   security.php        sessions, headers, sign-in throttling
 *   mailer.php          outgoing email
 *
 * Put new helpers in the file for their topic, not here.
 */

// Database connection ($pdo). Also loads .env.
require_once __DIR__ . '/../config/db.php';

require_once __DIR__ . '/core/env.php';

// Loaded early: session cookie settings must be set before the session starts.
require_once __DIR__ . '/core/security.php';

require_once __DIR__ . '/core/mailer.php';

require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/csrf.php';
require_once __DIR__ . '/core/validation.php';
require_once __DIR__ . '/core/uploads.php';
require_once __DIR__ . '/core/avatars.php';
require_once __DIR__ . '/core/lookups.php';
require_once __DIR__ . '/core/listings.php';
require_once __DIR__ . '/core/rooms.php';
require_once __DIR__ . '/core/audit.php';
require_once __DIR__ . '/core/password_reset.php';
require_once __DIR__ . '/core/site_settings.php';

// Profile photos appear on almost every page.
require_once __DIR__ . '/components/avatar.php';

// Show a friendly error page instead of a blank one (see helpers.php).
set_exception_handler('handle_uncaught_exception');

configure_session_security();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

send_security_headers();

// Last: time out old sessions and re-check the account on every request.
enforce_session_policy();
