import {useQuery} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {eventsClient} from "../api/event.client.ts";

export const GET_EVENT_RECONCILIATION_QUERY_KEY = 'getEventReconciliation';

export const useGetEventReconciliation = (eventId: IdParam, enabled: boolean = true) => {
    return useQuery({
        queryKey: [GET_EVENT_RECONCILIATION_QUERY_KEY, eventId],
        queryFn: async () => {
            const {data} = await eventsClient.getEventReconciliation(eventId);
            return data;
        },
        enabled,
    });
};
