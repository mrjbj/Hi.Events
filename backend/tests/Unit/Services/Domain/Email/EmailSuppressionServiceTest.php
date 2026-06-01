<?php

namespace Tests\Unit\Services\Domain\Email;

use HiEvents\DomainObjects\EmailSuppressionDomainObject;
use HiEvents\DomainObjects\Status\EmailSuppressionReasonEnum;
use HiEvents\Repository\Interfaces\EmailSuppressionRepositoryInterface;
use HiEvents\Services\Domain\Email\EmailSuppressionService;
use Illuminate\Support\Collection;
use Mockery as m;
use Tests\TestCase;

class EmailSuppressionServiceTest extends TestCase
{
    private EmailSuppressionRepositoryInterface $repository;
    private EmailSuppressionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = m::mock(EmailSuppressionRepositoryInterface::class);
        $this->service = new EmailSuppressionService($this->repository);

        // Pin the placeholder patterns so tests don't depend on env config.
        config(['mail.suppressed_address_patterns' => ['unknown@unknown.com', '*@example.com', '*@noemail.*']]);
    }

    public function testIsEmailSuppressedReturnsFalseWhenFeatureDisabled(): void
    {
        config(['services.ses.suppression_enabled' => false]);

        $this->repository->shouldReceive('findByEmail')
            ->once()
            ->with('real@acme.test', 1)
            ->andReturn(new Collection([]));

        $this->assertFalse($this->service->isEmailSuppressed('real@acme.test', 1));
    }

    public function testIsEmailSuppressedReturnsFalseWhenNoSuppressionsExist(): void
    {
        config(['services.ses.suppression_enabled' => true]);

        $this->repository->shouldReceive('findByEmail')
            ->once()
            ->with('real@acme.test', 1)
            ->andReturn(new Collection([]));

        $this->assertFalse($this->service->isEmailSuppressed('real@acme.test', 1));
    }

    public function testPermanentBounceSuppressesAllEmailTypes(): void
    {
        config(['services.ses.suppression_enabled' => true]);

        $suppression = m::mock(EmailSuppressionDomainObject::class);
        $suppression->shouldReceive('getReason')->andReturn(EmailSuppressionReasonEnum::BOUNCE->value);
        $suppression->shouldReceive('getBounceType')->andReturn('Permanent');

        $this->repository->shouldReceive('findByEmail')
            ->andReturn(new Collection([$suppression]));

        $this->assertTrue($this->service->isEmailSuppressed('real@acme.test', 1, 'marketing'));
        $this->assertTrue($this->service->isEmailSuppressed('real@acme.test', 1, 'transactional'));
    }

    public function testTransientBounceSuppressesOnlyMarketing(): void
    {
        config(['services.ses.suppression_enabled' => true]);

        $suppression = m::mock(EmailSuppressionDomainObject::class);
        $suppression->shouldReceive('getReason')->andReturn(EmailSuppressionReasonEnum::BOUNCE->value);
        $suppression->shouldReceive('getBounceType')->andReturn('Transient');

        $this->repository->shouldReceive('findByEmail')
            ->andReturn(new Collection([$suppression]));

        $this->assertTrue($this->service->isEmailSuppressed('real@acme.test', 1, 'marketing'));
        $this->assertFalse($this->service->isEmailSuppressed('real@acme.test', 1, 'transactional'));
    }

    public function testComplaintSuppressesOnlyMarketing(): void
    {
        config(['services.ses.suppression_enabled' => true]);

        $suppression = m::mock(EmailSuppressionDomainObject::class);
        $suppression->shouldReceive('getReason')->andReturn(EmailSuppressionReasonEnum::COMPLAINT->value);

        $this->repository->shouldReceive('findByEmail')
            ->andReturn(new Collection([$suppression]));

        $this->assertTrue($this->service->isEmailSuppressed('real@acme.test', 1, 'marketing'));
        $this->assertFalse($this->service->isEmailSuppressed('real@acme.test', 1, 'transactional'));
    }

    public function testDoNotContactSuppressesAllTypesEvenWhenFeatureDisabled(): void
    {
        // The do-not-contact reason is independent of the SES feature flag.
        config(['services.ses.suppression_enabled' => false]);

        $suppression = m::mock(EmailSuppressionDomainObject::class);
        $suppression->shouldReceive('getReason')->andReturn(EmailSuppressionReasonEnum::DO_NOT_CONTACT->value);

        $this->repository->shouldReceive('findByEmail')
            ->andReturn(new Collection([$suppression]));

        $this->assertTrue($this->service->isEmailSuppressed('real@acme.test', 1, 'marketing'));
        $this->assertTrue($this->service->isEmailSuppressed('real@acme.test', 1, 'transactional'));
    }

    public function testPlaceholderAddressSuppressedWithoutDbRowAndRegardlessOfFlag(): void
    {
        config(['services.ses.suppression_enabled' => false]);

        // No findByEmail lookup needed — the placeholder check short-circuits first.
        $this->repository->shouldReceive('findByEmail')->never();

        $this->assertTrue($this->service->isEmailSuppressed('unknown@unknown.com', 1, 'transactional'));
        $this->assertTrue($this->service->isEmailSuppressed('Anyone@Example.com', 1, 'marketing'));
        $this->assertTrue($this->service->isEmailSuppressed('guest@noemail.local', 1, 'transactional'));
    }

    public function testIsPlaceholderAddressMatchesPatternsCaseInsensitively(): void
    {
        $this->assertTrue($this->service->isPlaceholderAddress('UNKNOWN@unknown.com'));
        $this->assertTrue($this->service->isPlaceholderAddress('jo@example.com'));
        $this->assertFalse($this->service->isPlaceholderAddress('real@acme.test'));
        $this->assertFalse($this->service->isPlaceholderAddress(''));
    }

    public function testSuppressEmailUsesFirstOrCreate(): void
    {
        $suppression = m::mock(EmailSuppressionDomainObject::class);

        $this->repository->shouldReceive('findOrCreateSuppression')
            ->once()
            ->withArgs(function ($unique, $additional) {
                return $unique['email'] === 'real@acme.test'
                    && $unique['reason'] === 'bounce'
                    && $additional['bounce_type'] === 'Permanent';
            })
            ->andReturn($suppression);

        $result = $this->service->suppressEmail(
            email: 'REAL@ACME.TEST',
            reason: 'bounce',
            source: 'ses_notification',
            accountId: 1,
            bounceType: 'Permanent',
        );

        $this->assertSame($suppression, $result);
    }

    public function testRemoveSuppressionSoftDeletesMatchingRecords(): void
    {
        $suppression1 = m::mock(EmailSuppressionDomainObject::class);
        $suppression1->shouldReceive('getId')->andReturn(1);
        $suppression2 = m::mock(EmailSuppressionDomainObject::class);
        $suppression2->shouldReceive('getId')->andReturn(2);

        $this->repository->shouldReceive('findWhere')
            ->once()
            ->with(['email' => 'real@acme.test', 'account_id' => 1])
            ->andReturn(new Collection([$suppression1, $suppression2]));

        $this->repository->shouldReceive('deleteById')->once()->with(1);
        $this->repository->shouldReceive('deleteById')->once()->with(2);

        $this->service->removeSuppression('real@acme.test', 1);
    }

    public function testRemoveSuppressionFiltersByReason(): void
    {
        $suppression = m::mock(EmailSuppressionDomainObject::class);
        $suppression->shouldReceive('getId')->andReturn(1);

        $this->repository->shouldReceive('findWhere')
            ->once()
            ->with(['email' => 'real@acme.test', 'reason' => 'do_not_contact'])
            ->andReturn(new Collection([$suppression]));

        $this->repository->shouldReceive('deleteById')->once()->with(1);

        $this->service->removeSuppression('real@acme.test', null, 'do_not_contact');
    }
}
