<?php

namespace HiEvents\Services\Domain\Mail;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\ContactDomainObject;
use HiEvents\DomainObjects\Enums\MessageTypeEnum;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\Status\AttendeeStatus;
use HiEvents\DomainObjects\Status\MessageStatus;
use HiEvents\Exceptions\UnableToSendMessageException;
use HiEvents\Jobs\Event\SendEventEmailJob;
use HiEvents\Mail\Event\EventMessage;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\DomainObjects\Generated\OutgoingMessageDomainObjectAbstract;
use HiEvents\DomainObjects\Status\OutgoingMessageStatus;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\MessageRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Repository\Interfaces\OutgoingMessageRepositoryInterface;
use HiEvents\Repository\Interfaces\UserRepositoryInterface;
use HiEvents\Services\Application\Handlers\Message\DTO\SendMessageDTO;
use HiEvents\Services\Domain\Email\EmailSuppressionService;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Collection;
use Symfony\Component\HttpKernel\Log\Logger;

class SendEventEmailMessagesService
{
    private array $sentEmails = [];

    public function __construct(
        private readonly OrderRepositoryInterface    $orderRepository,
        private readonly AttendeeRepositoryInterface $attendeeRepository,
        private readonly EventRepositoryInterface    $eventRepository,
        private readonly MessageRepositoryInterface  $messageRepository,
        private readonly UserRepositoryInterface     $userRepository,
        private readonly Logger                      $logger,
        private readonly Dispatcher                          $dispatcher,
        private readonly EmailSuppressionService             $emailSuppressionService,
        private readonly OutgoingMessageRepositoryInterface  $outgoingMessageRepository,
    )
    {
    }

    /**
     * @throws UnableToSendMessageException
     */
    public function send(SendMessageDTO $messageData): void
    {
        $event = $this->eventRepository
            ->loadRelation(EventSettingDomainObject::class)
            ->loadRelation(new Relationship(
                domainObject: OrganizerDomainObject::class,
                name: 'organizer'
            ))
            ->findById($messageData->event_id);

        $order = $this->orderRepository->findFirstWhere([
            'id' => $messageData->order_id,
            'event_id' => $messageData->event_id,
        ]);

        if ((!$order && $messageData->type === MessageTypeEnum::ORDER_OWNER) || !$messageData->id) {
            $message = 'Unable to send message. Order or message ID not present.';
            $this->logger->error($message, $messageData->toArray());
            $this->updateMessageStatus($messageData, MessageStatus::FAILED);

            throw new UnableToSendMessageException($message);
        }

        switch ($messageData->type) {
            case MessageTypeEnum::INDIVIDUAL_ATTENDEES:
                $this->sendAttendeeMessages($messageData, $event);
                break;
            case MessageTypeEnum::ORDER_OWNER:
                $this->sendOrderMessages($messageData, $event, $order);
                break;
            case MessageTypeEnum::TICKET_HOLDERS:
                $this->sendTicketHolderMessages($messageData, $event);
                break;
            case MessageTypeEnum::ALL_ATTENDEES:
                $this->sendEventMessages($messageData, $event);
                break;
            case MessageTypeEnum::ORDER_OWNERS_WITH_PRODUCT:
                $this->sendProductMessages($messageData, $event);
                break;
            case MessageTypeEnum::CHECKED_IN_ATTENDEES:
                $this->sendCheckedInMessages($messageData, $event);
                break;
            case MessageTypeEnum::NOT_CHECKED_IN_ATTENDEES:
                $this->sendNotCheckedInMessages($messageData, $event);
                break;
        }

        $this->updateMessageStatus($messageData, MessageStatus::SENT);
    }

    private function sendAttendeeMessages(SendMessageDTO $messageData, EventDomainObject $event): void
    {
        $attendees = $this->attendeesQuery()->findWhereIn(
            field: 'id',
            values: $messageData->attendee_ids,
            additionalWhere: [
                'event_id' => $messageData->event_id,
            ],
            columns: $this->attendeeColumns(),
        );

        $this->emailAttendees($attendees, $messageData, $event);
    }

    private function sendTicketHolderMessages(SendMessageDTO $messageData, EventDomainObject $event): void
    {
        $attendees = $this->attendeesQuery()->findWhereIn(
            field: 'product_id',
            values: $messageData->product_ids,
            additionalWhere: [
                'event_id' => $messageData->event_id,
                'status' => AttendeeStatus::ACTIVE->name,
            ],
            columns: $this->attendeeColumns(),
        );

        $this->emailAttendees($attendees, $messageData, $event);
    }

    private function sendOrderMessages(
        SendMessageDTO    $messageData,
        EventDomainObject $event,
        OrderDomainObject $order,
    ): void
    {
        $this->sendEmailToMessageSender($messageData, $event);

        $this->sendMessage(
            emailAddress: $order->getEmail(),
            fullName: $order->getFullName(),
            messageData: $messageData,
            event: $event,
        );
    }

    private function emailAttendees(
        Collection        $attendees,
        SendMessageDTO    $messageData,
        EventDomainObject $event,
    ): void
    {
        $this->sendEmailToMessageSender($messageData, $event);

        if ($messageData->is_test) {
            return;
        }

        $sentEmails = [];
        $attendees->each(function (AttendeeDomainObject $attendee) use (&$sentEmails, $event, $messageData) {
            $email = $this->resolveAttendeeEmail($attendee, $messageData);
            if (in_array($email, $sentEmails, true)) {
                return;
            }

            $sentEmails[] = $email;

            $this->sendMessage(
                emailAddress: $email,
                fullName: $attendee->getFullName(),
                messageData: $messageData,
                event: $event,
            );
        });
    }

    /**
     * Cross-event announcements (audience event != promoted event) prefer the
     * Contact's current email so cleanups done in the Resolve modal compound
     * across all future sends. Same-event and transactional sends use the
     * attendee's email verbatim.
     */
    private function resolveAttendeeEmail(AttendeeDomainObject $attendee, SendMessageDTO $messageData): string
    {
        $promotes = $messageData->promotes_event_id;
        $isCrossEvent = $promotes !== null && (int)$promotes !== (int)$messageData->event_id;
        if (!$isCrossEvent) {
            return $attendee->getEmail();
        }

        if ($attendee->getContactId() === null || $attendee->getContactLinkIgnoredAt() !== null) {
            return $attendee->getEmail();
        }

        $contact = $attendee->getContact();
        return $contact?->getEmail() ?? $attendee->getEmail();
    }

    private function attendeesQuery(): AttendeeRepositoryInterface
    {
        return $this->attendeeRepository->loadRelation(
            new Relationship(ContactDomainObject::class, name: 'contact'),
        );
    }

    /**
     * @return string[] columns required for resolveAttendeeEmail() + send.
     */
    private function attendeeColumns(): array
    {
        return ['id', 'first_name', 'last_name', 'email', 'contact_id', 'contact_link_ignored_at', 'event_id'];
    }

    private function updateMessageStatus(SendMessageDTO $messageData, MessageStatus $status): void
    {
        $attributes = [
            'status' => $status->name,
        ];

        if ($status === MessageStatus::SENT) {
            $attributes['sent_at'] = now()->toDateTimeString();
        }

        $this->messageRepository->updateWhere(
            attributes: $attributes,
            where: [
                'id' => $messageData->id,
            ]
        );
    }

    /**
     * @todo - Load test this. Events can have a lot of attendees.
     */
    private function sendEventMessages(SendMessageDTO $messageData, EventDomainObject $event): void
    {
        $attendees = $this->attendeesQuery()->findWhere(
            where: [
                'event_id' => $messageData->event_id,
                'status' => AttendeeStatus::ACTIVE->name,
            ],
            columns: $this->attendeeColumns(),
        );

        $this->emailAttendees($attendees, $messageData, $event);
    }

    private function sendCheckedInMessages(SendMessageDTO $messageData, EventDomainObject $event): void
    {
        $attendees = $this->attendeesQuery()->findCheckedInAttendees(
            eventId: $messageData->event_id,
            checkInListId: $messageData->check_in_list_id,
            columns: $this->attendeeColumns(),
        );

        $this->emailAttendees($attendees, $messageData, $event);
    }

    private function sendNotCheckedInMessages(SendMessageDTO $messageData, EventDomainObject $event): void
    {
        $attendees = $this->attendeesQuery()->findNotCheckedInAttendees(
            eventId: $messageData->event_id,
            checkInListId: $messageData->check_in_list_id,
            columns: $this->attendeeColumns(),
        );

        $this->emailAttendees($attendees, $messageData, $event);
    }

    private function sendEmailToMessageSender(SendMessageDTO $messageData, EventDomainObject $event): void
    {
        if (!$messageData->send_copy_to_current_user && !$messageData->is_test) {
            return;
        }

        $user = $this->userRepository->findById($messageData->sent_by_user_id);

        $this->sendMessage(
            emailAddress: $user->getEmail(),
            fullName: $user->getFullName(),
            messageData: $messageData,
            event: $event,
        );
    }

    private function sendProductMessages(SendMessageDTO $messageData, EventDomainObject $event): void
    {
        $orders = $this->orderRepository->findOrdersAssociatedWithProducts(
            eventId: $messageData->event_id,
            productIds: $messageData->product_ids,
            orderStatuses: $messageData->order_statuses
        );

        if ($orders->isEmpty()) {
            return;
        }

        $this->sendEmailToMessageSender($messageData, $event);

        $orders->each(function (OrderDomainObject $order) use ($messageData, $event) {
            $this->sendMessage(
                emailAddress: $order->getEmail(),
                fullName: $order->getFullName(),
                messageData: $messageData,
                event: $event,
            );
        });
    }

    /**
     * Returns the deduped, lowercase list of emails that would receive this
     * message if it were sent now. Mirrors the routing in send() but skips
     * dispatch, suppression checks, and the "copy to sender" / "test" paths.
     * Used by the preflight endpoint to warn senders about unresolved bounces
     * in the upcoming audience.
     *
     * @return string[]
     */
    public function resolveAudienceEmails(SendMessageDTO $messageData): array
    {
        $event = $this->eventRepository
            ->loadRelation(EventSettingDomainObject::class)
            ->findById($messageData->event_id);
        if ($event === null) {
            return [];
        }

        $emails = match ($messageData->type) {
            MessageTypeEnum::INDIVIDUAL_ATTENDEES => $this->emailsFromAttendees(
                $this->attendeesQuery()->findWhereIn('id', $messageData->attendee_ids, [
                    'event_id' => $messageData->event_id,
                ], $this->attendeeColumns()),
                $messageData,
            ),
            MessageTypeEnum::TICKET_HOLDERS => $this->emailsFromAttendees(
                $this->attendeesQuery()->findWhereIn('product_id', $messageData->product_ids, [
                    'event_id' => $messageData->event_id,
                    'status' => AttendeeStatus::ACTIVE->name,
                ], $this->attendeeColumns()),
                $messageData,
            ),
            MessageTypeEnum::ALL_ATTENDEES => $this->emailsFromAttendees(
                $this->attendeesQuery()->findWhere([
                    'event_id' => $messageData->event_id,
                    'status' => AttendeeStatus::ACTIVE->name,
                ], $this->attendeeColumns()),
                $messageData,
            ),
            MessageTypeEnum::CHECKED_IN_ATTENDEES => $this->emailsFromAttendees(
                $this->attendeesQuery()->findCheckedInAttendees(
                    $messageData->event_id,
                    $messageData->check_in_list_id,
                    $this->attendeeColumns(),
                ),
                $messageData,
            ),
            MessageTypeEnum::NOT_CHECKED_IN_ATTENDEES => $this->emailsFromAttendees(
                $this->attendeesQuery()->findNotCheckedInAttendees(
                    $messageData->event_id,
                    $messageData->check_in_list_id,
                    $this->attendeeColumns(),
                ),
                $messageData,
            ),
            MessageTypeEnum::ORDER_OWNER => array_filter([
                $this->orderRepository->findFirstWhere([
                    'id' => $messageData->order_id,
                    'event_id' => $messageData->event_id,
                ])?->getEmail(),
            ]),
            MessageTypeEnum::ORDER_OWNERS_WITH_PRODUCT => $this->orderRepository
                ->findOrdersAssociatedWithProducts(
                    eventId: $messageData->event_id,
                    productIds: $messageData->product_ids,
                    orderStatuses: $messageData->order_statuses,
                )
                ->map(fn (OrderDomainObject $order) => $order->getEmail())
                ->all(),
        };

        return array_values(array_unique(array_map(
            fn (string $e) => strtolower(trim($e)),
            array_filter($emails),
        )));
    }

    /**
     * @param Collection<AttendeeDomainObject> $attendees
     * @return string[]
     */
    private function emailsFromAttendees(Collection $attendees, SendMessageDTO $messageData): array
    {
        return $attendees
            ->map(fn (AttendeeDomainObject $attendee) => $this->resolveAttendeeEmail($attendee, $messageData))
            ->all();
    }

    private function sendMessage(
        string            $emailAddress,
        string            $fullName,
        SendMessageDTO    $messageData,
        EventDomainObject $event,
    ): void
    {
        if (in_array($emailAddress, $this->sentEmails, true)) {
            return;
        }

        if ($this->emailSuppressionService->isEmailSuppressed($emailAddress, $messageData->account_id, 'marketing')) {
            $this->logger->info('Email suppressed, skipping dispatch', [
                'email' => $emailAddress,
                'message_id' => $messageData->id,
            ]);

            $this->outgoingMessageRepository->create([
                OutgoingMessageDomainObjectAbstract::MESSAGE_ID => $messageData->id,
                OutgoingMessageDomainObjectAbstract::EVENT_ID => $messageData->event_id,
                OutgoingMessageDomainObjectAbstract::STATUS => OutgoingMessageStatus::SUPPRESSED->name,
                OutgoingMessageDomainObjectAbstract::RECIPIENT => $emailAddress,
                OutgoingMessageDomainObjectAbstract::SUBJECT => $messageData->subject,
            ]);

            $this->sentEmails[] = $emailAddress;
            return;
        }

        $this->dispatcher->dispatch(
            new SendEventEmailJob(
                email: $emailAddress,
                toName: $fullName,
                eventMessage: new EventMessage(
                    event: $event,
                    eventSettings: $event->getEventSettings(),
                    messageData: $messageData
                ),
                messageData: $messageData,
            )
        );

        $this->sentEmails[] = $emailAddress;
    }
}
