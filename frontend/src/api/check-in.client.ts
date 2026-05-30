import {publicApi} from "./public-client";
import {
    Attendee,
    CheckInList,
    GenericDataResponse,
    GenericPaginatedResponse,
    IdParam, PublicCheckIn,
    QueryFilters,
} from "../types";
import {queryParamsHelper} from "../utilites/queryParamsHelper";

export const publicCheckInClient = {
    getCheckInList: async (checkInListShortId: IdParam) => {
        const response = await publicApi.get<GenericDataResponse<CheckInList>>(`/check-in-lists/${checkInListShortId}`);
        return response.data;
    },
    getCheckInListAttendees: async (checkInListShortId: IdParam, pagination: QueryFilters) => {
        const response = await publicApi.get<GenericPaginatedResponse<Attendee>>(`/check-in-lists/${checkInListShortId}/attendees` + queryParamsHelper.buildQueryString(pagination));
        return response.data;
    },
    getCheckInListFilterOptions: async (checkInListShortId: IdParam) => {
        const response = await publicApi.get<GenericDataResponse<{ tables: string[]; groups: Array<{ order_id: number; label: string }> }>>(
            `/check-in-lists/${checkInListShortId}/filter-options`
        );
        return response.data;
    },
    getCheckInListSiblings: async (checkInListShortId: IdParam) => {
        const response = await publicApi.get<GenericDataResponse<Array<{
            short_id: string;
            name: string;
            is_active: boolean;
            is_expired: boolean;
        }>>>(`/check-in-lists/${checkInListShortId}/siblings`);
        return response.data;
    },
    getCheckInListAttendee: async (checkInListShortId: IdParam, attendeePublicId: IdParam) => {
        const response = await publicApi.get<GenericDataResponse<Attendee>>(`/check-in-lists/${checkInListShortId}/attendees/${attendeePublicId}`);
        return response.data;
    },
    createCheckIn: async (
        checkInListShortId: IdParam,
        attendeePublicId: IdParam,
        action: 'check-in' | 'check-in-and-mark-order-as-paid',
        payment?: {
            payment_method: string;
            payment_reference?: string | null;
            amount?: number | null;
        },
    ) => {
        const attendeePayload: Record<string, unknown> = {
            public_id: attendeePublicId,
            action,
        };
        if (action === 'check-in-and-mark-order-as-paid' && payment) {
            attendeePayload.payment_method = payment.payment_method;
            attendeePayload.payment_reference = payment.payment_reference ?? null;
            attendeePayload.amount = payment.amount ?? null;
        }
        const response = await publicApi.post<GenericDataResponse<PublicCheckIn[]>>(
            `/check-in-lists/${checkInListShortId}/check-ins`,
            {attendees: [attendeePayload]},
        );
        return response.data;
    },
    deleteCheckIn: async (checkInListShortId: IdParam, checkInShortId: IdParam) => {
        const response = await publicApi.delete<GenericDataResponse<PublicCheckIn>>(`/check-in-lists/${checkInListShortId}/check-ins/${checkInShortId}`);
        return response.data;
    },
};
