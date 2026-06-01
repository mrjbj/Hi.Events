import {publicApi} from "./public-client.ts";
import {
    GenericDataResponse,
    GenericPaginatedResponse,
    IdParam,
    Order,
    QueryFilters,
    StripePaymentIntent
} from "../types.ts";
import {api} from "./client.ts";
import {queryParamsHelper} from "../utilites/queryParamsHelper.ts";

export interface OrderDetails {
    first_name: string,
    last_name: string,
    email: string,
}

export interface AttendeeDetails extends OrderDetails {
    product_id: number,
}

export interface FinaliseOrderPayload {
    order: OrderDetails,
    attendees: AttendeeDetails[],
}

export interface EditOrderPayload {
    first_name: string,
    last_name: string,
    email: string,
    notes: string,
}

export interface ProductPriceQuantityFormValue {
    price?: number,
    quantity: number,
    price_id: number,
}

export interface ProductFormValue {
    product_id: number,
    quantities: ProductPriceQuantityFormValue[],
}

export interface ProductFormPayload {
    products?: ProductFormValue[],
    promo_code: string | null,
    affiliate_code?: string | null,
    session_identifier?: string,
}

export interface RefundOrderPayload {
    amount: number;
    notify_buyer: boolean;
    cancel_order: boolean;
}

export interface RecordOrderPaymentPayload {
    transaction_type: string;
    payment_method?: string | null;
    amount: number;
    reference?: string | null;
    note?: string | null;
}

export interface ReverseOrderPaymentPayload {
    note: string;
}

export const orderClient = {
    all: async (eventId: IdParam, pagination: QueryFilters) => {
        const response = await api.get<GenericPaginatedResponse<Order>>(
            `events/${eventId}/orders` + queryParamsHelper.buildQueryString(pagination),
        );
        return response.data;
    },

    findByID: async (eventId: IdParam, orderId: IdParam) => {
        const response = await api.get<GenericDataResponse<Order>>(`events/${eventId}/orders/${orderId}`);
        return response.data;
    },

    refund: async (eventId: IdParam, orderId: IdParam, refundPayload: RefundOrderPayload) => {
        const response = await api.post<GenericDataResponse<Order>>('events/' + eventId + '/orders/' + orderId + '/refund', refundPayload);
        return response.data;
    },

    resendConfirmation: async (eventId: IdParam, orderId: IdParam) => {
        const response = await api.post<GenericDataResponse<Order>>('events/' + eventId + '/orders/' + orderId + '/resend_confirmation');
        return response.data;
    },

    cancel: async (eventId: IdParam, orderId: IdParam, refund?: boolean, notifyBuyer?: boolean, notifyRefund?: boolean) => {
        const response = await api.post<GenericDataResponse<Order>>('events/' + eventId + '/orders/' + orderId + '/cancel', {
            refund: refund ?? false,
            notify_buyer: notifyBuyer ?? false,
            notify_refund: notifyRefund ?? false,
        });
        return response.data;
    },

    exportOrders: async (eventId: IdParam): Promise<Blob> => {
        const response = await api.post(`events/${eventId}/orders/export`, {}, {
            responseType: 'blob',
        });

        return new Blob([response.data]);
    },

    markAsPaid: async (eventId: IdParam, orderId: IdParam) => {
        const response = await api.post<GenericDataResponse<Order>>('events/' + eventId + '/orders/' + orderId + '/mark-as-paid');
        return response.data;
    },

    recordPayment: async (eventId: IdParam, orderId: IdParam, payload: RecordOrderPaymentPayload) => {
        const response = await api.post<GenericDataResponse<Order>>(`events/${eventId}/orders/${orderId}/payments`, payload);
        return response.data;
    },

    reversePayment: async (eventId: IdParam, orderId: IdParam, paymentId: IdParam, payload: ReverseOrderPaymentPayload) => {
        const response = await api.post<GenericDataResponse<Order>>(`events/${eventId}/orders/${orderId}/payments/${paymentId}/reverse`, payload);
        return response.data;
    },

    downloadInvoice: async (eventId: IdParam, orderId: IdParam): Promise<Blob> => {
        const response = await api.get(`events/${eventId}/orders/${orderId}/invoice`, {
            responseType: 'blob',
        });

        return new Blob([response.data]);
    },

    editOrder: async (eventId: IdParam, orderId: IdParam, payload: EditOrderPayload) => {
        const response = await api.put<GenericDataResponse<Order>>(`events/${eventId}/orders/${orderId}`, payload);
        return response.data;
    },

    bulkAssignAttendeeSeatInfo: async (eventId: IdParam, orderId: IdParam, seatInfo: string | null) => {
        const response = await api.patch<{updated_count: number}>(
            `events/${eventId}/orders/${orderId}/attendees/seat-info`,
            {seat_info: seatInfo},
        );
        return response.data;
    },
}

export const orderClientPublic = {
    create: async (eventId: number, createOrderPayload: ProductFormPayload, turnstileToken?: string | null) => {
        const headers: Record<string, string> = {};
        if (turnstileToken) headers["cf-turnstile-response"] = turnstileToken;
        const response = await publicApi.post<GenericDataResponse<Order>>(
            'events/' + eventId + '/order',
            createOrderPayload,
            {headers},
        );
        return response.data;
    },

    findByShortId: async (
        eventId: number,
        orderShortId: string,
        includes: string[] = [],
        sessionIdentifier?: string
    ) => {
        const query = new URLSearchParams();
        if (includes.length > 0) {
            query.append("include", includes.join(","));
        }
        if (sessionIdentifier) {
            query.append("session_identifier", sessionIdentifier);
        }

        const response = await publicApi.get<GenericDataResponse<Order>>(
            `events/${eventId}/order/${orderShortId}?${query.toString()}`
        );

        return response.data;
    },

    findOrderStripePaymentIntent: async (eventId: number, orderShortId: string) => {
        return await publicApi.get<StripePaymentIntent>(`events/${eventId}/order/${orderShortId}/stripe/payment_intent`);
    },

    createStripePaymentIntent: async (eventId: number, orderShortId: string) => {
        const response = await publicApi.post<{
            client_secret: string,
            account_id?: string,
            public_key: string,
            stripe_platform?: string,
        }>(`events/${eventId}/order/${orderShortId}/stripe/payment_intent`);
        return response.data;
    },

    finaliseOrder: async (
        eventId: number,
        orderShortId: string,
        payload: FinaliseOrderPayload
    ) => {
        const response = await publicApi.put<GenericDataResponse<Order>>(`events/${eventId}/order/${orderShortId}`, payload);
        return response.data;
    },

    transitionToOfflinePayment: async (eventId: IdParam, orderShortId: IdParam) => {
        const response = await publicApi.post<GenericDataResponse<Order>>(`events/${eventId}/order/${orderShortId}/await-offline-payment`);
        return response.data;
    },

    downloadInvoice: async (eventId: IdParam, orderShortId: IdParam): Promise<Blob> => {
        const response = await publicApi.get(`events/${eventId}/order/${orderShortId}/invoice`, {
            responseType: 'blob',
        });

        return new Blob([response.data]);
    },

    abandonOrder: async (eventId: IdParam, orderShortId: IdParam) => {
        const response = await publicApi.post<GenericDataResponse<Order>>(`events/${eventId}/order/${orderShortId}/abandon`);
        return response.data;
    },
}
