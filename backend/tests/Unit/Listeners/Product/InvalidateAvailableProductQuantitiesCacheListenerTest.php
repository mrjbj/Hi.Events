<?php

namespace Tests\Unit\Listeners\Product;

use HiEvents\DomainObjects\Enums\CapacityChangeDirection;
use HiEvents\Events\CapacityChangedEvent;
use HiEvents\Listeners\Product\InvalidateAvailableProductQuantitiesCacheListener;
use Illuminate\Contracts\Cache\Repository as Cache;
use Mockery as m;
use Tests\TestCase;

class InvalidateAvailableProductQuantitiesCacheListenerTest extends TestCase
{
    public function test_forgets_available_product_quantities_cache_for_event(): void
    {
        $cache = m::mock(Cache::class);
        $cache->shouldReceive('forget')
            ->once()
            ->with('event.42.available_product_quantities');

        $listener = new InvalidateAvailableProductQuantitiesCacheListener($cache);

        $listener->handle(new CapacityChangedEvent(
            eventId: 42,
            direction: CapacityChangeDirection::INCREASED,
        ));
    }

    public function test_invalidates_on_capacity_decrease_too(): void
    {
        $cache = m::mock(Cache::class);
        $cache->shouldReceive('forget')
            ->once()
            ->with('event.7.available_product_quantities');

        $listener = new InvalidateAvailableProductQuantitiesCacheListener($cache);

        $listener->handle(new CapacityChangedEvent(
            eventId: 7,
            direction: CapacityChangeDirection::DECREASED,
            productId: 99,
            productPriceId: 100,
            newCapacity: 5,
        ));
    }
}
