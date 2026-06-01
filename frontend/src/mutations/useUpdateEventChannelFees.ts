import {useMutation, useQueryClient} from "@tanstack/react-query";
import {ChannelFeeInput, IdParam} from "../types.ts";
import {eventsClient} from "../api/event.client.ts";
import {GET_EVENT_RECONCILIATION_QUERY_KEY} from "../queries/useGetEventReconciliation.ts";

export const useUpdateEventChannelFees = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({eventId, fees}: { eventId: IdParam, fees: ChannelFeeInput[] }) =>
            eventsClient.updateEventChannelFees(eventId, fees),

        onSuccess: (_, variables) => {
            return queryClient.invalidateQueries({
                queryKey: [GET_EVENT_RECONCILIATION_QUERY_KEY, variables.eventId],
            });
        }
    });
};
