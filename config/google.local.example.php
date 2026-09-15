<?php
/**
 * Google sign-in credentials — EXAMPLE.
 *
 * Copy this file to config/google.local.php (which .gitignore keeps out of the
 * repository) and fill in the two values from Google Cloud console:
 *
 *   1. https://console.cloud.google.com/ > APIs & Services > Credentials
 *   2. Create credentials > OAuth client ID > Web application
 *   3. Authorized redirect URIs: add the callback exactly, for example
 *        http://localhost/roomease/auth/google_callback.php
 *   4. Copy the client ID and client secret below.
 *
 * While the OAuth consent screen is in "Testing", only the Google accounts
 * listed under Test users can sign in.
 *
 * Leave both empty and the "Continue with Google" button still shows, but
 * clicking it says Google sign-in is not set up yet.
 * Environment variables ROOMEASE_GOOGLE_CLIENT_ID and
 * ROOMEASE_GOOGLE_CLIENT_SECRET take priority over this file.
 */
return [
    'client_id'     => '',
    'client_secret' => '',

    // Optional. Path to a CA certificate bundle (cacert.pem) if PHP cannot
    // verify Google's certificate on this machine. Normally leave empty.
    'ca_bundle'     => '',
];
