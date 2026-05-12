<?php

namespace Tests\Unit\Services\Domain\Attendee;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\ProductRepositoryInterface;
use HiEvents\Services\Domain\Attendee\BundleSeatInfoPropagationService;
use Illuminate\Support\Collection;
use Mockery as m;
use Mockery\MockInterface;
use Tests\TestCase;

class BundleSeatInfoPropagationServiceTest extends TestCase
{
    private MockInterface|AttendeeRepositoryInterface $attendeeRepository;

    private MockInterface|ProductRepositoryInterface $productRepository;

    private BundleSeatInfoPropagationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->attendeeRepository = m::mock(AttendeeRepositoryInterface::class);
        $this->productRepository = m::mock(ProductRepositoryInterface::class);

        $this->service = new BundleSeatInfoPropagationService(
            $this->attendeeRepository,
            $this->productRepository,
        );
    }

    public function test_does_nothing_when_value_unchanged(): void
    {
        $this->productRepository->shouldNotReceive('findFirstWhere');
        $this->attendeeRepository->shouldNotReceive('findWhere');

        $this->service->propagate(
            attendeeId: 1,
            orderId: 10,
            productId: 100,
            eventId: 1000,
            newSeatInfo: 'Table 5',
            previousSeatInfo: 'Table 5',
        );
    }

    public function test_does_nothing_when_product_is_not_a_bundle(): void
    {
        $product = $this->mockProduct(1);
        $this->productRepository->shouldReceive('findFirstWhere')->once()->andReturn($product);
        $this->attendeeRepository->shouldNotReceive('findWhere');

        $this->service->propagate(
            attendeeId: 1,
            orderId: 10,
            productId: 100,
            eventId: 1000,
            newSeatInfo: 'Table 5',
            previousSeatInfo: null,
        );
    }

    public function test_does_not_propagate_when_edited_attendee_is_not_the_anchor(): void
    {
        $product = $this->mockProduct(2);
        $this->productRepository->shouldReceive('findFirstWhere')->once()->andReturn($product);

        // Anchor is attendee 1 (lowest id); we're editing attendee 2 (sibling).
        $siblings = new Collection([
            $this->mockAttendee(id: 1, seatInfo: null),
            $this->mockAttendee(id: 2, seatInfo: 'Table 9'),
        ]);
        $this->attendeeRepository->shouldReceive('findWhere')->once()->andReturn($siblings);
        $this->attendeeRepository->shouldNotReceive('updateByIdWhere');

        $this->service->propagate(
            attendeeId: 2,
            orderId: 10,
            productId: 100,
            eventId: 1000,
            newSeatInfo: 'Table 9',
            previousSeatInfo: null,
        );
    }

    public function test_anchor_edit_propagates_to_null_sibling(): void
    {
        $product = $this->mockProduct(2);
        $this->productRepository->shouldReceive('findFirstWhere')->once()->andReturn($product);

        $siblings = new Collection([
            $this->mockAttendee(id: 1, seatInfo: 'Table 5'),
            $this->mockAttendee(id: 2, seatInfo: null),
        ]);
        $this->attendeeRepository->shouldReceive('findWhere')->once()->andReturn($siblings);
        $this->attendeeRepository
            ->shouldReceive('updateByIdWhere')
            ->once()
            ->withArgs(function ($id, $attrs, $where) {
                return $id === 2 && $attrs['seat_info'] === 'Table 5' && $where['event_id'] === 1000;
            });

        $this->service->propagate(
            attendeeId: 1,
            orderId: 10,
            productId: 100,
            eventId: 1000,
            newSeatInfo: 'Table 5',
            previousSeatInfo: null,
        );
    }

    public function test_anchor_edit_propagates_to_sibling_with_matching_prior_value(): void
    {
        $product = $this->mockProduct(2);
        $this->productRepository->shouldReceive('findFirstWhere')->once()->andReturn($product);

        // Both anchor and sibling were at "Table 5"; anchor moves to "Table 7".
        // Sibling matches old anchor value, so it inherits.
        $siblings = new Collection([
            $this->mockAttendee(id: 1, seatInfo: 'Table 7'),
            $this->mockAttendee(id: 2, seatInfo: 'Table 5'),
        ]);
        $this->attendeeRepository->shouldReceive('findWhere')->once()->andReturn($siblings);
        $this->attendeeRepository
            ->shouldReceive('updateByIdWhere')
            ->once()
            ->withArgs(function ($id, $attrs) {
                return $id === 2 && $attrs['seat_info'] === 'Table 7';
            });

        $this->service->propagate(
            attendeeId: 1,
            orderId: 10,
            productId: 100,
            eventId: 1000,
            newSeatInfo: 'Table 7',
            previousSeatInfo: 'Table 5',
        );
    }

    public function test_anchor_edit_skips_customized_sibling(): void
    {
        $product = $this->mockProduct(2);
        $this->productRepository->shouldReceive('findFirstWhere')->once()->andReturn($product);

        // Sibling has a custom value that doesn't match the anchor's old value.
        $siblings = new Collection([
            $this->mockAttendee(id: 1, seatInfo: 'Table 7'),
            $this->mockAttendee(id: 2, seatInfo: 'Table 5 - Seat 2'),
        ]);
        $this->attendeeRepository->shouldReceive('findWhere')->once()->andReturn($siblings);
        $this->attendeeRepository->shouldNotReceive('updateByIdWhere');

        $this->service->propagate(
            attendeeId: 1,
            orderId: 10,
            productId: 100,
            eventId: 1000,
            newSeatInfo: 'Table 7',
            previousSeatInfo: 'Table 5',
        );
    }

    private function mockProduct(?int $minPerOrder): ProductDomainObject
    {
        $product = m::mock(ProductDomainObject::class);
        $product->shouldReceive('getMinPerOrder')->andReturn($minPerOrder);

        return $product;
    }

    private function mockAttendee(int $id, ?string $seatInfo): AttendeeDomainObject
    {
        $attendee = m::mock(AttendeeDomainObject::class);
        $attendee->shouldReceive('getId')->andReturn($id);
        $attendee->shouldReceive('getSeatInfo')->andReturn($seatInfo);

        return $attendee;
    }
}
