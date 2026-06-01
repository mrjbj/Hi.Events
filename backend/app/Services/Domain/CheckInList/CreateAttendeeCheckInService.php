<?php

namespace HiEvents\Services\Domain\CheckInList;

use Exception;
use HiEvents\DataTransferObjects\ErrorBagDTO;
use HiEvents\DomainObjects\AttendeeCheckInDomainObject;
use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\CheckInListDomainObject;
use HiEvents\DomainObjects\Enums\AttendeeCheckInActionType;
use HiEvents\DomainObjects\Enums\OfflinePaymentMethod;
use HiEvents\DomainObjects\Enums\PaymentTransactionType;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\Generated\AttendeeCheckInDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\OrderDomainObjectAbstract;
use HiEvents\DomainObjects\Status\AttendeeStatus;
use HiEvents\DomainObjects\StripePaymentDomainObject;
use HiEvents\Exceptions\CannotCheckInException;
use HiEvents\Helper\DateHelper;
use HiEvents\Helper\IdHelper;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Repository\Interfaces\AttendeeCheckInRepositoryInterface;
use HiEvents\Repository\Interfaces\EventSettingsRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Services\Application\Handlers\CheckInList\Public\DTO\AttendeeAndActionDTO;
use HiEvents\Services\Application\Handlers\Order\DTO\MarkOrderAsPaidDTO;
use HiEvents\Services\Application\Handlers\Order\DTO\RecordOrderPaymentDTO;
use HiEvents\Services\Domain\CheckInList\DTO\CheckInResultDTO;
use HiEvents\Services\Domain\CheckInList\DTO\CreateAttendeeCheckInsResponseDTO;
use HiEvents\Services\Domain\Order\MarkOrderAsPaidService;
use HiEvents\Services\Domain\Order\OrderBalanceService;
use HiEvents\Services\Domain\Order\RecordOrderPaymentService;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;
use Throwable;

class CreateAttendeeCheckInService
{
    public function __construct(
        private readonly AttendeeCheckInRepositoryInterface $attendeeCheckInRepository,
        private readonly CheckInListDataService $checkInListDataService,
        private readonly EventSettingsRepositoryInterface $eventSettingsRepository,
        private readonly ConnectionInterface $db,
        private readonly MarkOrderAsPaidService $markOrderAsPaidService,
        private readonly RecordOrderPaymentService $recordOrderPaymentService,
        private readonly OrderBalanceService $orderBalanceService,
        private readonly OrderRepositoryInterface $orderRepository,
    ) {}

    /**
     * @param  Collection<int, AttendeeAndActionDTO>  $attendeesAndActions
     *
     * @throws CannotCheckInException
     * @throws Exception|Throwable
     */
    public function checkInAttendees(
        string $checkInListUuid,
        string $checkInUserIpAddress,
        Collection $attendeesAndActions
    ): CreateAttendeeCheckInsResponseDTO {
        $checkInList = $this->checkInListDataService->getCheckInList($checkInListUuid);
        $this->validateCheckInListIsActive($checkInList);

        $attendees = $this->fetchAttendees($attendeesAndActions);
        $eventSettings = $this->fetchEventSettings($checkInList->getEventId());
        $existingCheckIns = $this->fetchExistingCheckIns($attendees, $checkInList);

        return $this->processAttendeeCheckIns(
            $attendees,
            $attendeesAndActions,
            $checkInList,
            $eventSettings,
            $existingCheckIns,
            $checkInUserIpAddress
        );
    }

    /**
     * @throws CannotCheckInException
     */
    private function validateCheckInListIsActive(CheckInListDomainObject $checkInList): void
    {
        if ($checkInList->getExpiresAt() && DateHelper::utcDateIsPast($checkInList->getExpiresAt())) {
            throw new CannotCheckInException(__('Check-in list has expired'));
        }

        if ($checkInList->getActivatesAt() && DateHelper::utcDateIsFuture($checkInList->getActivatesAt())) {
            throw new CannotCheckInException(__('Check-in list is not active yet'));
        }
    }

    /**
     * @param  Collection<int, AttendeeAndActionDTO>  $attendeesAndActions
     * @return Collection<int, AttendeeDomainObject>
     *
     * @throws CannotCheckInException
     */
    private function fetchAttendees(Collection $attendeesAndActions): Collection
    {
        $publicIds = $attendeesAndActions->map(
            fn (AttendeeAndActionDTO $attendeeAndAction) => $attendeeAndAction->public_id
        );

        return $this->checkInListDataService->getAttendees($publicIds);
    }

    private function fetchEventSettings(int $eventId): EventSettingDomainObject
    {
        return $this->eventSettingsRepository->findFirstWhere([
            'event_id' => $eventId,
        ]);
    }

    /**
     * @param  Collection<int, AttendeeDomainObject>  $attendees
     *
     * @throws Exception
     */
    private function fetchExistingCheckIns(Collection $attendees, CheckInListDomainObject $checkInList): Collection
    {
        $attendeeIds = $attendees->map(fn (AttendeeDomainObject $attendee) => $attendee->getId())->toArray();

        return $this->attendeeCheckInRepository->findWhereIn(
            field: AttendeeCheckInDomainObjectAbstract::ATTENDEE_ID,
            values: $attendeeIds,
            additionalWhere: [
                AttendeeCheckInDomainObjectAbstract::EVENT_ID => $checkInList->getEventId(),
                AttendeeCheckInDomainObjectAbstract::CHECK_IN_LIST_ID => $checkInList->getId(),
            ],
        );
    }

    /**
     * @throws Throwable
     * @throws CannotCheckInException
     */
    private function processAttendeeCheckIns(
        Collection $attendees,
        Collection $attendeesAndActions,
        CheckInListDomainObject $checkInList,
        EventSettingDomainObject $eventSettings,
        Collection $existingCheckIns,
        string $checkInUserIpAddress
    ): CreateAttendeeCheckInsResponseDTO {
        $errors = new ErrorBagDTO;
        $checkIns = new Collection;

        foreach ($attendees as $attendee) {
            $result = $this->processIndividualCheckIn(
                $attendee,
                $attendeesAndActions,
                $checkInList,
                $eventSettings,
                $existingCheckIns,
                $checkInUserIpAddress
            );

            if ($result->checkIn) {
                $checkIns->push($result->checkIn);
            }
            if ($result->error) {
                $errors->addError($attendee->getPublicId(), $result->error);
            }
        }

        return new CreateAttendeeCheckInsResponseDTO(
            attendeeCheckIns: $checkIns,
            errors: $errors,
        );
    }

    /**
     * @throws Throwable
     * @throws CannotCheckInException
     */
    private function processIndividualCheckIn(
        AttendeeDomainObject $attendee,
        Collection $attendeesAndActions,
        CheckInListDomainObject $checkInList,
        EventSettingDomainObject $eventSettings,
        Collection $existingCheckIns,
        string $checkInUserIpAddress
    ): CheckInResultDTO {
        $this->checkInListDataService->verifyAttendeeBelongsToCheckInList($checkInList, $attendee);

        $attendeeAction = $attendeesAndActions->first(
            fn (AttendeeAndActionDTO $action) => $action->public_id === $attendee->getPublicId()
        );
        $checkInAction = $attendeeAction->action;

        if ($existingCheckIn = $this->getExistingCheckIn($existingCheckIns, $attendee)) {
            return new CheckInResultDTO(
                checkIn: $existingCheckIn,
                error: __('Attendee :attendee_name is already checked in', [
                    'attendee_name' => $attendee->getFullName(),
                ])
            );
        }

        if ($error = $this->validateAttendeeStatus($attendee, $checkInAction, $eventSettings)) {
            return new CheckInResultDTO(error: $error);
        }

        return $this->db->transaction(function () use ($attendee, $checkInList, $checkInAction, $checkInUserIpAddress, $attendeeAction) {
            $checkIn = $this->createCheckIn($attendee, $checkInList, $checkInUserIpAddress);

            if ($checkInAction->value === AttendeeCheckInActionType::CHECK_IN_AND_MARK_ORDER_AS_PAID->value) {
                $this->recordDoorPayment($attendee, $attendeeAction, $checkInUserIpAddress);
            }

            return new CheckInResultDTO(checkIn: $checkIn);
        });
    }

    /**
     * Record a payment collected at the door against the order's ledger.
     *
     * The agent enters the amount actually received (cash/card/Square), which may
     * be the full balance, more (can't make change — excess is surfaced as
     * overpaid), or less (short — the order stays awaiting payment while the
     * attendee is still admitted). Comp/write-off is deliberately NOT available
     * here: the door link is unauthenticated, and only reconcilable money may be
     * recorded over it — forgiving a balance stays on the authenticated
     * manage-order surface.
     *
     * @throws Throwable
     */
    private function recordDoorPayment(
        AttendeeDomainObject $attendee,
        AttendeeAndActionDTO $attendeeAction,
        string $checkInUserIpAddress
    ): void {
        $method = $attendeeAction->payment_method ?? OfflinePaymentMethod::OTHER;

        $order = $this->orderRepository
            ->loadRelation(new Relationship(StripePaymentDomainObject::class, name: 'stripe_payment'))
            ->findFirstWhere([OrderDomainObjectAbstract::ID => $attendee->getOrderId()]);

        $outstanding = max(0.0, $this->orderBalanceService->getBalanceForOrder($order)->balance);
        $amount = $attendeeAction->amount !== null ? round($attendeeAction->amount, 2) : $outstanding;

        if ($amount <= 0.0) {
            return;
        }

        if ($amount >= $outstanding - 0.001) {
            $this->markOrderAsPaidService->markOrderAsPaid(new MarkOrderAsPaidDTO(
                eventId: $attendee->getEventId(),
                orderId: $attendee->getOrderId(),
                paymentMethod: $method,
                paymentReference: $attendeeAction->payment_reference,
                amountReceived: $amount,
                recordedByIp: $checkInUserIpAddress,
            ));

            return;
        }

        $this->recordOrderPaymentService->record(new RecordOrderPaymentDTO(
            eventId: $attendee->getEventId(),
            orderId: $attendee->getOrderId(),
            transactionType: PaymentTransactionType::PAYMENT,
            amount: $amount,
            paymentMethod: $method,
            reference: $attendeeAction->payment_reference,
            recordedByIp: $checkInUserIpAddress,
        ));
    }

    private function getExistingCheckIn(Collection $existingCheckIns, AttendeeDomainObject $attendee): ?object
    {
        return $existingCheckIns->first(
            fn ($checkIn) => $checkIn->getAttendeeId() === $attendee->getId()
        );
    }

    private function validateAttendeeStatus(
        AttendeeDomainObject $attendee,
        AttendeeCheckInActionType $checkInAction,
        EventSettingDomainObject $eventSettings
    ): ?string {
        $allowAttendeesAwaitingPaymentToCheckIn = $eventSettings->getAllowOrdersAwaitingOfflinePaymentToCheckIn();

        if ($attendee->getStatus() === AttendeeStatus::CANCELLED->name) {
            return __('Attendee :attendee_name\'s ticket is cancelled', [
                'attendee_name' => $attendee->getFullName(),
            ]);
        }

        if (! $allowAttendeesAwaitingPaymentToCheckIn) {
            if ($checkInAction->value === AttendeeCheckInActionType::CHECK_IN->value
                && $attendee->getStatus() === AttendeeStatus::AWAITING_PAYMENT->name
            ) {
                return __('Unable to check in as attendee :attendee_name\'s order is awaiting payment', [
                    'attendee_name' => $attendee->getFullName(),
                ]);
            }

            if ($checkInAction->value === AttendeeCheckInActionType::CHECK_IN_AND_MARK_ORDER_AS_PAID->value) {
                return __('Attendee :attendee_name\'s order cannot be marked as paid. Please check your event settings', [
                    'attendee_name' => $attendee->getFullName(),
                ]);
            }
        }

        return null;
    }

    private function createCheckIn(
        AttendeeDomainObject $attendee,
        CheckInListDomainObject $checkInList,
        string $checkInUserIpAddress
    ): AttendeeCheckInDomainObject {
        return $this->attendeeCheckInRepository->create([
            AttendeeCheckInDomainObjectAbstract::ORDER_ID => $attendee->getOrderId(),
            AttendeeCheckInDomainObjectAbstract::ATTENDEE_ID => $attendee->getId(),
            AttendeeCheckInDomainObjectAbstract::CHECK_IN_LIST_ID => $checkInList->getId(),
            AttendeeCheckInDomainObjectAbstract::IP_ADDRESS => $checkInUserIpAddress,
            AttendeeCheckInDomainObjectAbstract::PRODUCT_ID => $attendee->getProductId(),
            AttendeeCheckInDomainObjectAbstract::SHORT_ID => IdHelper::shortId(IdHelper::CHECK_IN_PREFIX),
            AttendeeCheckInDomainObjectAbstract::EVENT_ID => $checkInList->getEventId(),
        ]);
    }
}
