<?php

declare(strict_types=1);

namespace HiEvents\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class VerifyTurnstileToken
{
    private const SITEVERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    private const TOKEN_HEADER = 'cf-turnstile-response';

    private const COOKIE_NAME = 'ct_verified';

    private const COOKIE_TTL_SECONDS = 900;

    private const COOKIE_PATH = '/api/public';

    public function handle(Request $request, Closure $next): SymfonyResponse
    {
        if (!config('turnstile.enabled')) {
            return $next($request);
        }

        if ($this->hasValidCookie($request)) {
            return $next($request);
        }

        $verifyError = $this->runSiteverify($request);
        if ($verifyError !== null) {
            return $verifyError;
        }

        $response = $next($request);

        if ($response->isSuccessful()) {
            $response->headers->setCookie($this->issueCookie($request));
        }

        return $response;
    }

    private function hasValidCookie(Request $request): bool
    {
        $raw = $request->cookie(self::COOKIE_NAME);
        if (!is_string($raw) || $raw === '') {
            return false;
        }

        try {
            $decoded = Crypt::decryptString($raw);
        } catch (DecryptException) {
            return false;
        }

        $payload = json_decode($decoded, true);
        if (!is_array($payload)) {
            return false;
        }

        $exp = (int) ($payload['exp'] ?? 0);
        if ($exp < time()) {
            return false;
        }

        $ipHash = (string) ($payload['ip'] ?? '');
        if ($ipHash !== hash('sha256', (string) $request->ip())) {
            return false;
        }

        return true;
    }

    private function issueCookie(Request $request): Cookie
    {
        $payload = Crypt::encryptString(json_encode([
            'exp' => time() + self::COOKIE_TTL_SECONDS,
            'ip' => hash('sha256', (string) $request->ip()),
        ], JSON_THROW_ON_ERROR));

        return new Cookie(
            name: self::COOKIE_NAME,
            value: $payload,
            expire: time() + self::COOKIE_TTL_SECONDS,
            path: self::COOKIE_PATH,
            domain: null,
            secure: true,
            httpOnly: true,
            raw: false,
            sameSite: Cookie::SAMESITE_STRICT,
        );
    }

    private function runSiteverify(Request $request): ?SymfonyResponse
    {
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

        return null;
    }
}
