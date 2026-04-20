<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Contact;

use HiEvents\Repository\Interfaces\ContactRepositoryInterface;
use Illuminate\Support\Facades\Log;

/**
 * Turns ordinary public URLs into token-carrying prefill links for known
 * contacts. Used from mailables that address a specific recipient so the
 * click-through can auto-fill the checkout form or land them on their
 * self-service profile without re-entering their email.
 *
 * Usage:
 *     $url = $contactLinkBuilder->forRecipient('alice@example.com', $accountId,
 *         'https://events.example.com/event/42/register');
 *     // → 'https://events.example.com/event/42/register?c=<signed-token>'
 *
 * Returns the input URL unchanged when the email doesn't match any contact
 * in the account (new customers don't need a token).
 */
class ContactLinkBuilder
{
    public function __construct(
        private readonly ContactRepositoryInterface $contactRepository,
        private readonly ContactSignedTokenService $tokenService,
    ) {}

    public function forRecipient(string $email, int $accountId, string $url): string
    {
        if ($email === '' || $url === '') {
            return $url;
        }

        try {
            $contact = $this->contactRepository->findByEmailAndAccountId($email, $accountId);
        } catch (\Throwable $e) {
            Log::warning('ContactLinkBuilder: lookup failed, returning unsigned URL', [
                'account_id' => $accountId,
                'error' => $e->getMessage(),
            ]);

            return $url;
        }

        if ($contact === null) {
            return $url;
        }

        $token = $this->tokenService->generate(
            contactId: $contact->getId(),
            accountId: $accountId,
        );

        return $this->appendTokenParam($url, $token);
    }

    private function appendTokenParam(string $url, string $token): string
    {
        $separator = str_contains($url, '?') ? '&' : '?';

        return $url . $separator . 'c=' . rawurlencode($token);
    }
}
