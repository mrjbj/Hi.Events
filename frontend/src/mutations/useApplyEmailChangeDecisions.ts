import {useMutation, useQueryClient} from "@tanstack/react-query";
import {contactClient} from "../api/contact.client.ts";
import {GET_BACKFILL_EMAIL_CHANGES_QUERY_KEY} from "../queries/useGetBackfillEmailChanges.ts";
import {GET_BACKFILL_SUMMARY_QUERY_KEY} from "../queries/useGetBackfillSummary.ts";
import {GET_CONTACTS_QUERY_KEY} from "../queries/useGetContacts.ts";
import {useGetMe} from "../queries/useGetMe.ts";

export const useApplyEmailChangeDecisions = () => {
    const queryClient = useQueryClient();
    const {data: me} = useGetMe();

    return useMutation({
        mutationFn: ({decisions}: {
            decisions: Array<{ attendee_id: number; decision: 'update' | 'split' | 'ignore' }>;
        }) => contactClient.backfillApplyEmailChangeDecisions(me?.account_id, decisions),

        onSuccess: () => {
            queryClient.invalidateQueries({queryKey: [GET_BACKFILL_EMAIL_CHANGES_QUERY_KEY]});
            queryClient.invalidateQueries({queryKey: [GET_BACKFILL_SUMMARY_QUERY_KEY]});
            queryClient.invalidateQueries({queryKey: [GET_CONTACTS_QUERY_KEY]});
        },
    });
};
