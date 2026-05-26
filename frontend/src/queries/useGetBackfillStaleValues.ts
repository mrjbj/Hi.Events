import {useQuery} from "@tanstack/react-query";
import {GenericPaginatedResponse, IdParam, QueryFilters} from "../types.ts";
import {contactClient, ContactBackfillStaleValueRow} from "../api/contact.client.ts";
import {useGetMe} from "./useGetMe.ts";

export const GET_BACKFILL_STALE_VALUES_QUERY_KEY = 'getBackfillStaleValues';

export const useGetBackfillStaleValues = (params: QueryFilters) => {
    const meQuery = useGetMe();
    const accountId = meQuery.data?.account_id as IdParam;

    return useQuery<GenericPaginatedResponse<ContactBackfillStaleValueRow>>({
        queryKey: [GET_BACKFILL_STALE_VALUES_QUERY_KEY, accountId, params],
        enabled: meQuery.isFetched,
        queryFn: () => contactClient.backfillStaleValues(accountId, params),
    });
};
