<?php

namespace HiEvents\Services\Application\Handlers\Order;

use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\Exceptions\ResourceConflictException;
use HiEvents\Services\Application\Handlers\Order\DTO\MarkOrderAsPaidDTO;
use HiEvents\Services\Domain\Order\MarkOrderAsPaidService;
use Psr\Log\LoggerInterface;
use Throwable;

class MarkOrderAsPaidHandler
{
    public function __construct(
        private readonly MarkOrderAsPaidService $markOrderAsPaidService,
        private readonly LoggerInterface        $logger,
    )
    {
    }

    /**
     * @throws ResourceConflictException|Throwable
     */
    public function handle(MarkOrderAsPaidDTO $dto): OrderDomainObject
    {
        $this->logger->info(__('Marking order as paid'), [
            'orderId' => $dto->orderId,
            'eventId' => $dto->eventId,
            'paymentMethod' => $dto->paymentMethod->value,
            'collectedAmountProvided' => $dto->collectedAmount !== null,
        ]);

        return $this->markOrderAsPaidService->markOrderAsPaid($dto);
    }
}
