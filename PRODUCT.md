# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

Renters in Baybay City, Leyte, Philippines — working adults and students
alike. RoomEase is a general local rental directory and is deliberately **not**
tied to a campus or an academic calendar, so no surface should assume a
semester rhythm, a student budget, or a university affiliation.

Three roles, all session-authenticated and role-gated:

- **Prospective boarder** — searches for a room, compares options, shortlists,
  and contacts the landlord. Browses on a phone as often as a desktop; the
  improvement plan records the browse filter bar breaking on small screens as a
  real defect, which is evidence that phone use is expected rather than
  incidental.
- **Landlord** — publishes and maintains listings for the rooms they own, and
  waits on moderation before those listings are visible.
- **Administrator** — moderates listings (approve or reject with a reason),
  manages accounts, and watches platform-level stats.

## Product Purpose

A web-based boarding house information and listing system. Landlords publish
rooms, administrators moderate them, and renters discover, compare, shortlist,
and inquire.

Success is a renter completing the whole journey — browse, shortlist, inquire —
**without leaving RoomEase**, and both sides still having a record of it
afterwards.

## Positioning

The status quo in this market is Facebook groups, Marketplace posts, and word
of mouth. Those scatter the journey across platforms and leave nothing behind:
a renter finds a room, copies a phone number, moves to Messenger, and the
system never learns that any of it happened.

RoomEase's claim is **one place, whole journey**. Browsing, shortlisting, and
inquiring live on one system, so the renter can return to what they looked at
and the landlord has a record of who asked. A neighboring product built on a
social feed cannot truthfully make that claim, because the feed owns the
conversation and discards the structure.

Honest state of this claim: the **inquiry step does not exist yet**. Today a
boarder browses, reads the landlord's phone number on the listing page, and
leaves. This is the single largest hole in the product and is item B1 in
`RoomEase_Improvement_Plan.docx`. Future work must not describe or design the
inquiry inbox as though it already ships.

## Operating Context

- Deployed on a local WAMP or XAMPP stack (Apache, PHP 8, MySQL/MariaDB), with
  `.htaccess` protecting `config/`, `database/`, `includes/`, and `storage/`.
- Renters browse on phones as a first-class case, not a fallback.
- Listings are moderated before they are visible: a landlord's new listing is
  invisible to boarders until an administrator approves it, so the landlord's
  dashboard has to communicate a waiting state and a rejection reason.
- Search matches listing name and address as a single string. `address` is
  stored as one text field, so there is no separate city or barangay filter.
- A boarding house has rooms (`rooms`). Each room has its own room type
  (a foreign key onto `room_types`, so the browse filter and the room form can
  never disagree), rent, capacity, slots taken, and an open/closed switch.
  "Available", "Full" and "Not available" are worked out from those, never
  stored. The listing's own `availability_status` is only a show/hide switch
  for the whole listing.
- Browse's room-type and budget filters match a room inside a listing: a
  listing appears when one of its open rooms fits. Listings with a room
  available sort before fully occupied ones, which are still shown.
- Boarders only ever see slot counts. There are no tenant records, so no tenant
  name is stored or shown anywhere.

## Capabilities and Constraints

**Confirmed functionality**

- Listing CRUD with name, address, reservation fee, utilities, amenities,
  house rules, stay terms, map pin, and contact info; house photos with a
  landlord-chosen cover photo.
- Rooms inside each listing: add, edit and delete rooms, each with its own
  photos and main photo; landlords update slots taken with − / + and close or
  reopen a room without leaving the listing page. A listing is not shown, and
  cannot be approved, until it has at least one room.
- Utilities and amenities: the administrator's items are available to every
  landlord; each landlord can add their own, which only they see, from the
  listing form or their Utilities & Amenities page. The administrator can make
  a landlord's item available to everyone, merging same-name copies.
- Moderation queue: approve or reject with a reason; landlords see the state of
  each of their listings.
- Browse with keyword search, room-type and maximum-rent filters, and
  server-side pagination; full listing detail; saved-listing shortlist.
- Administration: platform stats, user activate/deactivate, archive/restore,
  listing removal.
- Separate sign-in for administrators at `admin/login.php` (fixed dark
  background, no sign-up, Google, or "Remember me", hidden from search
  engines). The public login refuses administrator accounts and the admin
  login accepts only them, each with the generic "Invalid email or password".
  Administrators reset passwords from their own page, and are sent back to the
  admin login when they log out or their session ends.
- Profile editing and password change for all roles; password reset by emailed
  link using single-use, hashed, expiring tokens.
- Plain-language Terms & Conditions (`terms.php`) and Privacy Policy
  (`privacy.php`), drafted to match what the system actually stores. Linked
  from the sign-in and sign-up pages ("By continuing, you agree to...") and the
  footer.
- Security baseline is complete and is not up for redesign: bcrypt, prepared
  statements throughout, CSRF tokens on every form, hardened sessions
  re-validated against the database each request, sign-in and reset throttling,
  security headers, MIME-checked uploads with PHP disabled in the upload
  folder.

**Binding constraints** (confirmed by the user)

- **Runs offline.** The app must work on a local stack with no internet
  reachable. Nothing may depend on an external service being available at
  render time.
- **Plain PHP 8 + MySQL, no framework.** PDO, no Composer, no build step, no
  package manager in the request path. Roughly 39 PHP files.

**Webfonts: resolved**

Every face is self-hosted under `assets/fonts/` (IBM Plex Sans and Fraunces),
for the public site in `assets/css/style.css` and for the management panel in
`assets/css/panel.css`, and the CSP allows fonts from this server only. The
panel was the last page still asking Google Fonts for Source Sans Pro, a
request the CSP always refused. The typography seen offline at the defence is
the typography seen online.

What still needs a connection, by design: the OpenStreetMap tiles on listing
pages, "Continue with Google", and sending email through Gmail.

**Explicitly undecided**

- Whether the capstone's *Limitations of the Study* bind future scope. The user
  did not mark it fixed, so real-time chat, payments, and SMS are absent
  today but are not ruled out.
- **Google sign-in is a second accepted exception.** The user chose it knowing
  it needs internet and Google Cloud OAuth credentials that only they can
  create. The button always shows; until credentials exist, clicking it says
  Google sign-in is not set up yet. Email and password login, "Remember me",
  and everything else still work offline. Someone new from the log-in page
  chooses boarder or landlord on a "One more step" page before any account is
  created; accounts made with Google set a password with an emailed code from
  their profile.
- **Maps are an accepted exception to "runs offline".** The user chose to add
  a listing map and a landlord pin picker knowing the tiles need internet.
  Leaflet is self-hosted in `assets/vendor/leaflet`; only the tile images come
  from `tile.openstreetmap.org`, which the CSP allows as an image source. An
  offline demo shows every other section and a "map could not load" note.
- The inquiry inbox (B1), moderation attribution (B2), browse sort options
  (B3), and server-side paging for admin tables (B4) are identified in the
  improvement plan but not built. Room-slot tracking (B5) is built, as the
  rooms and slot counts described above.

## Brand Commitments

The name **RoomEase** is fixed.

The public theme was redesigned from principles taken from myboardmate.com, at
the user's request: the principles, not its palette, type, or layout. The user's
standing brief is one bold element with everything else restrained, and no
generic template tells (uniform card grids, all-caps eyebrow labels, scroll
fade-ins). `assets/css/style.css` documents the concept: *a forest-green frame
with the thing you came for sitting on its lower edge*. Every public page opens
on a forest band, and the search form, listing gallery, or auth form sits across
the band's seam. Palette: `--forest` #184A3F, `--marigold` #F2A93B (reserved for
the hero's second line, Search, and Call), `--paper` #FAF8F3, `--ink` #1F2A28,
`--leaf` #E6EFEA. Fraunces (soft axis) for headings and IBM Plex Sans for body,
both self-hosted. At the user's request the listing layouts then followed
MyBoardMate's structure more closely, still in RoomEase's colours and type:
listings are photo cards with a "View details" button (three across on a
laptop, one on a phone), browse shows six at a time with "Show more rooms"
adding the next six (`?page=N` renders everything up to N), and the listing
page is a title band with tags, a main column of sections (photos with a
full-screen viewer, utilities, living here, what to expect, house rules, map),
and a sticky Quick Info card with price and landlord contact.

The sign-in pages (log in, sign up, forgot and reset password) are standalone,
after MyBoardMate's login: no site header, band, or footer, just the RoomEase
wordmark, a heading, and one card. An administrator chooses their background
(a colour or a photo) in the panel under Appearance; the text outside the card
switches between forest and white by contrast, and photos get a forest tint.
The earlier *lobby directory board* theme (Zilla Slab, brass on dark board) is
retired.

Visual maturity is split and future work should know it: the public theme is
authored and coherent, while the admin and landlord panels are stock AdminLTE
with no such intent applied.

No logo, wordmark, or brand asset files exist in the repository.

## Evidence on Hand

- `database/seed_demo.sql` — one demo landlord, one demo boarder, and three
  sample Baybay City listings. Demonstration fixtures only; the password in
  them is published in this repository.
- `RoomEase_Improvement_Plan.docx` — an audit of the current build with a
  removal list (Part A) and a prioritized addition list (Part B).
- `README.md` — accurate setup, accounts, folder structure, and security notes.

There are **no** real landlords, real listings, users, usage data, testimonials,
case studies, press, partnerships, pricing, or research findings. Future work
must not invent any of these, and must not put fabricated social proof or
counts on any surface.

## Product Principles

1. **The journey ends inside RoomEase.** Every surface should carry the renter
   to the next step — view, shortlist, inquire — rather than handing over a
   phone number and letting the conversation leave for Messenger. A design that
   dead-ends at contact details has failed the product's one real claim.
2. **A listing is a record, not a post.** Typed fields and moderation are the
   whole difference from a social feed. Prefer structure that can be filtered,
   compared, and kept current over free text that merely reads well.
3. **Offline is a floor, not a preference.** Anything that needs the network to
   render is a defect on the stack this product actually runs on.
4. **The phone is the real screen.** Small-screen behavior is part of the
   design, not a responsive afterthought bolted on at the end.
5. **Moderation is a user-facing experience.** Waiting, approval, and rejection
   are states a landlord lives in. They deserve designed communication, not a
   bare status column.
