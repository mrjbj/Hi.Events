import { t } from "@lingui/macro";
import { NavLink, useNavigate, useParams, useLocation } from "react-router";
import { ActionIcon, Alert, Button, Collapse, Group, Popover, SimpleGrid, Text, Tooltip, UnstyledButton } from "@mantine/core";
import {
  IconAlertTriangle,
  IconBuilding,
  IconCalendar,
  IconCalendarEvent,
  IconCash,
  IconCheck,
  IconChevronDown,
  IconChevronUp,
  IconClock,
  IconCreditCard,
  IconEdit,
  IconId,
  IconInfoCircle,
  IconMail,
  IconMapPin,
  IconMenuOrder,
  IconPrinter,
  IconSend,
  IconTicket,
  IconUser,
  IconUserPlus,
} from "@tabler/icons-react";
import { useEffect, useState } from "react";
import { useQueryClient } from "@tanstack/react-query";

import { useGetOrderPublic, GET_ORDER_PUBLIC_QUERY_KEY } from "../../../../queries/useGetOrderPublic.ts";
import { eventCheckoutPath } from "../../../../utilites/urlHelper.ts";
import { dateToBrowserTz } from "../../../../utilites/dates.ts";
import { formatAddress } from "../../../../utilites/addressUtilities.ts";
import { getAttendeeProductTitle } from "../../../../utilites/products.ts";
import { showSuccess, showError } from "../../../../utilites/notifications.tsx";
import { confirmationDialog } from "../../../../utilites/confirmationDialog.tsx";

import { Card } from "../../../common/Card";
import { LoadingMask } from "../../../common/LoadingMask";
import { HomepageInfoMessage } from "../../../common/HomepageInfoMessage";
import { PoweredByFooter } from "../../../common/PoweredByFooter";
import { OrganizerBrandHeader } from "../../../common/OrganizerBrandHeader";
import { EventDateRange } from "../../../common/EventDateRange";
import { OnlineEventDetails } from "../../../common/OnlineEventDetails";
import { InlineOrderSummary } from "../../../common/InlineOrderSummary";
import { CheckoutContent } from "../../../layouts/Checkout/CheckoutContent";
import { EditAttendeeModal } from "./EditAttendeeModal";
import { EditOrderModal } from "./EditOrderModal";
import { AttendeeProfileCard, useAttendeeProfiles } from "./AttendeeProfiles";

import { useEditAttendeePublic } from "../../../../mutations/useEditAttendeePublic";
import { useEditOrderPublic } from "../../../../mutations/useEditOrderPublic";
import { useResendAttendeeTicketPublic } from "../../../../mutations/useResendAttendeeTicketPublic";
import { useResendOrderConfirmationPublic } from "../../../../mutations/useResendOrderConfirmationPublic";

import { Attendee, Event, Order, Product } from "../../../../types.ts";
import classes from './OrderSummaryAndProducts.module.scss';
import { clearWaitlistJoinedForEvent } from "../../../../hooks/useWaitlistJoined.ts";
// Purchase tracking is handled by the parent Checkout layout

const PaymentStatus = ({ order }: { order: Order }) => {
  const paymentStatuses: Record<string, string> = {
    'NO_PAYMENT_REQUIRED': t`No Payment Required`,
    'AWAITING_PAYMENT': t`Awaiting Payment`,
    'PAYMENT_FAILED': t`Payment Failed`,
    'PAYMENT_RECEIVED': t`Payment Received`,
    'AWAITING_OFFLINE_PAYMENT': t`Awaiting Offline Payment`,
  };

  return order?.payment_status ? <span>{paymentStatuses[order.payment_status] || ''}</span> : null;
};

const RefundStatusType = ({ order }: { order: Order }) => {
  const refundStatuses: Record<string, string> = {
    'REFUND_PENDING': t`Refund Pending`,
    'REFUND_FAILED': t`Refund Failed`,
    'REFUNDED': t`Refunded`,
    'PARTIALLY_REFUNDED': t`Partially Refunded`,
  };

  return order?.refund_status ? <span>{refundStatuses[order.refund_status] || ''}</span> : null;
};

/**
 * "Unassigned" = a ticket on a bundle/multi-quantity order whose attendee row
 * hasn't been given real-guest details yet. The buyer needs to fill in a name +
 * email before the ticket is useful to anyone but themselves. Two signals:
 *  1. A blank first OR last name (PER_TICKET checkout where the buyer skipped
 *     guest fields). Note: this is name-only and deliberately does NOT include
 *     the `confirm_at_checkin` flag — a named attendee that's merely flagged for
 *     check-in confirmation is "needs info", not "unassigned" (see needsConfirmation).
 *  2. Client-side duplicate detection — when name + email match the buyer's AND
 *     more than one attendee on the order has that exact combo, the second-and-
 *     later duplicates are treated as placeholders. The first match is assumed
 *     to be the buyer themselves (legitimately attending their own event).
 */
const isUnassignedAttendee = (
  attendee: Attendee,
  order: Order,
  allAttendees: Attendee[],
): boolean => {
  if (attendee.status === 'CANCELLED') return false;
  if ((attendee.first_name ?? '').trim() === '' || (attendee.last_name ?? '').trim() === '') return true;

  const matchesBuyer =
    (attendee.first_name ?? '').trim().toLowerCase() === (order.first_name ?? '').trim().toLowerCase()
    && (attendee.last_name ?? '').trim().toLowerCase() === (order.last_name ?? '').trim().toLowerCase()
    && (attendee.email ?? '').trim().toLowerCase() === (order.email ?? '').trim().toLowerCase();

  if (!matchesBuyer) return false;

  const firstBuyerMatchIndex = allAttendees.findIndex(a =>
    (a.first_name ?? '').trim().toLowerCase() === (order.first_name ?? '').trim().toLowerCase()
    && (a.last_name ?? '').trim().toLowerCase() === (order.last_name ?? '').trim().toLowerCase()
    && (a.email ?? '').trim().toLowerCase() === (order.email ?? '').trim().toLowerCase()
  );

  return allAttendees.indexOf(attendee) !== firstBuyerMatchIndex;
};

const GuestListItem = ({
  attendee,
  event,
  position,
  allowSelfEdit,
  isUnassigned,
  onEditClick,
  onResendClick,
}: {
  attendee: Attendee;
  event: Event;
  position: number;
  allowSelfEdit: boolean;
  isUnassigned: boolean;
  onEditClick: () => void;
  onResendClick: () => void;
}) => {
  const productTitle = getAttendeeProductTitle(attendee, attendee.product as Product);
  const isCancelled = attendee.status === 'CANCELLED';
  const showUnassignedTreatment = isUnassigned && allowSelfEdit;
  // Named attendee that's flagged for check-in confirmation: not "unassigned"
  // (they have a name), but still needs details verified. Unassigned wins.
  const needsConfirmation = !!attendee.confirm_at_checkin && !showUnassignedTreatment && !isCancelled;

  const guardedAction = (action: () => void) => () => {
    if (showUnassignedTreatment) {
      onEditClick();
      return;
    }
    action();
  };

  const isAssignedAndActive = !isCancelled && !showUnassignedTreatment;

  return (
    <div className={`${classes.guestItem} ${isCancelled ? classes.guestItemCancelled : ''} ${showUnassignedTreatment ? classes.guestItemUnassigned : ''} ${isAssignedAndActive ? classes.guestItemAssigned : ''}`}>
      <div className={classes.guestItemHeader}>
        <span
          className={`${classes.ticketPositionCircle} ${showUnassignedTreatment ? classes.ticketPositionCircleUnassigned : ''}`}
          aria-label={t`Ticket ${position}`}
        >
          {position}
        </span>
        <div className={classes.guestInfo}>
          <div className={classes.guestName}>
            {attendee.first_name || attendee.last_name
              ? <>{attendee.first_name} {attendee.last_name}</>
              : <span style={{ fontStyle: 'italic', opacity: 0.7 }}>{t`No name yet`}</span>}
            {isCancelled && <span className={classes.cancelledBadge}>{t`Cancelled`}</span>}
            {showUnassignedTreatment && (
              <Popover position="top" withArrow shadow="md" width={300}>
                <Popover.Target>
                  <UnstyledButton
                    className={classes.unassignedBadge}
                    aria-label={t`Unassigned ticket — click for details`}
                  >
                    <IconAlertTriangle size={11} style={{ marginRight: 4, verticalAlign: '-1px' }} />
                    {t`Unassigned`}
                  </UnstyledButton>
                </Popover.Target>
                <Popover.Dropdown>
                  <Text size="sm">
                    {t`This ticket hasn't been assigned to a guest yet. Click "Assign attendee" to add their name and email before sending, printing, or viewing the ticket.`}
                  </Text>
                </Popover.Dropdown>
              </Popover>
            )}
            {needsConfirmation && (
              <Popover position="top" withArrow shadow="md" width={300}>
                <Popover.Target>
                  <UnstyledButton
                    className={classes.needsInfoBadge}
                    aria-label={t`Needs info — click for details`}
                  >
                    <IconInfoCircle size={11} style={{ marginRight: 4, verticalAlign: '-1px' }} />
                    {t`Confirm Details`}
                  </UnstyledButton>
                </Popover.Target>
                <Popover.Dropdown>
                  <Text size="sm">
                    {t`This attendee is flagged to confirm their details at check-in. Their details can still be updated before the event.`}
                  </Text>
                </Popover.Dropdown>
              </Popover>
            )}
          </div>
          <div className={classes.guestDetails}>
            {allowSelfEdit && !isCancelled ? (
              <Tooltip label={t`Click to edit name and email`}>
                <UnstyledButton
                  className={`${classes.guestEmail} ${classes.guestEmailLink}`}
                  onClick={onEditClick}
                >
                  {attendee.email || <span style={{ fontStyle: 'italic' }}>{t`Add email`}</span>}
                </UnstyledButton>
              </Tooltip>
            ) : (
              <span className={classes.guestEmail}>{attendee.email}</span>
            )}
            <span className={classes.guestProduct}>{productTitle}</span>
          </div>
        </div>
        <div className={showUnassignedTreatment ? classes.guestActions : classes.guestActionsStack}>
          {showUnassignedTreatment ? (
            <Button
              size="sm"
              color="yellow"
              variant="filled"
              leftSection={<IconUserPlus size={16} />}
              onClick={onEditClick}
            >
              {t`Assign attendee`}
            </Button>
          ) : (
            <>
              <div className={classes.guestActionsIconsRow}>
                <Tooltip label={t`Print Ticket`}>
                  <ActionIcon
                    variant="subtle"
                    onClick={guardedAction(() => window?.open(`/product/${event.id}/${attendee.short_id}/print`, '_blank'))}
                  >
                    <IconPrinter size={18} />
                  </ActionIcon>
                </Tooltip>
                {allowSelfEdit && !isCancelled && (
                  <>
                    <Tooltip label={t`Edit Attendee`}>
                      <ActionIcon
                        variant="subtle"
                        onClick={onEditClick}
                      >
                        <IconEdit size={18} />
                      </ActionIcon>
                    </Tooltip>
                    <Tooltip label={t`Resend Ticket`}>
                      <ActionIcon
                        variant="subtle"
                        onClick={onResendClick}
                      >
                        <IconSend size={18} />
                      </ActionIcon>
                    </Tooltip>
                  </>
                )}
              </div>
              <Button
                size="xs"
                variant="filled"
                leftSection={<IconTicket size={14} />}
                onClick={guardedAction(() => window?.open(`/product/${event.id}/${attendee.short_id}`, '_blank'))}
              >
                {t`View Ticket`}
              </Button>
            </>
          )}
        </div>
      </div>
    </div>
  );
};

const DetailItem = ({ icon: Icon, label, value }: { icon: any, label: string, value: React.ReactNode }) => (
  <div className={classes.detailItem}>
    <Group gap="xs" wrap="nowrap">
      <Icon size={20} style={{ color: 'var(--checkout-accent, var(--mantine-color-gray-6))', flexShrink: 0 }} />
      <div className={classes.detailContent}>
        <Text size="sm" c="dimmed" className={classes.label}>{label}</Text>
        <Text className={classes.value}>{value}</Text>
      </div>
    </Group>
  </div>
);

const WelcomeHeader = ({ order, event, allowSelfEdit }: { order: Order; event: Event; allowSelfEdit: boolean }) => {
  const isCompleted = order.status === 'COMPLETED';
  const isAwaitingPayment = order.status === 'AWAITING_OFFLINE_PAYMENT';
  const isCancelled = order.status === 'CANCELLED';

  // Status emojis trail the message text now (e.g. "You're going to Foo! 🎉"),
  // freeing the large header slot for the organizer's brand mark.
  const statusEmoji = isCompleted ? ' 🎉'
    : isAwaitingPayment ? ' ⏳'
      : isCancelled ? ' 😔'
        : '';

  const message = {
    'COMPLETED': t`You're going to ${event.title}!`,
    'CANCELLED': t`Your order has been cancelled`,
    'RESERVED': null,
    'AWAITING_OFFLINE_PAYMENT': t`Your order is awaiting payment`,
    'ABANDONED': null,
  }[order.status];

  if (!message) return null;

  return (
    <div className={classes.welcomeHeader}>
      <OrganizerBrandHeader event={event} />
      <div className={classes.welcomeMessage}>{message}{statusEmoji}</div>
      {isCompleted && (
        <div className={classes.confirmationText}>
          {t`Confirmation sent to`} <strong>{order.email}</strong>
        </div>
      )}
      {isCompleted && allowSelfEdit && (
        <div className={classes.selfServiceHint}>
          {t`Bookmark this page to manage your order anytime.`}
        </div>
      )}
      {isCancelled && (
        <div className={classes.confirmationText}>
          {t`A cancellation notice has been sent to`} <strong>{order.email}</strong>
        </div>
      )}
    </div>
  );
};

const OrderDetails = ({
  order,
  event,
  allowSelfEdit,
  onEditClick,
  onResendClick,
}: {
  order: Order;
  event: Event;
  allowSelfEdit: boolean;
  onEditClick: () => void;
  onResendClick: () => void;
}) => (
  <Card style={{ marginBottom: '40px' }}>
    <SimpleGrid cols={{ base: 1, sm: 2 }} spacing="md">
      <DetailItem
        icon={IconUser}
        label={t`Name`}
        value={
          <Group gap="xs" wrap="nowrap">
            <span>{order.first_name} {order.last_name}</span>
            {allowSelfEdit && order.status !== 'CANCELLED' && (
              <Tooltip label={t`Edit`}>
                <ActionIcon size="xs" variant="subtle" onClick={onEditClick}>
                  <IconEdit size={14} />
                </ActionIcon>
              </Tooltip>
            )}
          </Group>
        }
      />
      <DetailItem
        icon={IconId}
        label={t`Order Reference`}
        value={order.public_id}
      />
      <DetailItem
        icon={IconMail}
        label={t`Email`}
        value={
          <Group gap="xs" wrap="nowrap">
            <span style={{ wordBreak: 'break-all' }}>{order.email}</span>
            {allowSelfEdit && order.status !== 'CANCELLED' && (
              <Tooltip label={t`Resend Confirmation`}>
                <ActionIcon size="xs" variant="subtle" onClick={onResendClick}>
                  <IconSend size={14} />
                </ActionIcon>
              </Tooltip>
            )}
          </Group>
        }
      />
      <DetailItem
        icon={IconCalendar}
        label={t`Order Date`}
        value={dateToBrowserTz(order.created_at, event.timezone)}
      />
      {!!order.refund_status && (
        <DetailItem
          icon={IconMenuOrder}
          label={t`Refund Status`}
          value={<RefundStatusType order={order} />}
        />
      )}
      {(order.payment_status !== 'PAYMENT_RECEIVED' && order.payment_status !== 'NO_PAYMENT_REQUIRED') && (
        <DetailItem
          icon={IconCash}
          label={t`Payment Status`}
          value={<PaymentStatus order={order} />}
        />
      )}
      {order.address && (
        <DetailItem
          icon={IconMapPin}
          label={t`Billing Address`}
          value={formatAddress(order.address)}
        />
      )}
    </SimpleGrid>
  </Card>
);

const EventDetails = ({ event }: { event: Event }) => {
  const location = event.settings?.location_details ? formatAddress(event.settings.location_details) : null;
  const venueDetails = event.settings?.location_details?.venue_name
    ? `${event.settings.location_details.venue_name}${location ? `, ${location}` : ''}`
    : location;

  return (
    <Card>
      <SimpleGrid cols={{ base: 1, sm: 2 }} spacing="md">
        <DetailItem
          icon={IconCalendarEvent}
          label={t`Event Date`}
          value={<EventDateRange event={event} />}
        />
        {venueDetails && (
          <DetailItem
            icon={IconMapPin}
            label={t`Location`}
            value={(
              <NavLink
                to={event.settings?.maps_url || `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(event?.settings?.location_details ? formatAddress(event.settings.location_details) : '')}`}
                target="_blank"
              >
                {venueDetails}
              </NavLink>

            )}
          />
        )}
        <DetailItem
          icon={IconClock}
          label={t`Timezone`}
          value={event.timezone}
        />
        <DetailItem
          icon={IconBuilding}
          label={t`Organizer`}
          value={(
            <>
              {event.organizer?.email && (
                <NavLink to={event.organizer?.email ? `mailto:${event.organizer.email}` : '#'}>
                  {event.organizer?.name}
                </NavLink>
              )}
              {!event.organizer?.email && event.organizer?.name}
            </>
          )}
        />
      </SimpleGrid>
    </Card>
  );
};

const OrderStatus = ({ order }: { order: Order }) => {
  if (order?.status === 'CANCELLED') {
    return (
      <HomepageInfoMessage
        status="cancelled"
        message={t`Order cancelled`}
        subtitle={t`This order has been cancelled.`}
      />
    );
  }

  if (order?.status === 'COMPLETED') {
    return (
      <HomepageInfoMessage
        status="success"
        message={t`Order complete`}
        subtitle={t`This order is complete.`}
      />
    );
  }

  return (
    <HomepageInfoMessage
      status="processing"
      message={t`Processing order`}
      subtitle={t`This order is being processed.`}
    />
  );
};

const PostCheckoutMessage = ({ message }: { message: string }) => (
  <div style={{ marginTop: '20px', marginBottom: '40px' }}>
    <h1 className={classes.heading}>{t`Additional Information`}</h1>
    <Card>
      <div dangerouslySetInnerHTML={{ __html: message }} />
    </Card>
  </div>
);

const OfflinePaymentInstructions = ({ event }: { event: Event }) => (
  <div style={{ marginTop: '20px', marginBottom: '40px' }}>
    <h2>{t`Payment Instructions`}</h2>
    <Card>
      <div
        dangerouslySetInnerHTML={{
          __html: event?.settings?.offline_payment_instructions || "",
        }}
      />
    </Card>
  </div>
);

export const OrderSummaryAndProducts = () => {
  const { eventId, orderShortId } = useParams();
  const location = useLocation();
  const { data: order, isFetched: orderIsFetched, isError } = useGetOrderPublic(eventId, orderShortId, ['event']);
  const event = order?.event;
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const emailUpdated = location.state?.emailUpdated === true;

  const [editingAttendee, setEditingAttendee] = useState<Attendee | null>(null);
  const [editOrderModalOpened, setEditOrderModalOpened] = useState(false);

  // Transient confirmation banner that surfaces after the buyer changes an
  // attendee's email — the backend auto-sends the ticket to the new address,
  // and this lets the buyer (a) confirm that happened and (b) resend with
  // one click if it ended up in a spam folder. Auto-clears after 30s.
  const [recentEmailSend, setRecentEmailSend] = useState<{ attendee: Attendee; email: string } | null>(null);

  const attendeeProfileMap = useAttendeeProfiles(
    Number(eventId),
    order?.attendee_contact_tokens,
    order?.buyer_contact_token,
  );
  const buyerProfileEntry = order?.buyer_contact_token
    ? attendeeProfileMap.get(order.buyer_contact_token.contact_id)
    : undefined;
  const [buyerProfileOpen, setBuyerProfileOpen] = useState(false);

  useEffect(() => {
    if (eventId && order && (order.status === 'COMPLETED' || order.status === 'AWAITING_OFFLINE_PAYMENT')) {
      clearWaitlistJoinedForEvent(eventId);
    }
  }, [eventId, order?.status]);

  // Auto-dismiss the email-sent banner after 30s so it doesn't linger forever.
  useEffect(() => {
    if (!recentEmailSend) return;
    const timer = setTimeout(() => setRecentEmailSend(null), 30_000);
    return () => clearTimeout(timer);
  }, [recentEmailSend]);

  const editAttendeeMutation = useEditAttendeePublic();
  const editOrderMutation = useEditOrderPublic();
  const resendAttendeeTicketMutation = useResendAttendeeTicketPublic();
  const resendOrderConfirmationMutation = useResendOrderConfirmationPublic();

  const allowSelfEdit = event?.settings?.allow_attendee_self_edit ?? false;

  const handleEditOrder = (data: any) => {
    editOrderMutation.mutate(
      {
        eventId: eventId!,
        orderShortId: orderShortId!,
        data,
      },
      {
        onSuccess: (result) => {
          setEditOrderModalOpened(false);
          showSuccess(result.message || t`Order updated successfully`);

          if (result.new_short_id) {
            navigate(`/checkout/${eventId}/${result.new_short_id}/summary`, {
              state: { emailUpdated: true }
            });
          } else {
            queryClient.invalidateQueries({ queryKey: [GET_ORDER_PUBLIC_QUERY_KEY] });
          }

          if (result.warning) {
            showError(result.warning);
          }
        },
        onError: (error: any) => {
          if (error?.response?.status === 429) {
            showError(t`Rate limit exceeded. Please try again later.`);
          } else {
            showError(error?.response?.data?.message || t`Failed to update order`);
          }
        },
      }
    );
  };

  const handleResendAttendeeTicket = (attendee: Attendee) => {
    confirmationDialog(
      t`Are you sure you want to resend the ticket to ${attendee.email}?`,
      () => resendAttendeeTicketMutation.mutate(
        {
          eventId: eventId!,
          orderShortId: orderShortId!,
          attendeeShortId: attendee.short_id,
        },
        {
          onSuccess: (result) => {
            showSuccess(result.message || t`Ticket resent successfully`);
          },
          onError: (error: any) => {
            if (error?.response?.status === 429) {
              showError(t`Rate limit exceeded. Please try again later.`);
            } else {
              showError(error?.response?.data?.message || t`Failed to resend ticket`);
            }
          },
        }
      ),
      { useCheckoutColors: true },
    );
  };

  const handleResendOrderConfirmation = () => {
    confirmationDialog(
      t`Are you sure you want to resend the order confirmation to ${order?.email}?`,
      () => resendOrderConfirmationMutation.mutate(
        {
          eventId: eventId!,
          orderShortId: orderShortId!,
        },
        {
          onSuccess: (result) => {
            showSuccess(result.message || t`Order confirmation resent successfully`);
          },
          onError: (error: any) => {
            if (error?.response?.status === 429) {
              showError(t`Rate limit exceeded. Please try again later.`);
            } else {
              showError(error?.response?.data?.message || t`Failed to resend order confirmation`);
            }
          },
        }
      ),
      { useCheckoutColors: true },
    );
  };

  if (isError) {
    return (
      <HomepageInfoMessage
        status="not_found"
        message={t`Order Not Found`}
        subtitle={t`We couldn't find the order you're looking for. The link may have expired or the order details may have changed.`}
      />
    );
  }

  if (!orderIsFetched || !order || !event) {
    return <LoadingMask />;
  }

  if (window?.location.search.includes('failed') || order?.payment_status === 'PAYMENT_FAILED') {
    navigate(eventCheckoutPath(eventId, orderShortId, 'payment') + '?payment_failed=true');
    return;
  }

  if (order?.status !== 'COMPLETED' && order?.status !== 'CANCELLED' && order?.status !== 'AWAITING_OFFLINE_PAYMENT') {
    return <OrderStatus order={order} />;
  }

  return (
    <>
      <CheckoutContent>
        <WelcomeHeader order={order} event={event} allowSelfEdit={allowSelfEdit} />

        {emailUpdated && (
          <Alert
            icon={<IconCheck size={16} />}
            color="green"
            mb="lg"
            radius="lg"
            style={{
              backgroundColor: 'var(--checkout-surface, #ECFDF5)',
              borderColor: 'var(--checkout-border, #D1FAE5)',
            }}
          >
            <Text size="sm" style={{ color: 'var(--checkout-text-primary, #065F46)' }}>
              {t`Your order details have been updated. A confirmation email has been sent to the new email address.`}
            </Text>
          </Alert>
        )}

        <InlineOrderSummary
          event={event}
          order={order}
          showBuyerProtection={false}
          defaultExpanded={false}
        />

        {order?.status === 'AWAITING_OFFLINE_PAYMENT' && event?.settings?.payment_providers?.includes('STRIPE') && (
          <Card style={{ marginTop: '20px', marginBottom: '20px' }}>
            <Group justify="space-between" align="center" wrap="wrap" gap="md">
              <div style={{ flex: 1, minWidth: 200 }}>
                <Text fw={600} size="md">{t`Want to pay by card now?`}</Text>
                <Text size="sm" c="dimmed">{t`Complete payment online so you don't have to handle it at the door.`}</Text>
              </div>
              <Button
                size="md"
                leftSection={<IconCreditCard size={18} />}
                onClick={() => navigate(eventCheckoutPath(eventId, orderShortId, 'payment'))}
              >
                {t`Pay by card now`}
              </Button>
            </Group>
          </Card>
        )}

        {order?.status === 'AWAITING_OFFLINE_PAYMENT' && event?.settings?.payment_providers?.includes('OFFLINE') && <OfflinePaymentInstructions event={event} />}

        {(order?.attendees && order.attendees.length > 0) && (
          <>
            <Group justify="space-between" align="center">
              <h1 className={classes.heading}>
                <Group gap="xs">
                  <IconTicket size={22} className={classes.ticketsHeadingIcon} />
                  {t`Tickets`}
                </Group>
              </h1>
              <Button
                size="sm"
                variant="subtle"
                leftSection={<IconPrinter size={16} />}
                onClick={() => window?.open(`/order/${eventId}/${orderShortId}/print`, '_blank')}
              >
                {t`Print All Tickets`}
              </Button>
            </Group>

            {recentEmailSend && (
              <Alert
                color="green"
                icon={<IconMail size={18} />}
                mb="sm"
                radius="md"
                withCloseButton
                onClose={() => setRecentEmailSend(null)}
              >
                <Group justify="space-between" align="center" wrap="nowrap">
                  <Text size="sm">
                    {t`Ticket sent to ${recentEmailSend.email} a moment ago.`}
                  </Text>
                  <Button
                    size="xs"
                    variant="subtle"
                    color="green"
                    loading={resendAttendeeTicketMutation.isPending}
                    onClick={() => {
                      const attendeeForResend = recentEmailSend.attendee;
                      resendAttendeeTicketMutation.mutate(
                        {
                          eventId: eventId!,
                          orderShortId: orderShortId!,
                          attendeeShortId: attendeeForResend.short_id,
                        },
                        {
                          onSuccess: (result) => {
                            showSuccess(result.message || t`Ticket resent.`);
                          },
                          onError: (error: any) => {
                            if (error?.response?.status === 429) {
                              showError(t`Rate limit exceeded. Please try again later.`);
                            } else {
                              showError(error?.response?.data?.message || t`Failed to resend ticket`);
                            }
                          },
                        },
                      );
                    }}
                  >
                    {t`Resend`}
                  </Button>
                </Group>
              </Alert>
            )}

            <Card className={classes.ticketsCard}>
              <div className={classes.guestList}>
                {order.attendees.map((attendee, index) => (
                  <GuestListItem
                    key={attendee.id}
                    attendee={attendee}
                    event={event}
                    position={index + 1}
                    allowSelfEdit={allowSelfEdit}
                    isUnassigned={isUnassignedAttendee(attendee, order, order.attendees ?? [])}
                    onEditClick={() => setEditingAttendee(attendee)}
                    onResendClick={() => handleResendAttendeeTicket(attendee)}
                  />
                ))}
              </div>
            </Card>
          </>
        )}

        <Group justify="space-between" align="center" wrap="nowrap">
          <h1 className={classes.heading} style={{ margin: 0 }}>{t`Order Details`}</h1>
          {order.status === 'COMPLETED' && buyerProfileEntry && (
            <UnstyledButton
              className={classes.profileToggle}
              onClick={() => setBuyerProfileOpen((v) => !v)}
              aria-expanded={buyerProfileOpen}
            >
              <IconUser size={14} />
              <span>{t`My Profile`}</span>
              {buyerProfileOpen ? <IconChevronUp size={14} /> : <IconChevronDown size={14} />}
            </UnstyledButton>
          )}
        </Group>

        <OrderDetails
          order={order}
          event={event}
          allowSelfEdit={allowSelfEdit}
          onEditClick={() => setEditOrderModalOpened(true)}
          onResendClick={handleResendOrderConfirmation}
        />

        {order.status === 'COMPLETED' && buyerProfileEntry && (
          <Collapse in={buyerProfileOpen}>
            <div className={classes.buyerProfilePanel}>
              <AttendeeProfileCard
                token={buyerProfileEntry.token}
                data={buyerProfileEntry.query.data}
                contactId={order.buyer_contact_token?.contact_id}
                eventId={Number(eventId)}
              />
            </div>
          </Collapse>
        )}

        {event?.settings?.is_online_event && <OnlineEventDetails eventSettings={event.settings} />}

        {!!event?.settings?.post_checkout_message && <PostCheckoutMessage message={event.settings.post_checkout_message} />}

        <h1 className={classes.heading}>{t`Event Details`}</h1>
        <EventDetails event={event} />

        <PoweredByFooter />
      </CheckoutContent>

      {editingAttendee && (
        <EditAttendeeModal
          opened={!!editingAttendee}
          onClose={() => setEditingAttendee(null)}
          attendee={editingAttendee}
          eventId={typeof eventId === 'string' ? Number(eventId) : eventId}
          profileEntry={
            order?.status === 'COMPLETED' && typeof editingAttendee.contact_id === 'number'
              ? attendeeProfileMap.get(editingAttendee.contact_id)
              : undefined
          }
          editAttendeeAsync={async (data) => {
            const previousEmail = (editingAttendee.email ?? '').trim().toLowerCase();
            const submittedEmail = (data.email ?? '').trim().toLowerCase();
            const emailChanged = submittedEmail !== '' && submittedEmail !== previousEmail;
            const submittedEmailRaw = (data.email ?? '').trim();
            const attendeeForBanner = editingAttendee;
            try {
              const result = await editAttendeeMutation.mutateAsync({
                eventId: eventId!,
                orderShortId: orderShortId!,
                attendeeShortId: editingAttendee.short_id,
                data,
              });
              queryClient.invalidateQueries({ queryKey: [GET_ORDER_PUBLIC_QUERY_KEY] });
              setEditingAttendee(null);
              // When the email changes, the backend automatically resends
              // the ticket to the new address via sendTicketToNewEmail.
              // Surface that as a transient banner above the Tickets
              // section so the buyer can confirm + Resend if needed.
              if (emailChanged && submittedEmailRaw) {
                setRecentEmailSend({
                  attendee: attendeeForBanner,
                  email: submittedEmailRaw,
                });
              } else {
                showSuccess(result.message || t`Attendee updated successfully`);
              }
              if (result.warning) {
                showError(result.warning);
              }
              return result;
            } catch (error: any) {
              if (error?.response?.status === 429) {
                showError(t`Rate limit exceeded. Please try again later.`);
              } else {
                showError(error?.response?.data?.message || t`Failed to update attendee`);
              }
              throw error;
            }
          }}
        />
      )}

      {order && (
        <EditOrderModal
          opened={editOrderModalOpened}
          onClose={() => setEditOrderModalOpened(false)}
          order={order}
          onSuccess={(values: any) => {
            handleEditOrder(values);
          }}
        />
      )}
    </>
  );
};

export default OrderSummaryAndProducts;
