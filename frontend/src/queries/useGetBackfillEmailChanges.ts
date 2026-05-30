import {useQuery} from "@tanstack/react-query";
import {GenericPaginatedResponse, IdParam, QueryFilters} from "../types.ts";
import {contactClient, ContactBackfillEmailChangeRow} from "../api/contact.client.ts";
import {useGetMe} from "./useGetMe.ts";

export const GET_BACKFILL_EMAIL_CHANGES_QUERY_KEY = 'getBackfillEmailChanges';

export const useGetBackfillEmailChanges = (params: QueryFilters, includeProcessed: boolean) => {
    const meQuery = useGetMe();
    const accountId = meQuery.data?.account_id as IdParam;

    return useQuery<GenericPaginatedResponse<ContactBackfillEmailChangeRow>>({
        queryKey: [GET_BACKFILL_EMAIL_CHANGES_QUERY_KEY, accountId, params, includeProcessed],
        enabled: meQuery.isFetched,
        queryFn: () => contactClient.backfillEmailChanges(accountId, params, includeProcessed),
    });
};
