import {useQuery} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {contactAttributeDefinitionClient} from "../api/contact-attribute-definition.client.ts";

export const GET_CONTACT_ATTRIBUTE_OPTION_USAGE_QUERY_KEY = 'getContactAttributeOptionUsage';

export const useGetContactAttributeOptionUsage = (accountId: IdParam, definitionId: IdParam | null, enabled = true) => {
    return useQuery({
        queryKey: [GET_CONTACT_ATTRIBUTE_OPTION_USAGE_QUERY_KEY, accountId, definitionId],
        queryFn: async () => {
            if (!accountId || !definitionId) return {data: {}};
            return await contactAttributeDefinitionClient.optionUsage(accountId, definitionId);
        },
        enabled: enabled && !!accountId && !!definitionId,
    });
};
