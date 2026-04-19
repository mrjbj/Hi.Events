<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cloudflare Turnstile
    |--------------------------------------------------------------------------
    |
    | Turnstile is Cloudflare's invisible-by-default CAPTCHA replacement.
    | When enabled, public endpoints wrapped with the `turnstile` middleware
    | alias require a valid token that the frontend obtains by rendering the
    | Turnstile widget.
    |
    | Dev: use Cloudflare's always-pass testing keys:
    |   site:   1x00000000000000000000AA
    |   secret: 1x0000000000000000000000000000000AA
    |
    | Prod: create a Turnstile site at https://dash.cloudflare.com (Turnstile)
    | with hostname events.district11ga.org, widget mode "Managed", and set
    | TURNSTILE_SITE_KEY + TURNSTILE_SECRET_KEY in the production .env.
    |
    */

    'enabled' => (bool) env('TURNSTILE_ENABLED', false),

    'site_key' => env('TURNSTILE_SITE_KEY', ''),

    'secret_key' => env('TURNSTILE_SECRET_KEY', ''),

    'verify_timeout_seconds' => (int) env('TURNSTILE_VERIFY_TIMEOUT_SECONDS', 5),
];
