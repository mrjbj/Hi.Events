<?php

namespace HiEvents\Listeners\Product;

use HiEvents\Events\CapacityChangedEvent;
use Illuminate\Contracts\Cache\Repository as Cache;

class InvalidateAvailableProductQuantitiesCacheListener
{
    public function __construct(
        private readonly Cache $cache,
    )
    {
    }

    public function handle(CapacityChangedEvent $event): void
    {
        $this->cache->forget("event.{$event->eventId}.available_product_quantities");
    }
}
