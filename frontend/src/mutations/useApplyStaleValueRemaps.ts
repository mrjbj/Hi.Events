import {useMutation, useQueryClient} from "@tanstack/react-query";
import {contactClient} from "../api/contact.client.ts";
import {GET_BACKFILL_STALE_VALUES_QUERY_KEY} from "../queries/useGetBackfillStaleValues.ts";
import {GET_BACKFILL_SUMMARY_QUERY_KEY} from "../queries/useGetBackfillSummary.ts";
import {GET_CONTACTS_QUERY_KEY} from "../queries/useGetContacts.ts";
import {useGetMe} from "../queries/useGetMe.ts";

export const useApplyStaleValueRemaps = () => {
    const queryClient = useQueryClient();
    const {data: me} = useGetMe();

    return useMutation({
        mutationFn: ({remaps}: {
            remaps: Array<{
                contact_id: number;
                attribute_name: string;
                new_value?: string | string[] | null;
                add_values_to_options?: string[];
            }>;
        }) => contactClient.backfillApplyStaleValueRemaps(me?.account_id, remaps),

        onSuccess: () => {
            queryClient.invalidateQueries({queryKey: [GET_BACKFILL_STALE_VALUES_QUERY_KEY]});
            queryClient.invalidateQueries({queryKey: [GET_BACKFILL_SUMMARY_QUERY_KEY]});
            queryClient.invalidateQueries({queryKey: [GET_CONTACTS_QUERY_KEY]});
        },
    });
};
