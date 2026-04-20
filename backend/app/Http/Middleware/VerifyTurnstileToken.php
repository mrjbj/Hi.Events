<?php

declare(strict_types=1);

namespace HiEvents\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class VerifyTurnstileToken
{
    private const SITEVERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    private const TOKEN_HEADER = 'cf-turnstile-response';

    public function handle(Request $request, Closure $next): SymfonyResponse
    {
        if (!config('turnstile.enabled')) {
            return $next($request);
        }

        $secret = (string) config('turnstile.secret_key', '');
        if ($secret === '') {
            Log::warning('Turnstile enabled but TURNSTILE_SECRET_KEY is empty; failing closed.');

            return response()->json(['message' => 'Challenge required.'], Response::HTTP_FORBIDDEN);
        }

        $token = $request->header(self::TOKEN_HEADER) ?? (string) $request->input('cf_turnstile_token', '');
        if ($token === '') {
            return response()->json(['message' => 'Challenge token missing.'], Response::HTTP_FORBIDDEN);
        }

        try {
            $response = Http::asForm()
                ->timeout((int) config('turnstile.verify_timeout_seconds', 5))
                ->post(self::SITEVERIFY_URL, [
                    'secret' => $secret,
                    'response' => $token,
                    'remoteip' => $request->ip(),
                ]);
        } catch (\Throwable $e) {
            Log::warning('Turnstile siteverify request failed', ['error' => $e->getMessage()]);

            return response()->json(['message' => 'Challenge verification unavailable.'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $body = $response->json();
        if (!is_array($body) || ($body['success'] ?? false) !== true) {
            Log::info('Turnstile verification failed', [
                'ip' => $request->ip(),
                'error_codes' => $body['error-codes'] ?? null,
            ]);

            return response()->json(['message' => 'Challenge failed.'], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
