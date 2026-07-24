<?php

return [

    /*
    |--------------------------------------------------------------------------
    | HMAC Request Signing
    |--------------------------------------------------------------------------
    |
    | When enabled, state-changing requests to protected routes must include
    | a valid HMAC-SHA256 signature in the X-HMAC-Signature header.
    | This prevents payload tampering in transit.
    |
    | Works alongside CSRF tokens:
    |   CSRF  → proves the request came from your browser
    |   HMAC  → proves the payload wasn't modified in transit
    |
    */

    'enabled' => env('HMAC_ENABLED', false),

    'signing_key' => env('HMAC_SIGNING_KEY'),

    // Reject requests with timestamps older than this (milliseconds)
    // Default: 300000ms = 5 minutes
    'tolerance_ms' => env('HMAC_TOLERANCE_MS', 300000),

];
