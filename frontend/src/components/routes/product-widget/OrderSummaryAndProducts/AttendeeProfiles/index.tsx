import {t, Trans} from "@lingui/macro";
import {Button, Group, SimpleGrid, Stack, Text, TextInput} from "@mantine/core";
import {IconCheck} from "@tabler/icons-react";
import {useMutation, useQueries, useQueryClient, UseQueryResult} from "@tanstack/react-query";
import {useEffect, useState} from "react";

import {contactPortalClientPublic, MyContactResult} from "../../../../../api/contact-portal.client.ts";
import {AttendeeContactToken} from "../../../../../types.ts";
import {showError, showSuccess} from "../../../../../utilites/notifications.tsx";

const toAttributeStrings = (attrs: Record<string, unknown> | undefined): Record<string, string> => {
    const out: Record<string, string> = {};
    for (const [name, value] of Object.entries(attrs ?? {})) {
        if (value === null || value === undefined) out[name] = '';
        else if (Array.isArray(value)) out[name] = value.join(', ');
        else if (typeof value === 'object') out[name] = JSON.stringify(value);
        else out[name] = String(value);
    }
    return out;
};

export interface AttendeeProfileEntry {
    token: string;
    query: UseQueryResult<MyContactResult, unknown>;
}

/**
 * Fetches per-contact profile data for each attendee_contact_token plus the
 * optional buyer_contact_token on the order. Returns a Map keyed by
 * contact_id so any component sharing a contact (e.g. single-attendee order
 * where buyer and attendee are the same) reads the same data. Queries share
 * queryKey across callers, so PATCH invalidation from any card updates all.
 */
export const useAttendeeProfiles = (
    eventId: number,
    tokens: AttendeeContactToken[] | undefined,
    buyerToken?: AttendeeContactToken | null,
): Map<number, AttendeeProfileEntry> => {
    const combinedTokens: AttendeeContactToken[] = [];
    const seen = new Set<number>();
    (tokens ?? []).forEach((entry) => {
        if (!seen.has(entry.contact_id)) {
            combinedTokens.push(entry);
            seen.add(entry.contact_id);
        }
    });
    if (buyerToken && !seen.has(buyerToken.contact_id)) {
        combinedTokens.push(buyerToken);
    }

    const queries = useQueries({
        queries: combinedTokens.map((entry) => ({
            queryKey: ['attendee-profile', entry.contact_id, eventId],
            queryFn: () => contactPortalClientPublic.getMyContact(entry.token, eventId),
            staleTime: 60_000,
            retry: false,
        })),
    });

    const map = new Map<number, AttendeeProfileEntry>();
    combinedTokens.forEach((entry, idx) => {
        map.set(entry.contact_id, {token: entry.token, query: queries[idx]});
    });
    return map;
};

interface AttendeeProfileCardProps {
    token: string;
    data: MyContactResult | undefined;
    /** If set, PATCH success invalidates this contact's query so any
     *  other card sharing the contact (e.g. buyer === attendee on a
     *  single-attendee order) refetches fresh values. */
    contactId?: number;
    eventId?: number;
}

export const AttendeeProfileCard = ({token, data, contactId, eventId}: AttendeeProfileCardProps) => {
    const queryClient = useQueryClient();
    const [firstName, setFirstName] = useState('');
    const [lastName, setLastName] = useState('');
    const [attrs, setAttrs] = useState<Record<string, string>>({});

    useEffect(() => {
        if (!data?.found) return;
        setFirstName(data.first_name ?? '');
        setLastName(data.last_name ?? '');
        setAttrs(toAttributeStrings(data.attributes));
    }, [data?.found, data?.first_name, data?.last_name]);

    const mutation = useMutation({
        mutationFn: () => contactPortalClientPublic.updateMyContact({
            token,
            first_name: firstName,
            last_name: lastName,
            attributes: attrs,
        }),
        onSuccess: () => {
            showSuccess(t`Profile updated.`);
            if (typeof contactId === 'number' && typeof eventId === 'number') {
                void queryClient.invalidateQueries({queryKey: ['attendee-profile', contactId, eventId]});
            }
        },
        onError: () => showError(t`We couldn't save your changes. Please try again.`),
    });

    if (!data?.found) {
        return (
            <Text c="dimmed" size="sm">
                <Trans>Profile is temporarily unavailable. You can manage it from your email link instead.</Trans>
            </Text>
        );
    }

    return (
        <form onSubmit={(e) => { e.preventDefault(); mutation.mutate(); }}>
            <Stack gap="sm">
                <SimpleGrid cols={{base: 1, sm: 2, md: 3}} spacing="sm">
                    <TextInput
                        label={t`First name`}
                        value={firstName}
                        onChange={(e) => setFirstName(e.currentTarget.value)}
                    />
                    <TextInput
                        label={t`Last name`}
                        value={lastName}
                        onChange={(e) => setLastName(e.currentTarget.value)}
                    />
                    {data.attribute_definitions?.map((def) => (
                        <TextInput
                            key={def.id}
                            label={def.name}
                            value={attrs[def.name] ?? ''}
                            onChange={(e) => setAttrs({...attrs, [def.name]: e.currentTarget.value})}
                        />
                    ))}
                </SimpleGrid>

                <Group justify="flex-end" mt="xs">
                    <Button
                        type="submit"
                        size="xs"
                        loading={mutation.isPending}
                        leftSection={<IconCheck size={14}/>}
                    >
                        {t`Save changes`}
                    </Button>
                </Group>
            </Stack>
        </form>
    );
};
