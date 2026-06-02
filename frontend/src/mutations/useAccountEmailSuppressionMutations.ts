import {useMutation, useQueryClient} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {emailSuppressionClient} from "../api/emailSuppression.client.ts";
import {GET_ACCOUNT_EMAIL_SUPPRESSIONS_QUERY_KEY} from "../queries/useGetAccountEmailSuppressions.ts";
import {useGetMe} from "../queries/useGetMe.ts";

export const useCreateAccountEmailSuppression = () => {
    const queryClient = useQueryClient();
    const {data: me} = useGetMe();
    const accountId = me?.account_id as IdParam;

    return useMutation({
        mutationFn: (email: string) => emailSuppressionClient.createForAccount(accountId, email),
        onSuccess: () => queryClient.invalidateQueries({queryKey: [GET_ACCOUNT_EMAIL_SUPPRESSIONS_QUERY_KEY]}),
    });
};

export const useDeleteAccountEmailSuppression = () => {
    const queryClient = useQueryClient();
    const {data: me} = useGetMe();
    const accountId = me?.account_id as IdParam;

    return useMutation({
        mutationFn: (suppressionId: IdParam) => emailSuppressionClient.deleteForAccount(accountId, suppressionId),
        onSuccess: () => queryClient.invalidateQueries({queryKey: [GET_ACCOUNT_EMAIL_SUPPRESSIONS_QUERY_KEY]}),
    });
};
