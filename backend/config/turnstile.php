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
    | The backend only needs the secret key (used to verify tokens against
    | Cloudflare's siteverify endpoint). The public site key lives in the
    | frontend as VITE_TURNSTILE_SITE_KEY.
    |
    | Dev: use Cloudflare's always-pass testing keys:
    |   site (frontend):   1x00000000000000000000AA
    |   secret (backend):  1x0000000000000000000000000000000AA
    |
    | Prod: create a Turnstile site at https://dash.cloudflare.com (Turnstile),
    | set TURNSTILE_SECRET_KEY in the backend .env, and set
    | VITE_TURNSTILE_SITE_KEY in the frontend build env.
    |
    */

    'enabled' => (bool) env('TURNSTILE_ENABLED', false),

    'secret_key' => env('TURNSTILE_SECRET_KEY', ''),

    'verify_timeout_seconds' => (int) env('TURNSTILE_VERIFY_TIMEOUT_SECONDS', 5),
];
