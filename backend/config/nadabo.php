<?php

return [

    /*
    | Email verification for customer accounts. Off by default so development
    | is not blocked while SMTP is not configured. When on, new customers get a
    | verification email and unverified customers are sent to the notice page.
    */
    'require_email_verification' => (bool) env('NADABO_REQUIRE_EMAIL_VERIFICATION', false),

    /*
    | Admin-area session: its own cookie, limited to /admin, and (database
    | driver) its own table, so staff and customer sessions never mix.
    */
    'admin_session' => [
        'cookie' => env('ADMIN_SESSION_COOKIE', 'nadabo_admin_session'),
        'path' => '/admin',
        'table' => env('ADMIN_SESSION_TABLE', 'admin_sessions'),
    ],

];
