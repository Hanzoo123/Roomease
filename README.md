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

## Setup

1. **Copy the project** into your webroot: `C:\wamp64\www\roomease` for WAMP,
   `C:\xampp\htdocs\roomease` for XAMPP on Windows, or
   `/Applications/XAMPP/htdocs/roomease` on Mac.

2. **Start Apache and MySQL** from the WAMP or XAMPP control panel.

3. **Create the database.** Open phpMyAdmin (`http://localhost/phpmyadmin`),
   click **Import**, and select `database/roomease.sql`. This creates the
   `roomease` database, all tables, the amenity/utility/room-type lookup
   rows, and one administrator account with **no usable password** (see
   step 5).

   Alternatively, from a terminal:
   ```
   mysql -u root -p < database/roomease.sql
   ```

4. **Run the login-throttle migration.**

   ```
   mysql -u root -p roomease < database/migration_login_throttle.sql
   ```

   On a **fresh** import of `roomease.sql` this is the only migration that
   adds anything: the base schema already contains favourites, password
   resets, room types, moderation and the reservation fee. Without it the app
   still runs, but brute-force protection is off and it says so in the PHP
   error log.

   The other `migration_*.sql` files are for upgrading a database created
   before those features existed. Importing them into a fresh database is
   harmless but not needed — `migration_moderation.sql` and
   `migration_reservation_fee.sql` will report `Duplicate column name`,
   which simply means the column is already there.

   If you are upgrading a database created before these passes, also run
   `migration_indexes.sql`, `migration_soft_delete.sql` and
   `migration_room_type_fk.sql`; a fresh import already has all three.

   Upgrading a database created before listings had stay terms (curfew,
   security deposit, minimum stay, payment methods, who can stay, visitor /
   pet / cooking rules) and a map pin? Run this too; it is safe to run twice:
   ```
   mysql -u root -p roomease < database/migration_stay_terms.sql
   ```
   The listing map and the landlord's pin picker draw OpenStreetMap tiles, so
   they need an internet connection. Offline, the rest of the listing page
   works and the map says it could not load.

   **Rooms, room photos, occupancy, and landlord utilities.** A boarding house
   now has rooms, each with its own type, rent, capacity, slots taken, and
   photos, and landlords can add their own utilities and amenities. Upgrading
   an existing database? Run this; it is safe to run twice, and it gives every
   existing listing a "Room 1" copied from its old rent, room type and capacity:
   ```
   mysql -u root -p roomease < database/migration_rooms.sql
   ```
   The old house-level rent, room type and capacity columns are left in place
   and no longer used. Once every listing's rooms look right on the website,
   remove them (this cannot be undone without a backup):
   ```
   mysql -u root -p roomease < database/migration_rooms_cleanup.sql
   ```
   A fresh import of `roomease.sql` already has rooms and none of the old
   columns. The demo seed files add a few listings with several rooms and some
   tenants, so the rooms section and "Fully occupied" badges have something to
   show.

   For "Remember me", Google sign-in, and the administrator's Appearance page
   (the sign-in pages' background), run this as well; it is safe to run twice:
   ```
   mysql -u root -p roomease < database/migration_auth_extras.sql
   ```

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
   3. Copy `config/google.local.example.php` to `config/google.local.php`
      (ignored by git) and fill in the client ID and secret, or set the
      `ROOMEASE_GOOGLE_CLIENT_ID` and `ROOMEASE_GOOGLE_CLIENT_SECRET`
      environment variables.

   Someone new who continues with Google from the sign-up page gets the role
   picked there. From the log-in page they are asked "One more step": boarder
   or landlord, and an optional phone number. An account made with Google has
   no password; its owner can set one from their profile, which emails them a
   code. Google sign-in needs internet; email and password login does not.

   **Password reset codes by email.** "Forgot password?" emails a 6-digit code
   through Gmail. Upgrading an existing database? Run this; it is safe to run
   twice:
   ```
   mysql -u root -p roomease < database/migration_reset_codes.sql
   ```
   Then give RoomEase a Gmail account to send from:
   1. On that Google account, turn on **2-Step Verification**
      (<https://myaccount.google.com/security>).
   2. Create an **App Password** named "RoomEase"
      (<https://myaccount.google.com/apppasswords>). Gmail does not accept the
      account's normal password here.
   3. Copy `config/mail.local.example.php` to `config/mail.local.php` (ignored
      by git) and fill in the Gmail address and the 16-letter App Password, or
      set the `ROOMEASE_MAIL_USERNAME` and `ROOMEASE_MAIL_PASSWORD`
      environment variables.

   Until that is done no email is sent, and someone browsing from the server
   itself sees the code on screen instead. Every send is logged to
   `storage/mail.log`, with Gmail's reason when one fails.

   **Admin tools.** Removing a listing archives it instead of deleting it, the
   Activity Log records every administrator decision, and landlords are told
   about approvals and rejections. Upgrading an existing database? Run this; it
   is safe to run twice:
   ```
   mysql -u root -p roomease < database/migration_admin_tools.sql
   ```
   Without it the admin listing pages fail, because they filter on the new
   `deleted_at` column.

5. **Set the administrator password.** The schema seeds the admin account with
   a placeholder that no password can ever match, so the account cannot be
   signed into until you choose one:

   ```
   php database/set_admin_password.php "YourStrongPassword"
   ```

   The script is command-line only, requires at least 8 characters, and
   re-reads the stored hash afterwards to prove the new password actually
   works before it reports success. Sign in at
   `http://localhost/roomease/admin/login.php` with `admin@roomease.local` and
   the password you just set.

6. **(Optional) Load the demo data.** For a walkthrough or a defence demo:

   ```
   mysql -u root -p roomease < database/seed_demo.sql
   ```

   This adds a demo landlord and a demo boarder (both with the password
   `Password@123`) and three sample Baybay City listings. It is safe to run
   more than once. **Do not import it anywhere reachable from the internet** —
   that password is published in this repository.

7. **Check the database config.** Open `config/db.php`. The defaults
   (`localhost` / `root` / no password) match a stock XAMPP install. Set the
   `ROOMEASE_DB_HOST`, `ROOMEASE_DB_NAME`, `ROOMEASE_DB_USER` and
   `ROOMEASE_DB_PASS` environment variables to override them without editing
   the file, which is what a real deployment should do.

8. **Visit the site.** Go to `http://localhost/roomease/` in your browser.

   The `.htaccess` files need `AllowOverride All` for this directory, which is
   the WAMP and XAMPP default. To confirm they are active, request
   `http://localhost/roomease/config/db.php` — it must return **403**, not a
   blank page. A blank page means Apache is ignoring `.htaccess` and the
   `config/`, `database/`, `includes/` and `storage/` folders are readable
   over HTTP.

## Accounts

| Account | Email | Password | Sign in at |
|---------|-------|----------|------------|
| Administrator | `admin@roomease.local` | set by you in step 5 | `/admin/login.php` |
| Demo landlord | `landlord@roomease.local` | `Password@123` (only if `seed_demo.sql` was imported) | `/auth/login.php` |
| Demo boarder | `boarder@roomease.local` | `Password@123` (only if `seed_demo.sql` was imported) | `/auth/login.php` |

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
├── admin/                     Admin panel: dashboard, manage users, manage listings
├── auth/                      Register, login, logout, profile, password reset by code
├── landlord/                  Dashboard, add/edit/delete listing, photo actions
├── boarder/                   Browse/search listings, listing detail, saved listings
├── config/db.php              Database connection (PDO)
├── includes/                  Shared code; never served over HTTP
│   ├── core/                    Logic loaded by pages, no HTML
│   │   ├── functions.php          Helpers; also boots security.php and the session
│   │   ├── security.php           Session hardening, headers, login/reset throttling
│   │   ├── mailer.php             Sends email through Gmail (reset codes)
│   │   └── google_auth.php        "Continue with Google"
│   ├── layouts/                 The outer shell of each kind of page
│   │   ├── header.php / footer.php            Public theme (guests and boarders)
│   │   ├── auth_header.php / auth_footer.php  Sign-in pages
│   │   └── panel*.php                         AdminLTE panel shell (admin and landlord)
│   ├── components/              Pieces placed inside pages: listing card,
│   │                            search bar, listing form, room rows, icons
│   └── scripts/                 PHP files that print a <script> block: password
│                                toggle, save heart, copy number, show more, room buttons
├── assets/css/style.css       Public theme styling
├── assets/adminlte/           AdminLTE theme for the management panel
├── assets/uploads/            Uploaded listing photos (auto-created per listing)
└── database/
    ├── roomease.sql             Schema + lookup data + locked admin account
    ├── seed_demo.sql            OPTIONAL demo accounts and sample listings
    ├── set_admin_password.php   CLI tool to set the administrator password
    └── migration_*.sql          Incremental schema changes (see setup step 4)
```

Both role panels render from the same `includes/layouts/panel*.php` shell,
configured per role in `includes/layouts/panel.php`. There is exactly one copy of that shell; see
the cleanup log below for why that is worth saying.

## What's implemented (from the project scope)

- Three roles with session-based auth and role-gated pages: Admin,
  Landlord, Prospective Boarder.
- Landlord: create, view, update, delete boarding house listings with
  name, address, rent, reservation fee, room type, capacity, utilities,
  amenities, house rules, contact info, and photos; choose which photo is
  the cover; see each listing's moderation state.
- Boarder: browse and page through approved listings, search by name or
  address, filter by room type and maximum rent, view full listing details,
  and save listings to a shortlist.
- Admin: view platform stats, approve or reject listings with a reason,
  activate/deactivate user accounts, archive and restore them, and remove
  and restore listings (removal archives, it never deletes). Every decision
  is written to the Activity Log with the administrator and the reason, and
  the landlord is told by email and on their dashboard.
- All roles: edit their profile and change their own password.
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
`includes/core/functions.php`, so every page gets the following without having to
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

**Rate limiting** (`database/migration_login_throttle.sql`)

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

## Maintenance log

### Cleanup pass — removing dead weight (Part A of the improvement plan)

The full plan lives in `RoomEase_Improvement_Plan.docx`. This section records
Part A of it, which has been carried out. Nothing below changes what the
application does for a user; it removes code and data that were misleading,
duplicated, or unsafe to publish.

**A1 — Deleted two unused copies of the management panel shell (346 lines).**
The live shell is `includes/layouts/panel_head.php`, `panel_navbar.php`,
`panel_sidebar.php` and `panel_footer.php`. Two older versions were still on
disk and loaded by nothing at all: `includes/admin_header.php` with
`includes/admin_footer.php` (132 lines, where the footer was required only by
the header and the header by no one), and the whole `admin/includes/`
directory — `head.php`, `navbar.php`, `sidebar.php`, `footer.php` (214 lines,
referenced nowhere in the project). Both sets are gone.

**A2 — Replaced five hand-written authorisation guards with `require_login()`.**
`admin/dashboard.php`, `manage_listings.php`, `manage_users.php`,
`listing_action.php` and `user_action.php` each carried their own
`is_logged_in()` / `is_admin()` test and redirect, duplicating a helper that
already existed in `includes/core/functions.php`. All five now call
`require_login('admin')`. The behaviour is the same; the point is that the
next admin page added to the project will copy one line instead of four.

**A3 — Removed the hard-coded fallback lists from the lookup helpers.**
`amenity_options()`, `utility_options()` and `room_type_options()` each caught
a database error and returned a hard-coded copy of the seed data. That made a
missing table invisible: the listing form rendered a full set of checkboxes,
the landlord ticked them, and the insert into the junction table failed
afterwards with nothing shown. The three now share one `lookup_options()`
helper that logs the failure and returns an empty list, and the listing form
renders an explicit "these options could not be read" notice in place of the
checklist. Loud and empty beats quiet and wrong.

**A4 — Split the demo data out of the schema, and removed the published admin
password.** `database/roomease.sql` previously seeded a demo landlord, a demo
boarder, three sample listings, and an administrator whose password was
printed in this README — which meant anyone with a copy of the repository
knew how to sign in as an administrator on any install where it had not been
changed. Now:

- `database/roomease.sql` contains the schema, the amenity/utility/room-type
  lookup rows, and a single administrator seeded with a placeholder hash that
  no password can match.
- `database/seed_demo.sql` (new) holds the demo accounts and sample listings.
  It is optional, written to be safe to run twice, and clearly labelled as
  unsafe to import on a public host.

  Splitting the file also exposed an error that had been in the schema from
  the start. Its comment read "Passwords below are hashed for 'Admin@123' and
  'Password@123'", but all three seeded accounts carried the *same* hash, so
  the demo landlord and demo boarder never had the password the comment
  claimed — all three were `Admin@123`. The demo accounts now carry a hash
  genuinely generated from `Password@123`, so the password documented above
  is the one that works.
- `database/set_admin_password.php` (new) sets the administrator password from
  the command line, so the real credential never lives in a tracked file.

  While testing that script, a latent bug turned up in `config/db.php`: it
  assigned `$host`, `$dbname`, `$username` and `$password` at global scope, so
  any script that set its own `$password` before requiring it had that value
  silently overwritten by the database password. The first version of the
  script hashed the empty database password instead of the one given on the
  command line and would have locked the administrator out. `config/db.php`
  now builds the connection inside a closure and exports only `$pdo`, and the
  script uses distinct variable names and verifies the stored hash before
  reporting success. No existing page was affected, because they all require
  `config/db.php` before assigning anything.

**A5 — Corrected this README.** The old "Suggested next steps" asked for four
things that were already done or no longer applied: a change-password page
(`auth/profile.php` has had one), pagination on browse (`render_pagination()`
provides it), normalising amenities into a lookup table (already normalised,
with a junction table), and removing a nested `Roomease/` checkout (no longer
in the working tree). The setup steps, the folder map and the account table
have all been brought back in line with what the code actually does.

**A6 — Fixed the browse filter bar on mobile.** `assets/css/style.css` defines
a `.search-bar` class across three rule blocks, including one inside the
720px media query that collapses it to a single column. No page used that
class: `boarder/browse.php` had been rewritten with `class="panel panel-pad"`
and an inline `grid-template-columns: 2fr 1fr 1fr auto`. Because an inline
style overrides a stylesheet media query, the filter bar stayed four columns
wide on a phone — which is where boarders actually search. The form now uses
`.search-bar`, the inline styles are gone, and the mobile rule applies. The
stale `margin-top: -30px` in that class, left over from a layout where the bar
overlapped the hero, was also removed.

**A7 — Added `.gitattributes` and `.gitignore`.** `git status` was reporting
roughly fifty files as modified at once, which is the signature of a
line-ending difference rather than real edits. `.gitattributes` sets
`* text=auto` so a checkout on Windows and one on Linux agree. `.gitignore`
keeps runtime logs, uploaded listing photos, editor folders and any future
`vendor/` out of the repository.

`.gitattributes` only governs files as they are staged, so the existing
phantom modifications persist until the working tree is renormalised once:

```
git add --renormalize .
git commit -m "Normalise line endings"
```

Two things also became visible while doing this, both left as they are
because they are decisions for the project owner rather than cleanup:

- Several files the Security section above describes are **not in version
  control at all** — `includes/core/security.php`, every `.htaccess`, and
  `database/migration_login_throttle.sql` are untracked. A fresh clone of
  this repository would therefore have none of the session hardening, none
  of the folder protection, and no throttle table. They should be committed.
- The commit history is a series of "Add files via upload" entries from the
  GitHub web uploader. Future changes are much easier to write up in the
  documentation chapter if each commit says what it changed and why.

### Verified after the cleanup

- Every PHP file in the project parses without error.
- `database/roomease.sql` imports into an empty database and produces one
  user, zero listings, and the 10 amenities / 5 utilities / 5 room types.
- `database/seed_demo.sql` imports on top of it, produces 3 users and 3
  listings, and importing it a second time leaves those counts unchanged.
- `database/set_admin_password.php` refuses an empty or short password,
  refuses an unknown email, and sets a hash that `password_verify()` accepts
  for the correct password and rejects for the wrong one.
- The seeded placeholder hash rejects every password, including the empty
  string and its own literal text.
- Browse, login and register render with no PHP notices, warnings or errors,
  and browse lists the demo listings and emits `class="search-bar"` with no
  inline grid styles.
- Dropping a lookup table makes the option list come back empty and logs the
  failure, instead of quietly substituting the old hard-coded list.
- Over real HTTP through Apache: signing in as an administrator loads the
  dashboard, manage users and manage listings pages with no PHP errors; a
  signed-in landlord is redirected to `index.php` and a guest to
  `auth/login.php` when either requests an admin page; the landlord dashboard,
  add-listing form and profile page all render, with the amenity, utility and
  room-type lists populated from the database; and `config/db.php` returns
  **403**, confirming the `.htaccess` rules are active.

### Index pass — the queries now have something to use (C1 of the improvement plan)

Before this, the only indexes in the schema were the primary keys, the unique
keys, and the ones InnoDB creates to support foreign keys. Not one of the
columns the application filters or sorts by was indexed, so the browse page
read **every** row of `boarding_houses` and then sorted the result in memory,
on every request.

`database/migration_indexes.sql` (new) adds seven indexes. It changes no data
and no application code, every statement is reversible with `DROP INDEX`, and
it is safe to run more than once — each index is created only if one of that
name is not already present.

```
mysql -u root -p roomease < database/migration_indexes.sql
```

The same indexes are now part of `database/roomease.sql`, so a fresh import
gets them without running the migration.

| Index | Table | Columns | Serves |
|---|---|---|---|
| idx_bh_public_recent | boarding_houses | moderation_status, availability_status, created_at | Default browse |
| idx_bh_public_rent | boarding_houses | moderation_status, availability_status, monthly_rent | Max-rent filter, price sorting |
| idx_bh_public_type | boarding_houses | moderation_status, availability_status, room_type, created_at | Room-type filter |
| idx_bh_created | boarding_houses | created_at | Admin tables, admin dashboard |
| idx_bh_landlord_recent | boarding_houses | landlord_id, created_at | Landlord dashboard |
| idx_images_cover | images | boarding_house_id, is_primary, image_id | Cover-photo lookup |
| idx_fav_user_recent | favorites | user_id, created_at | A boarder's saved list |

The two status columns lead each browse index because every browse query fixes
both of them, and the ordering column comes last so that one index supplies the
sort as well as the filter.

**Measured with `EXPLAIN`, before and after:**

| Query | Before | After |
|---|---|---|
| Browse, default | full table scan + filesort | index seek, backward index scan |
| Browse, room type + rent filter | full table scan + filesort | index seek, backward index scan |
| Landlord dashboard | index seek + filesort | index seek, backward index scan |
| Saved listings | index seek + filesort | index seek, backward index scan |
| Cover photo lookup | full scan of the row's photos | index seek |

"Backward index scan" replacing "Using filesort" is the part that matters: the
rows now come out of the index already in the right order, so there is no sort
step at all.

Two things worth recording because they are not obvious:

- **MySQL absorbed the redundant foreign-key indexes.** `fk_bh_landlord` and
  `fk_images_bh` no longer appear as separate indexes, because the new
  composites begin with the same column and can support the constraint
  themselves. All nine foreign keys were re-checked afterwards and still exist
  and still enforce — an insert with an unknown `landlord_id` is still
  rejected, and deleting a landlord still cascades to their listings and
  photos.
- **The cover-photo index leaves a small sort in place.** That query orders by
  `is_primary DESC, image_id ASC`, and matching mixed directions would need a
  descending index column. It is not worth it: the sort covers only the handful
  of photos belonging to one house. It would also be awkward to change later,
  since this index is now the one supporting `fk_images_bh` and cannot simply
  be dropped — which was confirmed by trying.

Full-text search was deliberately left out. The search box uses
`LIKE '%term%'`, whose leading wildcard no index can serve, so a `FULLTEXT`
index would sit unused until `boarder/browse.php` is rewritten to use
`MATCH ... AGAINST`. That is a code change; this pass was schema-only.

### Soft-deleted accounts, and room_type as a real foreign key

The two weaknesses recorded in the system diagrams have been fixed. Both ship
as re-runnable migrations, and both are in `database/roomease.sql` so a fresh
import already has them.

```
mysql -u root -p roomease < database/migration_soft_delete.sql
mysql -u root -p roomease < database/migration_room_type_fk.sql
```

**Accounts are archived, not destroyed.** `admin/user_action.php` used to run
`DELETE FROM users`, and because every foreign key cascades, that single
statement also erased the landlord's listings, every photo row attached to
them, and every boarder's saved copy of those listings — after deleting the
photo files from disk, so there was nothing to restore from either.

`users.deleted_at` now records the removal instead. An archived account cannot
sign in, is refused mid-session on its very next request, and disappears from
the public site along with its listings. The admin directory gained a
**Removed** tab and a **Restore** button, so the action is reversible from the
interface rather than only from phpMyAdmin.

The cascades themselves were deliberately left alone: they are still correct
for a genuine hard delete run against the database. What changed is that the
application no longer issues one.

Fixing this exposed a related hole worth recording. `boarder/browse.php` never
joined `users` at all, so a **deactivated** landlord's listings had always
stayed on the public site — `is_active` only ever blocked signing in. Browse,
saved listings and the listing detail page now all check that the landlord is
neither deactivated nor archived, via the shared `LIVE_LANDLORD_JOIN`.

**`room_type` is now `room_type_id`, constrained to `room_types`.** The lookup
table existed and both the form and the filter read from it, but the stored
value was a free `VARCHAR(50)` that nothing checked — the two agreed only
because the form offered no other choice.

The migration refuses to run if any listing's `room_type` has no match in
`room_types`, rather than quietly leaving those listings with no type. That
abort path was tested by injecting a bad row: it stopped with a message naming
the count, and left the schema untouched.

`fk_bh_room_type` uses **`ON DELETE RESTRICT`**, not `CASCADE`. Deleting a room
type that listings are using is refused instead of silently deleting those
listings, which makes it the one relationship in the schema that deliberately
does not cascade.

Display code did not have to change. The read queries select
`rt.room_type_name AS room_type` through `ROOM_TYPE_JOIN`, so every page that
already printed `$listing['room_type']` kept working; only the write paths and
the browse filter needed real edits. The browse filter now carries a
`room_type_id`, and an unrecognised value falls back to "no filter" rather than
to an empty result.

**Verified end to end, through Apache:**

- Both migrations run twice with no errors; the second run reports that there
  is nothing to migrate.
- A fresh import of `roomease.sql` produces a schema **identical** to the
  migrated live database — same columns, same ten constraints, same delete
  rules.
- All five existing listings kept their room type through the migration, and
  no listing was left without one.
- Archiving the demo landlord took browse from 4 listings to 1, made their
  listing detail page report that it no longer exists, and refused their
  login. Their 3 listings stayed in the database throughout. Restoring brought
  browse back to 4 and let them sign in again.
- Editing a listing's room type persists, and posting an invalid
  `room_type_id` produces the validation message "Choose a room type from the
  list" with the row unchanged, instead of a foreign key error.
- Creating a listing stores the right room type and still starts as `pending`.
- Every page for guest, landlord and administrator returns 200 with no PHP
  notices, warnings or errors.

A dump of the database was taken before the column was dropped, since dropping
a column cannot be undone.

### An existing local database is not affected

These changes alter `database/roomease.sql`, which only runs on a *fresh*
import. A `roomease` database created before this cleanup keeps its existing
rows and its existing administrator password. To adopt the new arrangement,
either keep using that database as it is, or drop it and follow the setup
steps above from step 3.

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
