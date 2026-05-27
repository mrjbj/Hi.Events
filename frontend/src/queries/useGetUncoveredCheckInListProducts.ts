import {useQuery} from "@tanstack/react-query";
import {IdParam} from "../types.ts";
import {checkInListClient} from "../api/check-in-list.client.ts";

export const GET_UNCOVERED_CHECK_IN_LIST_PRODUCTS_QUERY_KEY = 'getUncoveredCheckInListProducts';

export const useGetUncoveredCheckInListProducts = (eventId: IdParam) => {
    return useQuery({
        queryKey: [GET_UNCOVERED_CHECK_IN_LIST_PRODUCTS_QUERY_KEY, eventId],

        queryFn: async () => {
            return await checkInListClient.uncoveredProducts(eventId);
        },

        enabled: !!eventId,
    });
};
