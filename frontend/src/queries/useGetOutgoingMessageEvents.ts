import {useQuery} from "@tanstack/react-query";
import {GenericDataResponse, IdParam, OutgoingMessageEvent} from "../types.ts";
import {outgoingMessageEventsClient} from "../api/outgoing-message-events.client.ts";

export const GET_OUTGOING_MESSAGE_EVENTS_QUERY_KEY = 'getOutgoingMessageEvents';

export type MessageEventSource = 'announcement' | 'transaction';

export const useGetOutgoingMessageEvents = (
    eventId: IdParam | null,
    source: MessageEventSource,
    messageId: IdParam | null,
    enabled: boolean,
) => {
    return useQuery<GenericDataResponse<OutgoingMessageEvent[]>>({
        queryKey: [GET_OUTGOING_MESSAGE_EVENTS_QUERY_KEY, eventId, source, messageId],
        queryFn: async () => {
            if (source === 'transaction') {
                return outgoingMessageEventsClient.byTransactionMessage(eventId!, messageId!);
            }
            return outgoingMessageEventsClient.byOutgoingMessage(eventId!, messageId!);
        },
        enabled: enabled && !!eventId && !!messageId,
    });
};
