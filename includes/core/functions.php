<?php
/**
 * Startup file. Every page requires this one line and gets the whole toolkit.
 *
 * Where things live (all in includes/core/):
 *   helpers.php         escaping, redirects, URLs, flash messages, dates, money
 *   auth.php            who is signed in, role checks, return-after-login
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

// Settings read from .env or the environment.
require_once __DIR__ . "/env.php";

// Session cookie flags can only be chosen before the session exists, so the
// hardening file is loaded and applied first. It also sends the response
// security headers, and defines the login/reset throttle helpers.
require_once __DIR__ . "/security.php";

// Outgoing email through Gmail, used for password reset codes.
require_once __DIR__ . "/mailer.php";

require_once __DIR__ . "/helpers.php";
require_once __DIR__ . "/auth.php";
require_once __DIR__ . "/csrf.php";
require_once __DIR__ . "/validation.php";
require_once __DIR__ . "/uploads.php";
require_once __DIR__ . "/avatars.php";
require_once __DIR__ . "/lookups.php";
require_once __DIR__ . "/listings.php";
require_once __DIR__ . "/rooms.php";
require_once __DIR__ . "/audit.php";
require_once __DIR__ . "/password_reset.php";
require_once __DIR__ . "/site_settings.php";

// Profile photos are drawn on nearly every page of both the panel and the
// public site, so the one renderer is loaded here rather than page by page.
require_once __DIR__ . "/../components/avatar.php";

configure_session_security();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

send_security_headers();

// Applied last, once every helper above exists: age the session out, and
// re-check the account behind it against the database on every request.
enforce_session_policy();
