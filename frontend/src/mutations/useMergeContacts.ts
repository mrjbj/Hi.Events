import {useMutation, useQueryClient} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {contactClient} from "../api/contact.client.ts";
import {GET_CONTACTS_QUERY_KEY} from "../queries/useGetContacts.ts";
import {GET_CONTACT_QUERY_KEY} from "../queries/useGetContact.ts";
import {useGetMe} from "../queries/useGetMe.ts";

export const useMergeContacts = () => {
    const queryClient = useQueryClient();
    const {data: me} = useGetMe();

    return useMutation({
        mutationFn: ({survivorId, sourceContactId}: {
            survivorId: IdParam,
            sourceContactId: IdParam,
        }) => contactClient.merge(me?.account_id, survivorId, sourceContactId),

        onSuccess: () => {
            queryClient.invalidateQueries({queryKey: [GET_CONTACTS_QUERY_KEY]});
            queryClient.invalidateQueries({queryKey: [GET_CONTACT_QUERY_KEY]});
        },
    });
};
