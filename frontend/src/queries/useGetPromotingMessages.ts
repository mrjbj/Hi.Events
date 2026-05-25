import {useQuery} from "@tanstack/react-query";
import {GenericPaginatedResponse, IdParam, OutgoingMessage, QueryFilters} from "../types.ts";
import {outgoingMessagesClient} from "../api/outgoing-messages.client.ts";

export const GET_PROMOTING_MESSAGES_QUERY_KEY = 'getPromotingMessages';

export const useGetPromotingMessages = (eventId: IdParam, pagination: QueryFilters) => {
    return useQuery<GenericPaginatedResponse<OutgoingMessage>>({
        queryKey: [GET_PROMOTING_MESSAGES_QUERY_KEY, eventId, pagination],
        queryFn: async () => await outgoingMessagesClient.allPromoting(eventId, pagination),
        enabled: !!eventId,
    });
};
