# Putting RoomEase online

A checklist for moving RoomEase from `http://localhost/roomease/` to a real
address. It assumes shared hosting with cPanel or similar, which is what most
of these sites run on. Work down the list; the order matters in a few places,
and those are called out.

Nothing here is automatic. Tick each line as you go, and keep the answers
(host, database name, addresses) somewhere the whole team can see.

---

## 0. Before you start

- [ ] **Set the contact address.** Put `ROOMEASE_CONTACT_EMAIL=you@example.com`
      in `.env`. Until it is set, the contact page explains how to reach the
      team but does not offer the form.
- [ ] **Replace the placeholder names** in `about.php` with the real team
      members, their roles, and your course and school.
- [ ] **Decide the address.** A domain (`roomease.ph`) or a free subdomain from
      the host. Write it down: several steps below need the exact spelling,
      including whether it has `www.`.
- [ ] **Check the local site still works end to end**, so anything that breaks
      after the move is the move's fault and not a change you forgot.

## 1. Choosing a host

RoomEase needs all of these. Ask before paying:

- [ ] **PHP 8.0 or newer** with `pdo_mysql`, `fileinfo`, `gd` and `curl`.
      The code is verified on 8.3.
- [ ] **MySQL or MariaDB**, with phpMyAdmin or another way to import a `.sql`
      file.
- [ ] **Apache with `.htaccess` honoured** and `mod_rewrite` enabled. This one
      is not optional: the protection for `config/`, `database/`, `includes/`,
      `storage/` and `.env`, the ban on running PHP inside `assets/uploads/`,
      the security headers, `/sitemap.xml` and the 404 page are all `.htaccess`
      rules. **On nginx none of them apply** and every one has to be rewritten
      as server rules before the site is safe to expose.
- [ ] **HTTPS**, usually a free Let's Encrypt certificate in the control panel.
- [ ] **Outgoing SMTP allowed.** Many shared hosts block outbound mail to
      Gmail. Test it early (step 6): if it is blocked, password resets and the
      contact form will not work, and you will need the host's own mail relay.

## 2. Files to upload

Upload the contents of the project folder, with these exceptions.

**Never upload:**

- [ ] `.env` — it holds passwords. Create it on the server instead (step 4).
- [ ] `.git/`, `.claude/`, `.kilo/` — history and working copies of other
      branches. A worktree on a live server is a second, older copy of the
      whole site.
- [ ] `database/*.sql` and `database/set_admin_password.php` are needed once,
      during setup, and can be deleted afterwards. `.htaccess` already blocks
      them, but nothing beats their not being there.

**Must be present:**

- [ ] `vendor/`. Run `composer install` on the server if you have a terminal,
      or upload the folder from your machine. **Without it `.env` is never
      read**, and the site silently falls back to the built-in defaults —
      which means it tries to reach MySQL as `root` with no password.
- [ ] `.htaccess` in the project root **and** in `assets/uploads/`, `config/`,
      `database/`, `includes/` and `storage/`. Upload tools often hide
      dotfiles: turn on "show hidden files" and check each one arrived.
- [ ] `assets/img/` — the icon and the link-preview card.

> `composer.lock` is not in the repository, so `composer install` on the server
> may fetch a newer `phpdotenv` than you tested with. Committing the lock file
> removes that difference.

## 3. Database

- [ ] Create a database **and a dedicated user** for it. Do not use `root`:
      give the user rights to that one database only.
- [ ] Import `database/boardinghouse.sql` (phpMyAdmin → Import). This is the
      full schema; do not import `roomease.sql`, which is the older file.
- [ ] Optional: import `database/seed_demo.sql` for demonstration listings.
      **Do not** put demo accounts on a site real people will use.
- [ ] Set the administrator password. `database/set_admin_password.php` runs
      from a command line only:

      php database/set_admin_password.php "a long password you chose"

      With no terminal on the host, generate the hash on your own machine with
      `php -r 'echo password_hash("the password", PASSWORD_DEFAULT), "\n";'`,
      then paste it into the admin row's `password_hash` in phpMyAdmin.
- [ ] Sign in at `/admin/login.php` and confirm it works before going further.

## 4. Settings: the `.env` file

Create `.env` in the project root on the server, from `.env.example`:

- [ ] `ROOMEASE_DB_HOST`, `ROOMEASE_DB_NAME`, `ROOMEASE_DB_USER`,
      `ROOMEASE_DB_PASS` — the database and user from step 3.
- [ ] `ROOMEASE_MAIL_USERNAME`, `ROOMEASE_MAIL_PASSWORD` — the Gmail address
      and its 16-character App Password.
- [ ] `ROOMEASE_MAIL_PORT` — only if the host blocks 465; `587` is the usual
      alternative.
- [ ] `ROOMEASE_GOOGLE_CLIENT_ID`, `ROOMEASE_GOOGLE_CLIENT_SECRET`.
- [ ] `ROOMEASE_CONTACT_EMAIL` — where the contact form sends.
- [ ] **`ROOMEASE_SHOW_RESET_CODES` must be absent or `false`.** It shows
      password reset codes on screen. It is already limited to requests from
      the server itself, but a tunnel or proxy can make every visitor look
      local.
- [ ] Confirm `https://yourdomain/.env` answers 403 or 404 (step 8).

## 5. Google sign-in

- [ ] In the Google Cloud console, add the live callback as an authorised
      redirect URI: `https://yourdomain/auth/google_callback.php`. It must match
      the address people actually use, character for character — `www.` and
      `https` included. Keep the localhost entry so development still works.
- [ ] Add your test accounts under **Test users**, or publish the consent
      screen if anyone outside the team will sign in.
- [ ] After deploying, sign in with Google once and confirm it returns to the
      site rather than showing `redirect_uri_mismatch`.

## 6. Email

- [ ] Send yourself a password reset code from the live site.
- [ ] Send a message through the contact page.
- [ ] If either fails, read `storage/mail.log`: it records every attempt and
      Gmail's own reason. A refused connection usually means the host blocks
      outgoing SMTP (step 1).

## 7. HTTPS

- [ ] Install the certificate and force HTTPS in the control panel.
- [ ] Load the site over `https://` and confirm the padlock.
- [ ] RoomEase turns on HSTS and marks its cookies `Secure` by itself once
      requests arrive over HTTPS, including behind a proxy that sets
      `X-Forwarded-Proto`. Nothing to configure.

## 8. Security checks, after it is live

Open each address. Anything that shows content is a problem to fix before you
tell anyone the site exists.

- [ ] `https://yourdomain/.env` → refused
- [ ] `https://yourdomain/config/db.php` → refused
- [ ] `https://yourdomain/includes/core/functions.php` → refused
- [ ] `https://yourdomain/database/boardinghouse.sql` → refused
- [ ] `https://yourdomain/storage/mail.log` → refused
- [ ] `https://yourdomain/vendor/autoload.php` → refused
- [ ] `https://yourdomain/.claude/` → refused, and better still, not uploaded
- [ ] A made-up address → the RoomEase 404 page, not Apache's default
- [ ] An uploaded photo opens; the same folder must never run PHP

## 9. Does it actually work?

Walk the three roles once on the live site:

- [ ] **Boarder:** register, search, filter, open a listing, see the map, save
      a room, call button shows the number.
- [ ] **Landlord:** register, post a boarding house with rooms and photos,
      check it is pending review.
- [ ] **Administrator:** approve that listing, confirm the boarder can now see
      it, and check the activity log recorded the decision.
- [ ] **Accounts:** password reset by email, Google sign-in, changing a
      password signs the account out of other browsers.
- [ ] **The public pages:** `/about.php`, `/contact.php`, `/terms.php`,
      `/privacy.php`, `/robots.txt`, `/sitemap.xml`, and the favicon in the tab.

## 10. Finishing touches

- [ ] **Fix the sitemap line in `robots.txt`.** It currently reads
      `Sitemap: /sitemap.xml`; the standard wants the full address, so change
      it to `Sitemap: https://yourdomain/sitemap.xml` once you know the domain.
- [ ] **Check the link preview.** Paste a listing's address into Facebook's
      Sharing Debugger (`developers.facebook.com/tools/debug/`) and into
      Messenger. You should see the listing's name, its description and its
      photo. The debugger also clears Facebook's cache if you change the tags
      later.
- [ ] **Submit the site** to Google Search Console and give it the sitemap, if
      you want it found by search.

## 11. Backups and updates

- [ ] **Back up before every change**, and on a schedule afterwards:

      mysqldump -u user -p database > roomease-YYYY-MM-DD.sql

      and a copy of `assets/uploads/`, which holds every photo and is not in
      the repository.
- [ ] **Practise a restore once**, on your own machine, from those two
      artefacts. A backup nobody has restored is a guess.
- [ ] **Updating the site:** upload the changed files, or `git pull` if the
      host has git. `.env` and `assets/uploads/` stay as they are. Import a
      schema change only if one is part of the update.
- [ ] **Know where the error log is** on the host, and look at it after each
      update.

## 12. If it goes wrong

- [ ] **Blank white page:** PHP error with display off. Read the host's error
      log; it is almost always a missing `vendor/` or a wrong database setting.
- [ ] **"The site is temporarily unavailable":** RoomEase could not reach the
      database. Check the four `ROOMEASE_DB_*` values in `.env`.
- [ ] **Everything works but photos 404:** `assets/uploads/` did not upload, or
      is not writable by the web server.
- [ ] **Rolling back:** keep the previous version's files and the database dump
      from before the change. Restoring is putting both back.
