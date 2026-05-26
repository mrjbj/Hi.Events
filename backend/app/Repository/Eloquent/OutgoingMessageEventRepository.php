<?php

declare(strict_types=1);

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\OutgoingMessageEventDomainObject;
use HiEvents\Models\OutgoingMessageEvent;
use HiEvents\Repository\Interfaces\OutgoingMessageEventRepositoryInterface;

/**
 * @extends BaseRepository<OutgoingMessageEventDomainObject>
 */
class OutgoingMessageEventRepository extends BaseRepository implements OutgoingMessageEventRepositoryInterface
{
    protected function getModel(): string
    {
        return OutgoingMessageEvent::class;
    }

    public function getDomainObject(): string
    {
        return OutgoingMessageEventDomainObject::class;
    }
}
