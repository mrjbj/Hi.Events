import {useMutation, useQueryClient} from "@tanstack/react-query";
import {attendeeClientPublic, PatchCheckInListAttendeePayload} from "../api/attendee.client.ts";
import {GET_CHECK_IN_LIST_ATTENDEES_PUBLIC_QUERY_KEY} from "../queries/useGetCheckInListAttendeesPublic.ts";

interface UsePatchCheckInListAttendeeArgs {
    checkInListShortId: string;
}

export const usePatchCheckInListAttendee = ({checkInListShortId}: UsePatchCheckInListAttendeeArgs) => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({attendeePublicId, payload}: {attendeePublicId: string; payload: PatchCheckInListAttendeePayload}) => {
            return attendeeClientPublic.patchOnCheckInList(checkInListShortId, attendeePublicId, payload);
        },
        onSuccess: () => {
            queryClient.invalidateQueries({
                queryKey: [GET_CHECK_IN_LIST_ATTENDEES_PUBLIC_QUERY_KEY, checkInListShortId],
            });
        },
    });
};
