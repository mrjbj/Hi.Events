import {useMutation, useQueryClient} from "@tanstack/react-query";
import {userClient} from "../api/user.client.ts";
import {GET_USERS_QUERY_KEY} from "../queries/useGetUsers.ts";
import {IdParam} from "../types.ts";

export const useUpdateUserSuperAdminStatus = () => {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: ({userId, isSuperAdmin}: {
            userId: IdParam,
            isSuperAdmin: boolean,
        }) => userClient.updateSuperAdminStatus(userId, isSuperAdmin),

        onSuccess: () => queryClient.invalidateQueries({queryKey: [GET_USERS_QUERY_KEY]}),
    });
};
