<?php

namespace HiEvents\Http\Actions\Orders;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\Generated\OrderDomainObjectAbstract;
use HiEvents\DomainObjects\OrderItemDomainObject;
use HiEvents\DomainObjects\OrderPaymentDomainObject;
use HiEvents\DomainObjects\QuestionAndAnswerViewDomainObject;
use HiEvents\DomainObjects\StripePaymentDomainObject;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Repository\Eloquent\Value\OrderAndDirection;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Resources\Order\OrderResource;
use HiEvents\Services\Domain\Order\OrderBalanceService;
use Illuminate\Http\JsonResponse;

class GetOrderAction extends BaseAction
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly OrderBalanceService      $orderBalanceService,
    )
    {
    }

    /**
     * @throws ResourceNotFoundException
     */
    public function __invoke(int $eventId, int $orderId): JsonResponse
    {
        $this->isActionAuthorized($eventId, EventDomainObject::class);

        $order = $this->orderRepository
            ->loadRelation(OrderItemDomainObject::class)
            ->loadRelation(AttendeeDomainObject::class)
            ->loadRelation(OrderPaymentDomainObject::class)
            ->loadRelation(new Relationship(StripePaymentDomainObject::class, name: 'stripe_payment'))
            ->loadRelation(new Relationship(domainObject: QuestionAndAnswerViewDomainObject::class, orderAndDirections: [
                new OrderAndDirection(order: 'question_id'),
            ]))
            ->findFirstWhere([
                OrderDomainObjectAbstract::ID => $orderId,
                OrderDomainObjectAbstract::EVENT_ID => $eventId,
            ]);

        if ($order === null) {
            throw new ResourceNotFoundException(__('Order not found'));
        }

        $order->setPaymentBalance($this->orderBalanceService->getBalanceForOrder($order));

        return $this->resourceResponse(OrderResource::class, $order);
    }
}
