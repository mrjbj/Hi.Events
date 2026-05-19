import {useQuery} from '@tanstack/react-query';
import {GenericDataResponse, IdParam} from '../types';
import {publicCheckInClient} from '../api/check-in.client';

export type CheckInListSibling = {
    short_id: string;
    name: string;
    is_active: boolean;
    is_expired: boolean;
};

export const GET_CHECK_IN_LIST_SIBLINGS_QUERY_KEY = 'getCheckInListSiblings';

export const useGetCheckInListSiblingsPublic = (checkInListShortId: IdParam, enabled: boolean = true) => {
    return useQuery<GenericDataResponse<CheckInListSibling[]>>({
        queryKey: [GET_CHECK_IN_LIST_SIBLINGS_QUERY_KEY, checkInListShortId],
        queryFn: () => publicCheckInClient.getCheckInListSiblings(checkInListShortId),
        enabled,
    });
};
