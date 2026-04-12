import {useQuery} from "@tanstack/react-query";
import {GenericPaginatedResponse, IdParam, OutgoingTransactionMessage, QueryFilters} from "../types.ts";
import {transactionMessagesClient} from "../api/transaction-messages.client.ts";

export const GET_TRANSACTION_MESSAGE_FAILURES_QUERY_KEY = 'getTransactionMessageFailures';

export const useGetTransactionMessageFailures = (eventId: IdParam, pagination: QueryFilters) => {
    return useQuery<GenericPaginatedResponse<OutgoingTransactionMessage>>({
        queryKey: [GET_TRANSACTION_MESSAGE_FAILURES_QUERY_KEY, eventId, pagination],
        queryFn: async () => await transactionMessagesClient.failures(eventId, pagination),
        enabled: !!eventId,
    });
};
