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
- Room type is a foreign key onto `room_types`, so the browse filter and the
  listing form can never disagree about the available values.

## Capabilities and Constraints

**Confirmed functionality**

- Listing CRUD with name, address, rent, reservation fee, room type, capacity,
  utilities, amenities, house rules, and contact info; multiple photo uploads
  with a landlord-chosen cover photo.
- Moderation queue: approve or reject with a reason; landlords see the state of
  each of their listings.
- Browse with keyword search, room-type and maximum-rent filters, and
  server-side pagination; full listing detail; saved-listing shortlist.
- Administration: platform stats, user activate/deactivate, archive/restore,
  listing removal.
- Profile editing and password change for all roles; password reset by emailed
  link using single-use, hashed, expiring tokens.
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

**Known conflict with the offline constraint**

Six rendering paths currently fetch webfonts over the network, so on an offline
demo they silently fall back to system faces:

- `assets/css/style.css:8` — `@import` of Zilla Slab, Inter, and IBM Plex Mono
- `includes/panel_head.php:20`, `auth/login.php:83`,
  `auth/forgot_password.php:71`, `auth/reset_password.php:65` — Source Sans Pro
- `includes/security.php:101-102` — the CSP explicitly allows
  `fonts.googleapis.com` and `fonts.gstatic.com`

Resolving this means self-hosting the faces under `assets/` and tightening the
CSP. Until then, the typography seen on a connected machine is not the
typography seen at the defence.

**Explicitly undecided**

- Whether the capstone's *Limitations of the Study* bind future scope. The user
  did not mark it fixed, so real-time chat, payments, maps, and SMS are absent
  today but are not ruled out.
- The inquiry inbox (B1), moderation attribution (B2), browse sort options
  (B3), server-side paging for admin tables (B4), and room-slot tracking (B5)
  are identified in the improvement plan but not built.

## Brand Commitments

The name **RoomEase** is fixed.

An incumbent visual world exists and should be treated as evidence rather than
as a commitment — the user did not declare it binding. `assets/css/style.css`
documents a deliberate concept: *a building lobby directory board*, where
listings read as nameplates on a tenant directory and hairline rules stand in
for the board's slats. It carries a real palette (`--paper` #F6F2E9, `--board`
#1C3435, `--brass` #B8863C, `--clay` #A9573C, `--sage` #6E8168) with Zilla Slab
headings and IBM Plex Mono accents.

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
