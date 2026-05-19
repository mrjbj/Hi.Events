import {useQuery} from '@tanstack/react-query';
import {GenericDataResponse, IdParam} from '../types';
import {attendeesClient} from '../api/attendee.client';

export type EventAttendeeFilterOptions = {
    tables: string[];
    groups: Array<{order_id: number; label: string}>;
};

export const GET_EVENT_ATTENDEE_FILTER_OPTIONS_QUERY_KEY = 'getEventAttendeeFilterOptions';

export const useGetEventAttendeeFilterOptions = (eventId: IdParam) => {
    return useQuery<GenericDataResponse<EventAttendeeFilterOptions>>({
        queryKey: [GET_EVENT_ATTENDEE_FILTER_OPTIONS_QUERY_KEY, eventId],
        queryFn: () => attendeesClient.getFilterOptions(eventId),
        enabled: Boolean(eventId),
    });
};
