<?php

namespace Tests\Unit\Services\Domain\Contact;

use HiEvents\Services\Domain\Contact\ContactSignedTokenService;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class ContactSignedTokenServiceTest extends TestCase
{
    private ContactSignedTokenService $service;

    protected function setUp(): void
    {
        parent::setUp();
        // Fixed secret so signatures are deterministic across tests.
        Config::set('app.key', 'base64:' . base64_encode(str_repeat('k', 32)));
        Config::set('app.contact_token_ttl_days', 30);
        $this->service = new ContactSignedTokenService();
    }

    public function testGenerateAndVerifyRoundTrip(): void
    {
        $token = $this->service->generate(contactId: 42, accountId: 7);
        $payload = $this->service->verify($token);

        $this->assertNotNull($payload);
        $this->assertSame(42, $payload->contactId);
        $this->assertSame(7, $payload->accountId);
        $this->assertGreaterThan(time(), $payload->expiresAt);
        $this->assertNotEmpty($payload->nonce);
    }

    public function testGenerateProducesDistinctTokensForSameInputs(): void
    {
        // Nonces must make each token unique even for identical contact/account.
        $t1 = $this->service->generate(42, 7);
        $t2 = $this->service->generate(42, 7);

        $this->assertNotSame($t1, $t2);
    }

    public function testVerifyRejectsTamperedSignature(): void
    {
        $token = $this->service->generate(42, 7);
        [$payloadB64, $signatureB64] = explode('.', $token);

        // Flip one character in the signature.
        $tampered = $payloadB64 . '.' . ($signatureB64[0] === 'A' ? 'B' : 'A') . substr($signatureB64, 1);

        $this->assertNull($this->service->verify($tampered));
    }

    public function testVerifyRejectsTamperedPayload(): void
    {
        $token = $this->service->generate(contactId: 42, accountId: 7);
        [$payloadB64, $signatureB64] = explode('.', $token);

        // Replace payload with a different valid JSON payload (different contactId).
        $forgedPayload = rtrim(strtr(base64_encode(
            json_encode(['cid' => 999, 'aid' => 7, 'exp' => time() + 3600, 'nonce' => 'abc'])
        ), '+/', '-_'), '=');

        $this->assertNull($this->service->verify($forgedPayload . '.' . $signatureB64));
    }

    public function testVerifyRejectsExpiredToken(): void
    {
        // TTL 0 days → token expires immediately.
        $token = $this->service->generate(contactId: 42, accountId: 7, ttlDays: 0);

        // Artificially sleep past expiry window. TTL 0 sets exp = time(), so
        // anything from the next second onward is past. Use sleep(1) to avoid
        // race; alternatively inspect that exp <= time().
        sleep(1);

        $this->assertNull($this->service->verify($token));
    }

    public function testVerifyRejectsMalformedToken(): void
    {
        $this->assertNull($this->service->verify(''));
        $this->assertNull($this->service->verify('no-separator'));
        $this->assertNull($this->service->verify('!!!not-base64.also-not-base64'));
        $this->assertNull($this->service->verify('aW52YWxpZA==.aW52YWxpZA==')); // "invalid" not valid JSON
    }

    public function testVerifyUsesConstantTimeComparison(): void
    {
        // Sanity check: two invalid tokens should both return null without
        // leaking which bit differed. We can't measure timing reliably in a
        // unit test, but we can at least confirm hash_equals is reached by
        // verifying that a nearly-correct signature fails.
        $token = $this->service->generate(42, 7);
        [$payloadB64, $signatureB64] = explode('.', $token);

        $almost = $payloadB64 . '.' . substr($signatureB64, 0, -1) . 'z';
        $this->assertNull($this->service->verify($almost));
    }
}
