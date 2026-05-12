import {useMutation} from "@tanstack/react-query";
import {IdParam} from "../types";
import {orderClient} from "../api/order.client";
import {queryClient} from "../utilites/queryClient.ts";
import {GET_ORDER_QUERY_KEY} from "../queries/useGetOrder.ts";
import {GET_ATTENDEES_QUERY_KEY} from "../queries/useGetAttendees.ts";

export const useBulkAssignAttendeeSeatInfo = () => {
    return useMutation({
        mutationFn: ({eventId, orderId, seatInfo}: {
            eventId: IdParam,
            orderId: IdParam,
            seatInfo: string | null,
        }) => orderClient.bulkAssignAttendeeSeatInfo(eventId, orderId, seatInfo),
        onSuccess: (_, variables) => {
            return Promise.all([
                queryClient.invalidateQueries({
                    queryKey: [GET_ORDER_QUERY_KEY, variables.orderId],
                }),
                queryClient.invalidateQueries({
                    queryKey: [GET_ATTENDEES_QUERY_KEY],
                }),
            ]);
        },
    });
};
