<?php

namespace HiEvents\Http\Actions\Orders;

use HiEvents\DomainObjects\Enums\QuestionBelongsTo;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\OrderPaymentDomainObject;
use HiEvents\DomainObjects\QuestionAndAnswerViewDomainObject;
use HiEvents\DomainObjects\StripePaymentDomainObject;
use HiEvents\Exports\OrdersExport;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\DTO\QueryParamsDTO;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Repository\Interfaces\QuestionRepositoryInterface;
use HiEvents\Services\Domain\Order\OrderBalanceService;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ExportOrdersAction extends BaseAction
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly QuestionRepositoryInterface $questionRepository,
        private readonly OrdersExport $export,
        private readonly OrderBalanceService $orderBalanceService,
    ) {}

    public function __invoke(int $eventId): BinaryFileResponse
    {
        $this->isActionAuthorized($eventId, EventDomainObject::class);

        $orders = $this->orderRepository
            ->setMaxPerPage(10000)
            ->loadRelation(QuestionAndAnswerViewDomainObject::class)
            ->loadRelation(OrderPaymentDomainObject::class)
            ->loadRelation(new Relationship(StripePaymentDomainObject::class, name: 'stripe_payment'))
            ->findByEventId($eventId, new QueryParamsDTO(
                page: 1,
                per_page: 10000,
            ));

        // Derive the reconciliation balance from the already-loaded ledger +
        // Stripe relations (calculate(), not getBalanceForOrder(), to avoid an
        // N+1 over the export's up-to-10k orders).
        foreach ($orders as $order) {
            $order->setPaymentBalance(
                $this->orderBalanceService->calculate($order, $order->getOrderPayments() ?? collect())
            );
        }

        $questions = $this->questionRepository->findWhere([
            'event_id' => $eventId,
            'belongs_to' => QuestionBelongsTo::ORDER->name,
        ]);

        return Excel::download(
            $this->export->withData($orders, $questions),
            'orders.xlsx'
        );
    }
}
