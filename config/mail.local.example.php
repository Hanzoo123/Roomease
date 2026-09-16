<?php
/**
 * Outgoing email through Gmail — EXAMPLE.
 *
 * Copy this file to config/mail.local.php (which .gitignore keeps out of the
 * repository) and fill in the Gmail address and an App Password. Password
 * reset codes are sent from that address.
 *
 * Gmail does not accept your normal Google password here. Make an App
 * Password instead:
 *
 *   1. https://myaccount.google.com/security > turn on 2-Step Verification.
 *   2. https://myaccount.google.com/apppasswords > create one named "RoomEase".
 *   3. Copy the 16 letters Google shows into 'password' below. The spaces
 *      Google puts between them do not matter.
 *
 * Leave username or password empty and nothing is sent. Someone browsing from
 * the server itself then sees the reset code on screen instead.
 * Environment variables ROOMEASE_MAIL_USERNAME and ROOMEASE_MAIL_PASSWORD take
 * priority over this file.
 */
return [
    // Your own Gmail address, and the 16 letters Google shows when you create
    // the App Password. They are not written here: copy them from your own
    // Google account, or Gmail answers "Username and Password not accepted".
    'username'  => '',
    'password'  => '',

    // The name people see the email come from.
    'from_name' => 'RoomEase',

    // Normally leave these. Port 465 uses SSL from the start; set 587 to use
    // STARTTLS instead, if a network blocks 465.
    'host'      => 'smtp.gmail.com',
    'port'      => 465,

    // Optional. Path to a CA certificate bundle (cacert.pem) if PHP cannot
    // verify Gmail's certificate on this machine. Normally leave empty.
    'ca_bundle' => '',
];
