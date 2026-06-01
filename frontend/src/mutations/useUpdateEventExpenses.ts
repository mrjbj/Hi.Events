import {useMutation, useQueryClient} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {eventsClient} from "../api/event.client.ts";
import {GET_EVENT_RECONCILIATION_QUERY_KEY} from "../queries/useGetEventReconciliation.ts";

export const useUpdateEventExpenses = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({eventId, expenses}: { eventId: IdParam, expenses: number }) =>
            eventsClient.updateEventExpenses(eventId, expenses),

        onSuccess: (_, variables) => {
            return queryClient.invalidateQueries({
                queryKey: [GET_EVENT_RECONCILIATION_QUERY_KEY, variables.eventId],
            });
        }
    });
};
