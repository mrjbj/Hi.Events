import {api} from "./client";
import {GenericDataResponse, IdParam, OutgoingMessageEvent} from "../types";
import {AxiosResponse} from "axios";

export const outgoingMessageEventsClient = {
    byOutgoingMessage: async (eventId: IdParam, messageId: IdParam) => {
        const response: AxiosResponse<GenericDataResponse<OutgoingMessageEvent[]>> = await api.get(
            `events/${eventId}/outgoing-messages/${messageId}/events`,
        );
        return response.data;
    },

    byTransactionMessage: async (eventId: IdParam, messageId: IdParam) => {
        const response: AxiosResponse<GenericDataResponse<OutgoingMessageEvent[]>> = await api.get(
            `events/${eventId}/transaction-messages/${messageId}/events`,
        );
        return response.data;
    },
};
