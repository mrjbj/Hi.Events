import {api} from "./client.ts";
import {GenericDataResponse, GenericPaginatedResponse, IdParam} from "../types.ts";
import {EmailSuppression} from "./admin.client.ts";

export interface GetAccountEmailSuppressionsParams {
    page?: number;
    per_page?: number;
    search?: string;
    reason?: string;
    bounce_type?: string;
}

export const emailSuppressionClient = {
    getForAccount: async (accountId: IdParam, params: GetAccountEmailSuppressionsParams = {}) => {
        const response = await api.get<GenericPaginatedResponse<EmailSuppression>>(
            `accounts/${accountId}/email-suppressions`,
            {
                params: {
                    page: params.page || 1,
                    per_page: params.per_page || 20,
                    search: params.search || undefined,
                    reason: params.reason || undefined,
                    bounce_type: params.bounce_type || undefined,
                },
            },
        );
        return response.data;
    },
    createForAccount: async (accountId: IdParam, email: string) => {
        const response = await api.post<GenericDataResponse<EmailSuppression>>(
            `accounts/${accountId}/email-suppressions`,
            {email},
        );
        return response.data;
    },
    deleteForAccount: async (accountId: IdParam, suppressionId: IdParam) => {
        return await api.delete(`accounts/${accountId}/email-suppressions/${suppressionId}`);
    },
};
