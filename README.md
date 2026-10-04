# RoomEase — Web-Based Boarding House Information and Listing System

A working PHP + MySQL implementation matching the project proposal: three
roles (Administrator, Landlord, Prospective Boarder), full listing CRUD,
photo uploads, search/filter, and account management.

## Requirements

- PHP 8.0+ with the `pdo_mysql` and `fileinfo` extensions (both are enabled
  by default in XAMPP/WAMP/MAMP).
- MySQL or MariaDB.
- A local server stack: **WAMP** or **XAMPP**. The steps below work for
  either; only the webroot path differs.

These steps set up a copy on your own machine.

## Setup

1. **Copy the project** into your webroot: `C:\wamp64\www\roomease` for WAMP,
   `C:\xampp\htdocs\roomease` for XAMPP on Windows, or
   `/Applications/XAMPP/htdocs/roomease` on Mac.

2. **Start Apache and MySQL** from the WAMP or XAMPP control panel.

3. **Create the database.** Open phpMyAdmin (`http://localhost/phpmyadmin`),
   click **Import**, and select `database/roomease.sql`. This creates the
   `roomease` database, every table the application uses, the
   amenity/utility/room-type lookup rows, and one administrator account
   (see step 5).

   Alternatively, from a terminal:
   ```
   mysql -u root -p < database/roomease.sql
   ```

   This one file is the whole database. It drops and recreates every table
   before filling it, so importing it over an existing copy destroys whatever
   was there.

   **Set RoomEase up before?** If `database/roomease.sql` has changed since you
   last imported it, drop your old `roomease` database in phpMyAdmin and import
   the file again, so your tables match the code. Your local test accounts and
   listings go with it.

4. **Set up the optional features.** Nothing here touches the database —
   step 3 already created every table, including brute-force throttling,
   "Remember me", room photos, stay terms and the Appearance page. These are
   the two features that need credentials of their own.

   **First, install the PHP libraries.** Both features read their settings
   from the `.env` file, and `.env` is read by a library in `vendor/`, which
   git ignores. In the project folder, run:
   ```
   composer install
   ```
   (Get Composer from https://getcomposer.org, or copy the `vendor/` folder
   from a teammate who has it.) Without `vendor/` the site still runs on the
   database defaults, but `.env` is never read: reset codes are not emailed
   and "Continue with Google" says it is not set up, even when `.env` has the
   right values.

   The listing map and the landlord's pin picker draw OpenStreetMap tiles, so
   they need an internet connection. Offline, the rest of the listing page
   works and the map says it could not load.

   **Optional: "Continue with Google".** The button always shows on the log
   in and sign up pages; until credentials are set, clicking it says Google
   sign-in is not set up yet. To set them up:
   1. In Google Cloud console, create a project and set up the OAuth consent
      screen (External). While it is in *Testing*, only the Google accounts
      added under **Test users** can sign in, so add every account you will
      use.
   2. Create an OAuth client ID of type *Web application* with the callback as
      an authorized redirect URI, for example
      `http://localhost/roomease/auth/google_callback.php`. It must match the
      address the site is opened with exactly: `127.0.0.1` instead of
      `localhost`, or a renamed folder, needs its own entry.
   3. Put the client ID and secret in the project's `.env` as
      `ROOMEASE_GOOGLE_CLIENT_ID` and `ROOMEASE_GOOGLE_CLIENT_SECRET`
      (`.env.example` lists them, and git ignores `.env`). The same two names
      work as real environment variables on a deployed copy, and
      `config/google.local.php` still works as before; `.env` wins if both
      are set.

   Someone new who continues with Google from the sign-up page gets the role
   picked there. From the log-in page they are asked "One more step": boarder
   or landlord, and an optional phone number. An account made with Google has
   no password; its owner can set one from their profile, which emails them a
   code. Google sign-in needs internet; email and password login does not.

   **Password reset codes by email.** "Forgot password?" emails a 6-digit
   code through Gmail. Give RoomEase a Gmail account to send from:
   1. On that Google account, turn on **2-Step Verification**
      (<https://myaccount.google.com/security>).
   2. Create an **App Password** named "RoomEase"
      (<https://myaccount.google.com/apppasswords>). Gmail does not accept the
      account's normal password here.
   3. Put the Gmail address and the 16-letter App Password in the project's
      `.env` as `ROOMEASE_MAIL_USERNAME` and `ROOMEASE_MAIL_PASSWORD`
      (`.env.example` lists them, and git ignores `.env`). The same two names
      work as real environment variables on a deployed copy, and
      `config/mail.local.php` still works as before; `.env` wins if both are
      set. On a network that blocks Gmail's port 465, add
      `ROOMEASE_MAIL_PORT=587`, which connects first and starts TLS with
      STARTTLS instead.

   Until that is done no email is sent, and someone browsing from the server
   itself sees the code on screen instead. Every send is logged to
   `storage/mail.log`, with Gmail's reason when one fails.

5. **Set the administrator password.** `roomease.sql` creates one
   administrator, `admin@roomease.com`, with a password nobody knows. Set your
   own from a terminal in the project folder:

   ```
   php database/set_admin_password.php "YourStrongPassword"
   ```

   The script is command-line only, requires at least 8 characters, and
   re-reads the stored hash afterwards to prove the new password actually
   works before it reports success. Then sign in at
   `http://localhost/roomease/admin/login.php`.

6. **Check the database config.** Open `config/db.php`. The defaults
   (`localhost` / `root` / no password) match a stock XAMPP install. Set the
   `ROOMEASE_DB_HOST`, `ROOMEASE_DB_NAME`, `ROOMEASE_DB_USER` and
   `ROOMEASE_DB_PASS` environment variables to override them without editing
   the file, which is what a real deployment should do.

7. **Visit the site.** Go to `http://localhost/roomease/` in your browser.

   The `.htaccess` files need `AllowOverride All` for this directory, which is
   the WAMP and XAMPP default. To confirm they are active, request
   `http://localhost/roomease/config/db.php` — it must return **403**, not a
   blank page. A blank page means Apache is ignoring `.htaccess` and the
   `config/`, `database/`, `includes/` and `storage/` folders are readable
   over HTTP.

## Accounts

| Account | Email | Password | Sign in at |
|---------|-------|----------|------------|
| Administrator | `admin@roomease.com` | set by you in step 5 | `/admin/login.php` |

Landlords and boarders sign up on the site itself, at `/auth/register.php`.

Administrators and everyone else sign in separately. The public login at
`/auth/login.php` does not accept administrator accounts, and
`/admin/login.php` accepts only administrator accounts; either answers a wrong
kind of account with the same "Invalid email or password" as a wrong
password. Going to `/admin/` while signed out opens the admin sign-in page.
Administrators reset a forgotten password from the "Forgot password?" link on
that page, not from the public one.

There is no default administrator password any more. Earlier versions shipped
one and printed it here, which meant every copy of this repository told a
reader how to sign in as an administrator on any deployment where it had not
been changed.

## Folder structure

```
roomease/
├── index.php                  Home page: search, newest listings
├── 404.php                    Shown for an address that matches nothing
├── legal/                     The standing pages every visitor can read:
│                              about, contact, terms, privacy. A page added
│                              here must also be named in base_url() and
│                              app_cookie_path(), which find the app root
│                              by folder
├── admin/                     Admin panel: dashboard, manage users, manage listings
├── auth/                      Register, login, logout, profile, password reset by code
├── landlord/                  Dashboard, add/edit/delete listing, photo actions
├── boarder/                   Browse/search listings, listing detail, saved listings
├── config/db.php              Database connection (PDO)
├── includes/                  Shared code; never served over HTTP
│   ├── init.php                 Startup: every page requires this one file
│   ├── core/                    Logic loaded by init.php, no HTML
│   │   ├── helpers.php            Escaping, redirects, URLs, flash messages, dates, money
│   │   ├── auth.php               Who is signed in, role checks, return-after-login, admin usernames
│   │   ├── csrf.php               Form tokens
│   │   ├── validation.php         Checks for typed values
│   │   ├── uploads.php            Listing photo uploads
│   │   ├── avatars.php            Profile photos
│   │   ├── lookups.php            Amenities, utilities, room types
│   │   ├── listings.php           Listing queries, availability, browse filters, stay terms
│   │   ├── rooms.php              Rooms inside a listing
│   │   ├── audit.php              Audit log and landlord decision notices
│   │   ├── password_reset.php     Reset by emailed code
│   │   ├── site_settings.php      Administrator-controlled site settings
│   │   ├── security.php           Session hardening, headers, login/reset throttling
│   │   ├── mailer.php             Sends email through Gmail (reset codes)
│   │   ├── google_auth.php        "Continue with Google"
│   │   └── env.php                Reads a setting from .env or the environment
│   ├── layouts/                 The outer shell of each kind of page
│   │   ├── header.php / footer.php            Public theme (guests and boarders)
│   │   ├── auth_header.php / auth_footer.php  Sign-in pages
│   │   └── panel*.php                         AdminLTE panel shell (admin and landlord)
│   ├── components/              Pieces placed inside pages: listing card,
│   │                            browse filter panel, listing form, room rows, icons,
│   │                            avatar (one renderer for every profile photo),
│   │                            head_meta (icons and link-preview tags)
│   └── scripts/                 PHP files that print a <script> block: password
│                                toggle, save heart, copy number, show more, room buttons
├── assets/css/style.css       Public theme styling
├── assets/img/                The RoomEase logo, the favicons cut from it,
│                              and og-default.png for link previews. hero.webp
│                              (or hero.jpg) is the home page's photo, cut on a
│                              diagonal beside the headline. The one there now is a
│                              temporary stand-in for a real photo of Baybay City.
│                              room-types/ holds one photo per room type for the
│                              home page's tiles, named after the type
│                              (bed-spacer.webp); pages/ holds the About and Contact
│                              photos. Both sets come from the RoomEase Figma file.
├── assets/adminlte/           AdminLTE theme for the management panel
├── assets/uploads/            Uploaded photos: listings (a folder each),
│                              profile photos in avatars/, site/ for the
│                              sign-in background. PHP is off in this folder.
└── database/
    ├── roomease.sql             The whole database: every table, lookup data, one admin
    └── set_admin_password.php   CLI tool to set the administrator password
```

Both role panels render from the same `includes/layouts/panel*.php` shell,
configured per role in `includes/layouts/panel.php`. There is exactly one copy of that shell.

## Adding a page

Every page starts with the same one line, which connects the database, loads
the helpers and starts the session, in the right order. A page that uses
`$pdo` adds one more line under it:

```php
require_once __DIR__ . '/../config/db.php';
```

That line does nothing when PHP runs: `init.php` has already loaded the file,
and `require_once` skips it. It is there only so VS Code can see where `$pdo`
comes from and stops reporting it as undefined. A page that never uses `$pdo`
does not need it. Then copy the skeleton that matches the kind of page (a page
in the project root uses `/includes/...` and `/config/...` instead of
`/../includes/...` and `/../config/...`).

**Public page** (guests and boarders):

```php
<?php
require __DIR__ . '/../includes/init.php';

$pageTitle = 'My Page';
require __DIR__ . '/../includes/layouts/header.php';
?>
  ...your HTML...
<?php require __DIR__ . '/../includes/layouts/footer.php'; ?>
```

**Panel page** (admin or landlord). Add its link to the menu in
`includes/layouts/panel.php`:

```php
<?php
require __DIR__ . '/../includes/init.php';
require_login('landlord');          // or 'admin'

$pageTitle = 'My Page';
require __DIR__ . '/../includes/layouts/panel_head.php';
require __DIR__ . '/../includes/layouts/panel_navbar.php';
require __DIR__ . '/../includes/layouts/panel_sidebar.php';
?>
  ...your HTML...
<?php require __DIR__ . '/../includes/layouts/panel_footer.php'; ?>
```

**Form handler** (a page that only receives a POST). Every form that posts to
it needs `<?= csrf_field() ?>` inside the `<form>`:

```php
<?php
require __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../config/db.php';
require_login('landlord');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('landlord/dashboard.php');
}
verify_csrf();
```

New helper functions go in the `includes/core/` file that matches their topic
(the list is at the top of `includes/init.php`), not in the page.

## What's implemented (from the project scope)

- Three roles with session-based auth and role-gated pages: Admin,
  Landlord, Prospective Boarder.
- Landlord: create, view, update, delete boarding house listings with
  name, address, rent, reservation fee, room type, capacity, utilities,
  amenities, house rules, contact info, and photos; choose which photo is
  the cover; see each listing's moderation state.
- Boarder: browse and page through approved listings, search by name or
  address, filter by room type, rent range, free slots and amenities in a
  panel beside the results, sort by availability, lowest rent or newest,
  view full listing details, and save listings to a shortlist.
- Admin: view platform stats, approve or reject listings with a reason,
  activate/deactivate user accounts, archive and restore them, and remove
  and restore listings (removal archives, it never deletes). Every decision
  is written to the Activity Log with the administrator and the reason, and
  the landlord is told by email and on their dashboard.
- All roles: edit their profile, upload a profile photo, and change their own
  password. A photo is cropped square and shrunk to 512px on upload, stored
  under `assets/uploads/avatars/`, and drawn wherever that person appears:
  the panel sidebar and account menu, the admin user directory and account
  pages, the activity log, and the landlord block on a public listing.
- Password reset by a 6-digit code emailed through Gmail. Codes are hashed,
  single use, expire after 10 minutes, and stop working after five wrong
  guesses.
- Security: see the section below.

Search matches the listing name and the address as a single string. There is
no separate city or barangay filter, because `address` is stored as one text
field; splitting it is item C1 in the improvement plan. Room type is a
foreign key onto `room_types`, so the filter and the form can never disagree.

## Security

The original build already had the fundamentals: bcrypt password hashing,
prepared statements everywhere with no SQL built by concatenation, a CSRF
token on every form, `h()` escaping on output, and ownership checks written
into the `WHERE` clause so a landlord can only touch their own listings.

On top of that, `includes/core/security.php` is required from the top of
`includes/init.php`, so every page gets the following without having to
ask for it:

**Sessions**

- Cookies are `HttpOnly`, `SameSite=Lax`, `Secure` whenever the request came
  over HTTPS, and scoped to this app's path so a neighbouring app in the same
  webroot cannot read them.
- `session.use_strict_mode` is on, which is what stops session fixation: PHP
  will no longer adopt a session id that a visitor invented.
- Sessions expire after 30 minutes idle and 12 hours absolute, so a browser
  left open on a shared computer stops being a way in.
- The account behind the session is re-read from the database on every
  request. Deactivating a user, deleting them, or changing their role takes
  effect on that user's very next click rather than whenever they log out.
- Signing in starts a genuinely new session, and changing a password issues a
  new session id, which invalidates any copy someone else was holding.
- A password change also signs the account out everywhere else. Each session
  keeps a fingerprint of the stored password hash, and one that no longer
  matches ends on its next request, however the password changed: on the
  profile page, by a reset, by the first Google sign-in to an existing
  account (which replaces the password), or with
  `database/set_admin_password.php`. The pages that change it also forget
  every "Remember me" device. Only the browser that made the change stays
  signed in.

**Rate limiting** (the `login_attempts` table, in `database/roomease.sql`)

- Five failed sign-ins for one email address, or twenty from one IP, pause
  further attempts for fifteen minutes. The per-IP limit is the one that
  catches an attacker trying a single common password across many accounts,
  which no per-account counter would ever notice.
- Password-reset requests are limited to three per address per window, and a
  new code can be requested at most once a minute. The limit is applied
  before the address is looked up, so a throttled requester still learns
  nothing about who is registered.
- A reset code survives five wrong guesses. It is stored with
  `password_hash()`, because a fast hash of a 6-digit number would be guessed
  in moments if the table leaked. The right code is swapped for a token that
  exists only in that visitor's session, so the token never appears in a URL.

**Output and uploads**

- Flash messages can carry text a landlord typed, such as a listing name, and
  they are rendered through Toastr, which parses its message as HTML by
  default. Toastr is now told to escape it, and the listing name is stripped
  of tags before it goes in.
- `assets/uploads/.htaccess` takes PHP off the upload folder and refuses to
  serve any script extension, so an uploaded file cannot become code. The
  upload handler already forced the extension from the detected MIME type;
  this is the second lock.
- Filenames coming from the browser are cleaned before they appear in any
  error message shown back to the user.

**Everything else**

- `Content-Security-Policy`, `X-Content-Type-Options`, `X-Frame-Options`,
  `Referrer-Policy` and `Permissions-Policy` on every response, plus HSTS
  over HTTPS.
- `config/`, `database/`, `includes/` and `storage/` are unreachable over
  HTTP, as are `.git` directories, `.sql` and `.log` files, and dotfiles.
- A failed database connection logs the driver message and shows the visitor
  a generic 503, instead of printing the host, database name and user to the
  page.

### Still worth doing

- **Do not run as MySQL `root` with an empty password** outside local
  development. Create a user with rights on the `roomease` database only, and
  pass it in through the `ROOMEASE_DB_*` environment variables.
- **Throttle registration.** Sign-in and password reset are rate limited;
  registration is not, so a script can still create accounts without limit.
- **Drop `'unsafe-inline'` from the script CSP.** The policy currently allows
  it, which permits exactly the kind of inline script an injected payload
  would use. The inline block in `includes/layouts/panel_footer.php` is what makes it
  necessary; moving that into a `.js` file or giving it a nonce would let the
  directive be tightened.
- **Rehash on login.** Calling `password_needs_rehash()` after a successful
  `password_verify()` would let the bcrypt cost be raised later without
  locking anyone out.
- Serve the site over HTTPS, at which point the session cookie becomes
  `Secure` and HSTS switches on by itself.

## Out of scope (per the project's stated limitations)

Online reservations, online payments, real-time messaging, interactive
maps/GPS, reviews/ratings, and automatic notifications are intentionally
not included, matching the "Limitations of the Study" section of the
project document.

## Suggested next steps

Parts B and C of `RoomEase_Improvement_Plan.docx` cover these in full. The
highest-value items:

- **Add a landlord inquiry inbox.** Today a boarder finds a listing, reads the
  landlord's number, and leaves the system entirely; nothing records that it
  happened. A stored message with a status field stays inside the project's
  stated limitations, unlike real-time chat.
- **Record who moderated a listing.** `boarding_houses` has `moderated_at` but
  no `moderated_by`, so "who approved this?" cannot be answered.
- **Page the admin tables server-side.** `admin/manage_listings.php` reads
  every listing into PHP and counts the array in memory.
- **Add sort options to browse** — price ascending, price descending, newest.
