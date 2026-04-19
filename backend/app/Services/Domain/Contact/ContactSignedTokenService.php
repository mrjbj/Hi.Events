<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Contact;

use HiEvents\Services\Domain\Contact\DTO\ContactTokenPayload;

/**
 * Opaque HMAC-signed tokens encoding {contact_id, account_id, expiry, nonce}.
 * Embedded in outbound emails as ?c=<token> on event URLs, then verified
 * when the contact clicks through to prefill their checkout form or view
 * their self-service profile.
 *
 * Tokens are bearer capabilities: anyone with the token can read/update
 * the contact's record until expiry. Treat them like session cookies.
 */
class ContactSignedTokenService
{
    private const ALGO = 'sha256';

    private const SEPARATOR = '.';

    public function generate(int $contactId, int $accountId, ?int $ttlDays = null): string
    {
        $ttl = $ttlDays ?? (int) config('app.contact_token_ttl_days', 30);
        $payload = [
            'cid' => $contactId,
            'aid' => $accountId,
            'exp' => time() + ($ttl * 86400),
            'nonce' => bin2hex(random_bytes(8)),
        ];

        $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $payloadB64 = self::base64UrlEncode($payloadJson);
        $signature = hash_hmac(self::ALGO, $payloadB64, $this->secret(), true);

        return $payloadB64 . self::SEPARATOR . self::base64UrlEncode($signature);
    }

    public function verify(string $token): ?ContactTokenPayload
    {
        $parts = explode(self::SEPARATOR, $token, 2);
        if (count($parts) !== 2) {
            return null;
        }
        [$payloadB64, $signatureB64] = $parts;

        $expectedSignature = hash_hmac(self::ALGO, $payloadB64, $this->secret(), true);
        $providedSignature = self::base64UrlDecode($signatureB64);
        if ($providedSignature === null || !hash_equals($expectedSignature, $providedSignature)) {
            return null;
        }

        $payloadJson = self::base64UrlDecode($payloadB64);
        if ($payloadJson === null) {
            return null;
        }
        $payload = json_decode($payloadJson, true);
        if (!is_array($payload)) {
            return null;
        }
        if (!isset($payload['cid'], $payload['aid'], $payload['exp'], $payload['nonce'])) {
            return null;
        }
        if (!is_int($payload['cid']) || !is_int($payload['aid']) || !is_int($payload['exp'])) {
            return null;
        }
        if ($payload['exp'] < time()) {
            return null;
        }

        return new ContactTokenPayload(
            contactId: $payload['cid'],
            accountId: $payload['aid'],
            expiresAt: $payload['exp'],
            nonce: (string) $payload['nonce'],
        );
    }

    private function secret(): string
    {
        $appKey = (string) config('app.key', '');
        if (str_starts_with($appKey, 'base64:')) {
            $decoded = base64_decode(substr($appKey, 7), true);
            if ($decoded !== false) {
                return $decoded;
            }
        }

        return $appKey;
    }

    private static function base64UrlEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $encoded): ?string
    {
        $padded = strtr($encoded, '-_', '+/');
        $remainder = strlen($padded) % 4;
        if ($remainder > 0) {
            $padded .= str_repeat('=', 4 - $remainder);
        }
        $decoded = base64_decode($padded, true);
        if ($decoded === false) {
            return null;
        }

        return $decoded;
    }
}
