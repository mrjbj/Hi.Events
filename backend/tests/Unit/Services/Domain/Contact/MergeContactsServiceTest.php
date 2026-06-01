<?php

namespace Tests\Unit\Services\Domain\Contact;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\ContactDomainObject;
use HiEvents\DomainObjects\Generated\ContactDomainObjectAbstract;
use HiEvents\Exceptions\ContactMergeException;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\ContactRepositoryInterface;
use Illuminate\Database\DatabaseManager;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class MergeContactsServiceTest extends TestCase
{
    private ContactRepositoryInterface|MockInterface $contactRepository;
    private AttendeeRepositoryInterface|MockInterface $attendeeRepository;
    private \HiEvents\Services\Domain\Contact\MergeContactsService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->contactRepository = Mockery::mock(ContactRepositoryInterface::class);
        $this->attendeeRepository = Mockery::mock(AttendeeRepositoryInterface::class);

        $databaseManager = Mockery::mock(DatabaseManager::class);
        $databaseManager->shouldReceive('transaction')->andReturnUsing(fn ($cb) => $cb());

        $this->service = new \HiEvents\Services\Domain\Contact\MergeContactsService(
            $this->contactRepository,
            $this->attendeeRepository,
            $databaseManager,
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_reassigns_attendees_fills_gaps_and_retires_duplicate(): void
    {
        $survivor = $this->contact(1, firstName: 'Jane', lastName: '', attributes: ['county' => 'Hall'], processed: [10], ignored: []);
        $source = $this->contact(2, firstName: 'Janet', lastName: 'Doe', attributes: ['county' => 'Banks', 'role' => 'Volunteer'], processed: [11], ignored: [99]);

        $this->contactRepository->shouldReceive('findFirstWhere')
            ->with([ContactDomainObjectAbstract::ID => 1, ContactDomainObjectAbstract::ACCOUNT_ID => 7])
            ->andReturn($survivor);
        $this->contactRepository->shouldReceive('findFirstWhere')
            ->with([ContactDomainObjectAbstract::ID => 2, ContactDomainObjectAbstract::ACCOUNT_ID => 7])
            ->andReturn($source);

        $this->attendeeRepository->shouldReceive('updateWhere')
            ->once()
            ->with([AttendeeDomainObject::CONTACT_ID => 1], [AttendeeDomainObject::CONTACT_ID => 2]);

        $this->contactRepository->shouldReceive('updateFromArray')
            ->once()
            ->with(1, Mockery::on(function (array $update) {
                // Survivor's first name wins (not overwritten); last name is gap-filled.
                $firstNameUntouched = !array_key_exists(ContactDomainObjectAbstract::FIRST_NAME, $update);
                $lastNameFilled = ($update[ContactDomainObjectAbstract::LAST_NAME] ?? null) === 'Doe';
                // county kept (survivor), role gap-filled from source.
                $attrs = $update[ContactDomainObjectAbstract::ATTRIBUTES] ?? [];
                $attrsMerged = ($attrs['county'] ?? null) === 'Hall' && ($attrs['role'] ?? null) === 'Volunteer';
                // question id sets unioned.
                $processed = $update[ContactDomainObjectAbstract::PROCESSED_QUESTION_ANSWER_IDS] ?? [];
                $ignored = $update[ContactDomainObjectAbstract::IGNORED_QUESTION_ANSWER_IDS] ?? [];
                $idsUnioned = in_array(10, $processed, true) && in_array(11, $processed, true) && $ignored === [99];
                // history has a merge marker.
                $history = $update[ContactDomainObjectAbstract::ATTRIBUTES_HISTORY] ?? [];
                $last = end($history);
                $hasMarker = is_array($last) && ($last['type'] ?? null) === 'merge' && ($last['merged_contact_id'] ?? null) === 2;

                return $firstNameUntouched && $lastNameFilled && $attrsMerged && $idsUnioned && $hasMarker;
            }));

        $this->contactRepository->shouldReceive('deleteById')->once()->with(2);
        $this->contactRepository->shouldReceive('findById')->once()->with(1)->andReturn($survivor);

        $result = $this->service->merge(survivorId: 1, sourceId: 2, accountId: 7);

        $this->assertSame($survivor, $result);
    }

    public function test_rejects_self_merge(): void
    {
        $this->expectException(ContactMergeException::class);
        $this->service->merge(survivorId: 5, sourceId: 5, accountId: 7);
    }

    public function test_rejects_when_a_contact_is_missing(): void
    {
        $survivor = $this->contact(1);

        $this->contactRepository->shouldReceive('findFirstWhere')
            ->with([ContactDomainObjectAbstract::ID => 1, ContactDomainObjectAbstract::ACCOUNT_ID => 7])
            ->andReturn($survivor);
        $this->contactRepository->shouldReceive('findFirstWhere')
            ->with([ContactDomainObjectAbstract::ID => 2, ContactDomainObjectAbstract::ACCOUNT_ID => 7])
            ->andReturn(null);

        $this->attendeeRepository->shouldNotReceive('updateWhere');

        $this->expectException(ContactMergeException::class);
        $this->service->merge(survivorId: 1, sourceId: 2, accountId: 7);
    }

    private function contact(
        int $id,
        string $firstName = 'A',
        string $lastName = 'B',
        array $attributes = [],
        array $processed = [],
        array $ignored = [],
    ): ContactDomainObject|MockInterface {
        $contact = Mockery::mock(ContactDomainObject::class);
        $contact->shouldReceive('getId')->andReturn($id);
        $contact->shouldReceive('getEmail')->andReturn("contact{$id}@acme.test");
        $contact->shouldReceive('getFirstName')->andReturn($firstName);
        $contact->shouldReceive('getLastName')->andReturn($lastName);
        $contact->shouldReceive('getAttributes')->andReturn($attributes);
        $contact->shouldReceive('getAttributesHistory')->andReturn([]);
        $contact->shouldReceive('getProcessedQuestionAnswerIds')->andReturn($processed);
        $contact->shouldReceive('getIgnoredQuestionAnswerIds')->andReturn($ignored);

        return $contact;
    }
}
