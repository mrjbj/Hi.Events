import {publicApi} from "./public-client.ts";

export interface ContactAttributeDefinition {
    id: number;
    name: string;
    type: string | null;
    options: string[];
}

export interface MyContactResult {
    found: boolean;
    first_name: string | null;
    last_name: string | null;
    attributes: Record<string, unknown>;
    attribute_definitions: ContactAttributeDefinition[];
}

export interface UpdateMyContactPayload {
    token: string;
    first_name?: string;
    last_name?: string;
    attributes?: Record<string, unknown>;
}

export const contactPortalClientPublic = {
    getMyContact: async (token: string, eventId?: number): Promise<MyContactResult> => {
        const params: Record<string, string | number> = {c: token};
        if (typeof eventId === "number") params.event_id = eventId;
        const response = await publicApi.get<MyContactResult>('contacts/me', {params});
        return response.data;
    },

    updateMyContact: async (payload: UpdateMyContactPayload): Promise<MyContactResult> => {
        const response = await publicApi.patch<MyContactResult>('contacts/me', payload);
        return response.data;
    },
};
