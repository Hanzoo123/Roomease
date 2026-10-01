<?php
/**
 * Startup file. Every web page begins with this one line and gets the whole
 * toolkit, in the right order:
 *
 *     require __DIR__ . '/../includes/init.php';   // pages in a subfolder
 *     require __DIR__ . '/includes/init.php';      // index.php and 404.php
 *
 * The order is fixed here so no page has to remember it. The database comes
 * first because the sign-in check at the bottom reads $pdo.
 *
 * Where things live (includes/core/):
 *   helpers.php         escaping, redirects, URLs, flash messages, dates, money
 *   auth.php            who is signed in, role checks, return-after-login, administrator usernames
 *   csrf.php            form tokens
 *   validation.php      checks for typed values
 *   uploads.php         listing photo uploads
 *   avatars.php         profile photos
 *   lookups.php         amenities, utilities, room types
 *   listings.php        listing queries, availability, browse filters, stay terms
 *   rooms.php           rooms inside a listing
 *   audit.php           the audit log and landlord decision notices
 *   password_reset.php  reset by emailed code
 *   site_settings.php   administrator-controlled site settings
 *   security.php        sessions, headers, sign-in throttling (loaded first)
 *   mailer.php          outgoing email
 *
 * Add new helpers to the file whose topic they belong to, not here.
 */

// The database connection ($pdo). It also loads the project's .env.
require_once __DIR__ . '/../config/db.php';

// Settings read from .env or the environment.
require_once __DIR__ . '/core/env.php';

// Session cookie flags can only be chosen before the session exists, so the
// hardening file is loaded and applied first. It also sends the response
// security headers, and defines the login/reset throttle helpers.
require_once __DIR__ . '/core/security.php';

// Outgoing email through Gmail, used for password reset codes.
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

// Profile photos are drawn on nearly every page of both the panel and the
// public site, so the one renderer is loaded here rather than page by page.
require_once __DIR__ . '/components/avatar.php';

// An exception no page caught ends on a "something went wrong" page instead of
// a blank one, and its details go to the error log (includes/core/helpers.php).
set_exception_handler('handle_uncaught_exception');

configure_session_security();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

send_security_headers();

// Applied last, once every helper above exists: age the session out, and
// re-check the account behind it against the database on every request.
enforce_session_policy();
