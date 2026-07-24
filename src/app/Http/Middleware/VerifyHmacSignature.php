<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyHmacSignature
{
    /**
     * Verify that the request has a valid HMAC signature.
     *
     * Applied to sensitive routes (financial operations, imports, settings).
     * Works alongside CSRF — CSRF prevents forgery, HMAC prevents tampering.
     *
     * Expected headers:
     *   X-HMAC-Signature: hex-encoded HMAC-SHA256 of the payload
     *   X-HMAC-Timestamp: Unix timestamp (ms) when signature was generated
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Skip GET/HEAD/OPTIONS — only verify state-changing requests
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'])) {
            return $next($request);
        }

        // Skip if HMAC signing is disabled (dev convenience)
        if (! config('hmac.enabled')) {
            return $next($request);
        }

        $signature = $request->header('X-HMAC-Signature');
        $timestamp = $request->header('X-HMAC-Timestamp');

        if (! $signature || ! $timestamp) {
            return response()->json([
                'message' => 'Missing HMAC signature or timestamp.',
            ], 403);
        }

        // Replay protection: reject requests older than tolerance window
        $toleranceMs = config('hmac.tolerance_ms', 300000); // 5 minutes
        $now = round(microtime(true) * 1000);

        if (abs($now - (int) $timestamp) > $toleranceMs) {
            return response()->json([
                'message' => 'HMAC timestamp expired. Request rejected.',
            ], 403);
        }

        // Build the payload string: timestamp + method + path + body
        $payload = implode('.', [
            $timestamp,
            $request->method(),
            $request->path(),
            $request->getContent(),
        ]);

        $derivedKey = \App\Services\HmacService::deriveSessionKey();

        if (! $derivedKey) {
            return response()->json([
                'message' => 'HMAC signing key not configured.',
            ], 500);
        }

        $expectedSignature = hash_hmac('sha256', $payload, $derivedKey);

        if (! hash_equals($expectedSignature, $signature)) {
            return response()->json([
                'message' => 'Invalid HMAC signature. Request rejected.',
            ], 403);
        }

        return $next($request);
    }
}
