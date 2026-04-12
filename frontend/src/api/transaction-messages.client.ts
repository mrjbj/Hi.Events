import {api} from "./client";
import {GenericPaginatedResponse, IdParam, OutgoingTransactionMessage, QueryFilters} from "../types";
import {queryParamsHelper} from "../utilites/queryParamsHelper.ts";
import {AxiosResponse} from "axios";

export const transactionMessagesClient = {
    failures: async (eventId: IdParam, pagination: QueryFilters) => {
        const response: AxiosResponse<GenericPaginatedResponse<OutgoingTransactionMessage>> = await api.get<GenericPaginatedResponse<OutgoingTransactionMessage>>(
            `events/${eventId}/transaction-messages/failures` + queryParamsHelper.buildQueryString(pagination),
        );
        return response.data;
    },
};
