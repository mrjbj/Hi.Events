import {useQuery} from '@tanstack/react-query';
import {GenericDataResponse, IdParam} from '../types';
import {publicCheckInClient} from '../api/check-in.client';

export type CheckInListFilterOptions = {
    tables: string[];
    groups: Array<{ order_id: number; label: string }>;
};

export const GET_CHECK_IN_LIST_FILTER_OPTIONS_QUERY_KEY = 'getCheckInListFilterOptions';

export const useGetCheckInListFilterOptionsPublic = (checkInListShortId: IdParam, enabled: boolean = true) => {
    return useQuery<GenericDataResponse<CheckInListFilterOptions>>({
        queryKey: [GET_CHECK_IN_LIST_FILTER_OPTIONS_QUERY_KEY, checkInListShortId],
        queryFn: () => publicCheckInClient.getCheckInListFilterOptions(checkInListShortId),
        enabled,
    });
};
