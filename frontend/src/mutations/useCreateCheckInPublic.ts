import {useMutation, useQueryClient} from '@tanstack/react-query';
import {publicCheckInClient} from "../api/check-in.client";
import {GET_CHECK_IN_LIST_ATTENDEES_PUBLIC_QUERY_KEY} from "../queries/useGetCheckInListAttendeesPublic.ts";
import {IdParam, QueryFilters} from "../types.ts";

export const useCreateCheckInPublic = (pagination: QueryFilters) => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({checkInListShortId, attendeePublicId, action, payment}: {
            checkInListShortId: IdParam,
            attendeePublicId: IdParam,
            action: 'check-in' | 'check-in-and-mark-order-as-paid',
            payment?: {
                payment_method: string;
                payment_reference?: string | null;
                amount?: number | null;
            },
        }) =>
            publicCheckInClient.createCheckIn(checkInListShortId, attendeePublicId, action, payment),

        onSuccess: (data, {checkInListShortId, action, payment}) => {
            const markedAsPaid = action === 'check-in-and-mark-order-as-paid';

            // A door payment only activates the attendee when it settles the order.
            // A short (partial) payment leaves the order awaiting payment, so the
            // attendee stays AWAITING_PAYMENT — decided per row against what's owed.
            const settlesOrder = (owed?: number | null) => {
                const amount = payment?.amount;
                if (amount === undefined || amount === null) return true; // no amount entered = pay in full
                if (owed === undefined || owed === null) return true;
                return amount >= owed;
            };

            queryClient.setQueryData(
                [GET_CHECK_IN_LIST_ATTENDEES_PUBLIC_QUERY_KEY, checkInListShortId, pagination],
                (oldData: any) => {
                    if (!oldData?.data) return oldData;

                    const updatedAttendee = data?.data?.find((checkIn: any) => checkIn.attendee_id);

                    if (!updatedAttendee) return oldData;

                    const updatedOrderId = updatedAttendee.order_id;

                    const newAttendees = oldData.data.map((attendee: any) => {
                        const attendeeCheckIn = data?.data?.find(
                            (checkIn: any) => checkIn.attendee_id === attendee.id
                        );

                        const hasError = data.errors && Object.keys(data.errors).some(
                            (key) => key === attendee.public_id
                        );

                        const activates = markedAsPaid && !hasError && settlesOrder(attendee.order_total_gross);

                        if (attendeeCheckIn) {
                            return {
                                ...attendee,
                                check_in: attendeeCheckIn,
                                status: activates ? 'ACTIVE' : attendee.status,
                            };
                        }

                        // Mark all attendees with the same order_id as ACTIVE if the order settled
                        if (markedAsPaid && attendee.order_id === updatedOrderId
                            && settlesOrder(attendee.order_total_gross)) {
                            return {
                                ...attendee,
                                status: 'ACTIVE',
                            };
                        }
                        return attendee;
                    });

                    return {
                        ...oldData,
                        data: newAttendees,
                    };
                }
            );

            // Refetch authoritative status/balance after a payment (the optimistic
            // guess above can't account for prior partial credits on the order).
            if (markedAsPaid) {
                queryClient.invalidateQueries({
                    queryKey: [GET_CHECK_IN_LIST_ATTENDEES_PUBLIC_QUERY_KEY, checkInListShortId],
                });
            }
        }
    });
};
