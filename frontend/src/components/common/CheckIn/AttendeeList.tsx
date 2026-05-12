import {ActionIcon, Badge, Button, Loader, Popover, Text, Tooltip} from "@mantine/core";
import {IconTicket, IconUserEdit, IconUsersGroup} from "@tabler/icons-react";
import {t} from "@lingui/macro";
import {Attendee} from "../../../types.ts";
import classes from "../../layouts/CheckIn/CheckIn.module.scss";

interface AttendeeListProps {
    attendees: Attendee[] | undefined;
    products: { id: number; title: string; }[] | undefined;
    isLoading: boolean;
    isCheckInPending: boolean;
    isDeletePending: boolean;
    allowOrdersAwaitingOfflinePaymentToCheckIn: boolean;
    onCheckInToggle: (attendee: Attendee) => void;
    onEditAttendee?: (attendee: Attendee) => void;
    onClickSound?: () => void;
}

export const AttendeeList = ({
                                 attendees,
                                 products,
                                 isLoading,
                                 isCheckInPending,
                                 isDeletePending,
                                 allowOrdersAwaitingOfflinePaymentToCheckIn,
                                 onCheckInToggle,
                                 onEditAttendee,
                                 onClickSound
                             }: AttendeeListProps) => {
    const checkInButtonText = (attendee: Attendee) => {
        if (!allowOrdersAwaitingOfflinePaymentToCheckIn && attendee.status === 'AWAITING_PAYMENT') {
            return t`Cannot Check In`;
        }

        if (attendee.status === 'CANCELLED') {
            return t`Cannot Check In (Cancelled)`;
        }

        if (attendee.check_in) {
            return t`Check Out`;
        }

        return t`Check In`;
    };

    const getButtonColor = (attendee: Attendee) => {
        if (attendee.check_in || attendee.status === 'CANCELLED') {
            return 'red';
        }
        if (attendee.status === 'AWAITING_PAYMENT' && !allowOrdersAwaitingOfflinePaymentToCheckIn) {
            return 'gray';
        }
        return 'teal';
    };

    if (isLoading || !attendees || !products) {
        return (
            <div className={classes.loading}>
                <Loader size={40}/>
            </div>
        );
    }

    if (attendees.length === 0) {
        return (
            <div className={classes.noResults}>
                No attendees to show.
            </div>
        );
    }

    return (
        <div className={classes.attendees}>
            {attendees.map(attendee => {
                const isAttendeeAwaitingPayment = attendee.status === 'AWAITING_PAYMENT';

                return (
                    <div className={classes.attendee} key={attendee.public_id}>
                        <div className={classes.details}>
                            <div style={{display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap'}}>
                                <b>{attendee.first_name} {attendee.last_name}</b>
                                {attendee.from_group_purchase && (() => {
                                    const buyerName = [attendee.buyer_first_name, attendee.buyer_last_name]
                                        .filter(Boolean)
                                        .join(' ')
                                        .trim();
                                    return (
                                    <Popover position="top" withArrow shadow="md" width={260}>
                                        <Popover.Target>
                                            <Badge
                                                color="orange"
                                                variant="light"
                                                size="sm"
                                                leftSection={<IconUsersGroup size={12}/>}
                                                style={{cursor: 'pointer'}}
                                                aria-label={t`Group purchase — click for buyer info`}
                                            >
                                                {buyerName
                                                    ? t`Group: ${buyerName}`
                                                    : t`Group purchase`}
                                            </Badge>
                                        </Popover.Target>
                                        <Popover.Dropdown>
                                            <Text size="xs" c="dimmed" mb={4}>{t`Purchased by`}</Text>
                                            {(attendee.buyer_first_name || attendee.buyer_last_name) ? (
                                                <Text size="sm" fw={500}>
                                                    {attendee.buyer_first_name} {attendee.buyer_last_name}
                                                </Text>
                                            ) : (
                                                <Text size="sm" fs="italic" c="dimmed">{t`Buyer name unavailable`}</Text>
                                            )}
                                            {attendee.buyer_email && (
                                                <Text size="xs" c="dimmed" mt={2}>
                                                    {attendee.buyer_email}
                                                </Text>
                                            )}
                                            <Text size="xs" c="dimmed" mt={8} fs="italic">
                                                {t`Verify the guest's name and email at check-in.`}
                                            </Text>
                                        </Popover.Dropdown>
                                    </Popover>
                                    );
                                })()}
                            </div>
                            {attendee.status === 'CANCELLED' ? (
                                <div style={{fontSize: '0.8em', color: 'red'}}>
                                    {t`Ticket Cancelled`}
                                </div>
                            ) : null}
                            <div style={{fontSize: '0.8em', color: '#555'}}>
                                {attendee.email}
                            </div>
                            {isAttendeeAwaitingPayment && (
                                <div className={classes.awaitingPayment}>
                                    {t`Awaiting payment`}
                                </div>
                            )}
                            <div>
                                <span>{attendee.public_id}</span>
                            </div>
                            <div className={classes.product}>
                                <IconTicket
                                    size={15}/> {products.find(product => product.id === attendee.product_id)?.title}
                            </div>
                        </div>
                        <div className={classes.actions}>
                            {onEditAttendee && attendee.contact_token && (
                                <Tooltip label={t`Edit attendee details`}>
                                    <ActionIcon
                                        variant="subtle"
                                        color="gray"
                                        onClick={() => onEditAttendee(attendee)}
                                        aria-label={t`Edit attendee`}
                                    >
                                        <IconUserEdit size={18}/>
                                    </ActionIcon>
                                </Tooltip>
                            )}
                            <Button
                                onClick={() => {
                                    onClickSound?.();
                                    onCheckInToggle(attendee);
                                }}
                                disabled={isCheckInPending || isDeletePending || attendee.status === 'CANCELLED'}
                                loading={isCheckInPending || isDeletePending}
                                color={getButtonColor(attendee)}
                            >
                                {checkInButtonText(attendee)}
                            </Button>
                        </div>
                    </div>
                );
            })}
        </div>
    );
};
