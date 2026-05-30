<?php

namespace HiEvents\Services\Application\Handlers\Order;

use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\Exceptions\ResourceConflictException;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Services\Application\Handlers\Order\DTO\RecordOrderPaymentDTO;
use HiEvents\Services\Domain\Order\RecordOrderPaymentService;
use Psr\Log\LoggerInterface;
use Throwable;

class RecordOrderPaymentHandler
{
    public function __construct(
        private readonly RecordOrderPaymentService $recordOrderPaymentService,
        private readonly LoggerInterface           $logger,
    )
    {
    }

    /**
     * @throws ResourceConflictException|ResourceNotFoundException|Throwable
     */
    public function handle(RecordOrderPaymentDTO $dto): OrderDomainObject
    {
        $this->logger->info(__('Recording order payment'), [
            'orderId' => $dto->orderId,
            'eventId' => $dto->eventId,
            'type' => $dto->type->value,
        ]);

        return $this->recordOrderPaymentService->record($dto);
    }
}
