<?php

namespace Tests\Unit\Services\Domain\Contact;

use HiEvents\Repository\Interfaces\ContactRepositoryInterface;
use HiEvents\Repository\Interfaces\QuestionAnswerRepositoryInterface;
use HiEvents\Services\Domain\Contact\ContactAutofillService;
use Mockery as m;
use Tests\TestCase;

class ContactAutofillServiceTest extends TestCase
{
    public function testConstructsWithRequiredDependencies(): void
    {
        $service = new ContactAutofillService(
            m::mock(ContactRepositoryInterface::class),
            m::mock(QuestionAnswerRepositoryInterface::class),
        );

        $this->assertInstanceOf(ContactAutofillService::class, $service);
    }

    // fillOrderBlanksFromContact is heavy on direct DB facade queries (questions,
    // contact_attribute_definitions, orders, attendees, question_answers joins)
    // and is better covered by feature/integration tests once those exist in the
    // project. The public response-shape guarantee (no question_answers field
    // leaking) is covered by LookupContactByEmailPublicHandlerTest.

    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }
}
