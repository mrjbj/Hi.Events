<?php

namespace HiEvents\Services\Application\Handlers\Order;

use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\Exceptions\ResourceConflictException;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Services\Application\Handlers\Order\DTO\ReverseOrderPaymentDTO;
use HiEvents\Services\Domain\Order\ReverseOrderPaymentService;
use Psr\Log\LoggerInterface;
use Throwable;

class ReverseOrderPaymentHandler
{
    public function __construct(
        private readonly ReverseOrderPaymentService $reverseOrderPaymentService,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @throws ResourceConflictException|ResourceNotFoundException|Throwable
     */
    public function handle(ReverseOrderPaymentDTO $dto): OrderDomainObject
    {
        $this->logger->info(__('Reversing order payment'), [
            'orderId' => $dto->orderId,
            'eventId' => $dto->eventId,
            'paymentId' => $dto->paymentId,
        ]);

        return $this->reverseOrderPaymentService->reverse($dto);
    }
}
