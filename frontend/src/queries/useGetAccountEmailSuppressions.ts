import {useQuery} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {emailSuppressionClient, GetAccountEmailSuppressionsParams} from "../api/emailSuppression.client.ts";
import {useGetMe} from "./useGetMe.ts";

export const GET_ACCOUNT_EMAIL_SUPPRESSIONS_QUERY_KEY = 'getAccountEmailSuppressions';

export const useGetAccountEmailSuppressions = (params: GetAccountEmailSuppressionsParams = {}) => {
    const meQuery = useGetMe();
    const accountId = meQuery.data?.account_id as IdParam;

    return useQuery({
        queryKey: [GET_ACCOUNT_EMAIL_SUPPRESSIONS_QUERY_KEY, accountId, params],
        enabled: meQuery.isFetched && !!accountId,
        queryFn: () => emailSuppressionClient.getForAccount(accountId, params),
    });
};
