<?php

namespace HiEvents\Http\Actions\Messages;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\Generated\OutgoingMessageDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\OutgoingMessageEventDomainObjectAbstract;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\ResponseCodes;
use HiEvents\Repository\Eloquent\Value\OrderAndDirection;
use HiEvents\Repository\Interfaces\OutgoingMessageEventRepositoryInterface;
use HiEvents\Repository\Interfaces\OutgoingMessageRepositoryInterface;
use HiEvents\Resources\Message\OutgoingMessageEventResource;
use Illuminate\Http\JsonResponse;

class GetOutgoingMessageEventsAction extends BaseAction
{
    public function __construct(
        private readonly OutgoingMessageRepositoryInterface      $outgoingMessageRepository,
        private readonly OutgoingMessageEventRepositoryInterface $eventRepository,
    )
    {
    }

    public function __invoke(int $eventId, int $messageId): JsonResponse
    {
        $this->isActionAuthorized($eventId, EventDomainObject::class);

        $message = $this->outgoingMessageRepository->findFirstWhere([
            OutgoingMessageDomainObjectAbstract::ID => $messageId,
            OutgoingMessageDomainObjectAbstract::EVENT_ID => $eventId,
        ]);

        if (!$message) {
            return $this->errorResponse(__('Message not found'), ResponseCodes::HTTP_NOT_FOUND);
        }

        $events = $this->eventRepository->findWhere(
            where: [OutgoingMessageEventDomainObjectAbstract::OUTGOING_MESSAGE_ID => $messageId],
            orderAndDirections: [
                new OrderAndDirection(OutgoingMessageEventDomainObjectAbstract::OCCURRED_AT, OrderAndDirection::DIRECTION_ASC),
                new OrderAndDirection(OutgoingMessageEventDomainObjectAbstract::CREATED_AT, OrderAndDirection::DIRECTION_ASC),
            ],
        );

        return $this->resourceResponse(OutgoingMessageEventResource::class, $events);
    }
}
