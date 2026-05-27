import {ActionIcon, Badge, Button, Loader, Tooltip, UnstyledButton} from "@mantine/core";
import {IconArmchair, IconFilterOff, IconTicket, IconUserEdit, IconUsersGroup} from "@tabler/icons-react";
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
    hasActiveFilter?: boolean;
    searchQuery?: string;
    onClearFilter?: () => void;
    onCheckInToggle: (attendee: Attendee) => void;
    onEditAttendee?: (attendee: Attendee) => void;
    onFilterByGroup?: (attendee: Attendee) => void;
    onFilterByTable?: (seatInfo: string) => void;
    onClickSound?: () => void;
}

export const AttendeeList = ({
                                 attendees,
                                 products,
                                 isLoading,
                                 isCheckInPending,
                                 isDeletePending,
                                 allowOrdersAwaitingOfflinePaymentToCheckIn,
                                 hasActiveFilter,
                                 searchQuery,
                                 onClearFilter,
                                 onCheckInToggle,
                                 onEditAttendee,
                                 onFilterByGroup,
                                 onFilterByTable,
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
        const trimmedQuery = searchQuery?.trim();
        if (hasActiveFilter && trimmedQuery && onClearFilter) {
            return (
                <div className={classes.noResults} style={{flexDirection: 'column', gap: 12}}>
                    <div>
                        {t`No matches in this filter for "${trimmedQuery}".`}
                    </div>
                    <Button
                        variant="light"
                        leftSection={<IconFilterOff size={16}/>}
                        onClick={onClearFilter}
                    >
                        {t`Clear filter & search all attendees`}
                    </Button>
                </div>
            );
        }
        if (trimmedQuery) {
            return (
                <div className={classes.noResults}>
                    {t`"${trimmedQuery}" not found.`}
                </div>
            );
        }
        return (
            <div className={classes.noResults}>
                {t`No attendees to show.`}
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
                                    const badgeLabel = buyerName
                                        ? t`Group: ${buyerName}`
                                        : t`Group purchase`;
                                    const badge = (
                                        <Badge
                                            color="orange"
                                            variant="light"
                                            size="sm"
                                            leftSection={<IconUsersGroup size={12}/>}
                                            style={{cursor: onFilterByGroup ? 'pointer' : 'default'}}
                                        >
                                            {badgeLabel}
                                        </Badge>
                                    );
                                    return onFilterByGroup ? (
                                        <UnstyledButton
                                            onClick={() => onFilterByGroup(attendee)}
                                            aria-label={t`Filter to this group`}
                                        >
                                            {badge}
                                        </UnstyledButton>
                                    ) : badge;
                                })()}
                                {attendee.seat_info && (
                                    onFilterByTable ? (
                                        <UnstyledButton
                                            onClick={() => onFilterByTable(attendee.seat_info as string)}
                                            aria-label={t`Filter to this table`}
                                        >
                                            <Badge
                                                color="violet"
                                                variant="light"
                                                size="sm"
                                                leftSection={<IconArmchair size={12}/>}
                                                style={{cursor: 'pointer'}}
                                            >
                                                {attendee.seat_info}
                                            </Badge>
                                        </UnstyledButton>
                                    ) : (
                                        <Badge
                                            color="violet"
                                            variant="light"
                                            size="sm"
                                            leftSection={<IconArmchair size={12}/>}
                                            aria-label={t`Seat assignment`}
                                        >
                                            {attendee.seat_info}
                                        </Badge>
                                    )
                                )}
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
