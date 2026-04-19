import {publicApi} from "./public-client.ts";

export interface ContactLookupResult {
    found: boolean;
    first_name: string | null;
    last_name: string | null;
    answered_question_ids: number[];
}

export interface ContactPrefillResult {
    found: boolean;
    first_name: string | null;
    last_name: string | null;
    question_answers: Record<string, unknown>;
    answered_question_ids: number[];
}

export const contactClientPublic = {
    lookupByEmail: async (
        eventId: number,
        email: string,
        turnstileToken?: string | null,
    ): Promise<ContactLookupResult> => {
        const headers: Record<string, string> = {};
        if (turnstileToken) {
            headers["cf-turnstile-response"] = turnstileToken;
        }
        const response = await publicApi.post<ContactLookupResult>(
            `events/${eventId}/contact-lookup`,
            {email},
            {headers},
        );
        return response.data;
    },

    prefillFromToken: async (eventId: number, token: string): Promise<ContactPrefillResult> => {
        const response = await publicApi.post<ContactPrefillResult>(
            `events/${eventId}/contact-prefill`,
            {token},
        );
        return response.data;
    },
};
