import {useQuery} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {ContactActivity, contactClient} from "../api/contact.client.ts";
import {useGetMe} from "./useGetMe.ts";

export const GET_CONTACT_ACTIVITY_QUERY_KEY = 'getContactActivity';

export const useGetContactActivity = (contactId: IdParam, enabled = true) => {
    const meQuery = useGetMe();
    const accountId = meQuery.data?.account_id as IdParam;

    return useQuery<{ data: ContactActivity }>({
        queryKey: [GET_CONTACT_ACTIVITY_QUERY_KEY, accountId, contactId],
        enabled: enabled && meQuery.isFetched && !!contactId,
        queryFn: async () => await contactClient.activity(accountId, contactId),
    });
};
