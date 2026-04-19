<?php

namespace Tests\Unit\Services\Domain\Contact;

use HiEvents\DomainObjects\ContactDomainObject;
use HiEvents\Repository\Interfaces\ContactRepositoryInterface;
use HiEvents\Services\Domain\Contact\ContactLinkBuilder;
use HiEvents\Services\Domain\Contact\ContactSignedTokenService;
use Mockery as m;
use Tests\TestCase;

class ContactLinkBuilderTest extends TestCase
{
    private ContactRepositoryInterface $contactRepository;
    private ContactSignedTokenService $tokenService;
    private ContactLinkBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->contactRepository = m::mock(ContactRepositoryInterface::class);
        $this->tokenService = m::mock(ContactSignedTokenService::class);
        $this->builder = new ContactLinkBuilder($this->contactRepository, $this->tokenService);
    }

    public function testReturnsUrlUnchangedWhenContactNotFound(): void
    {
        $this->contactRepository->shouldReceive('findByEmailAndAccountId')->andReturn(null);

        $result = $this->builder->forRecipient('new@example.com', 7, 'https://example.com/event/1');

        $this->assertSame('https://example.com/event/1', $result);
    }

    public function testAppendsTokenWhenContactFoundWithNoExistingQuery(): void
    {
        $contact = m::mock(ContactDomainObject::class);
        $contact->shouldReceive('getId')->andReturn(42);
        $this->contactRepository->shouldReceive('findByEmailAndAccountId')->andReturn($contact);
        $this->tokenService->shouldReceive('generate')->with(42, 7)->andReturn('abc.def');

        $result = $this->builder->forRecipient('alice@example.com', 7, 'https://example.com/event/1');

        $this->assertSame('https://example.com/event/1?c=abc.def', $result);
    }

    public function testAppendsTokenPreservingExistingQueryString(): void
    {
        $contact = m::mock(ContactDomainObject::class);
        $contact->shouldReceive('getId')->andReturn(42);
        $this->contactRepository->shouldReceive('findByEmailAndAccountId')->andReturn($contact);
        $this->tokenService->shouldReceive('generate')->andReturn('abc.def');

        $result = $this->builder->forRecipient('alice@example.com', 7, 'https://example.com/event/1?promo=SAVE10');

        $this->assertSame('https://example.com/event/1?promo=SAVE10&c=abc.def', $result);
    }

    public function testUrlEncodesTokenParameter(): void
    {
        $contact = m::mock(ContactDomainObject::class);
        $contact->shouldReceive('getId')->andReturn(42);
        $this->contactRepository->shouldReceive('findByEmailAndAccountId')->andReturn($contact);
        $this->tokenService->shouldReceive('generate')->andReturn('abc+/=def');

        $result = $this->builder->forRecipient('alice@example.com', 7, 'https://example.com/event/1');

        $this->assertStringContainsString('c=abc%2B%2F%3Ddef', $result);
    }

    public function testReturnsUrlUnchangedOnRepositoryFailure(): void
    {
        $this->contactRepository
            ->shouldReceive('findByEmailAndAccountId')
            ->andThrow(new \RuntimeException('db down'));

        $result = $this->builder->forRecipient('alice@example.com', 7, 'https://example.com/event/1');

        $this->assertSame('https://example.com/event/1', $result);
    }

    public function testReturnsEmptyUrlWhenEmailEmpty(): void
    {
        $result = $this->builder->forRecipient('', 7, 'https://example.com/event/1');

        $this->assertSame('https://example.com/event/1', $result);
    }

    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }
}
