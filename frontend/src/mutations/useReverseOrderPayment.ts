import {useMutation} from "@tanstack/react-query";
import {IdParam} from '../types';
import {GET_EVENT_ORDERS_QUERY_KEY} from '../queries/useGetEventOrders';
import {ReverseOrderPaymentPayload, orderClient} from '../api/order.client';
import {queryClient} from "../utilites/queryClient.ts";
import {GET_ORDER_QUERY_KEY} from "../queries/useGetOrder.ts";

export const useReverseOrderPayment = () => {
    return useMutation({
        mutationFn: ({eventId, orderId, paymentId, payload}: {
            eventId: IdParam,
            orderId: IdParam,
            paymentId: IdParam,
            payload: ReverseOrderPaymentPayload
        }) => orderClient.reversePayment(eventId, orderId, paymentId, payload),
        onSuccess: (_, variables) => {
            return Promise.all([
                queryClient.invalidateQueries({
                    queryKey: [GET_EVENT_ORDERS_QUERY_KEY, variables.eventId]
                }),
                queryClient.invalidateQueries({
                    queryKey: [GET_ORDER_QUERY_KEY, variables.orderId]
                })
            ]);
        }
    });
}
