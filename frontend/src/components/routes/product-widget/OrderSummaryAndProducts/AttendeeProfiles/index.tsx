import {t, Trans} from "@lingui/macro";
import {Button, Group, Stack, Text, TextInput} from "@mantine/core";
import {IconCheck} from "@tabler/icons-react";
import {useMutation, useQueries, UseQueryResult} from "@tanstack/react-query";
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
 * Fetches per-contact profile data for each attendee_contact_token on the
 * order and exposes a Map keyed by contact_id. Callers render the edit form
 * via <AttendeeProfileCard> where they choose (e.g. inline on the guest row).
 */
export const useAttendeeProfiles = (
    eventId: number,
    tokens: AttendeeContactToken[] | undefined,
): Map<number, AttendeeProfileEntry> => {
    const queries = useQueries({
        queries: (tokens ?? []).map((entry) => ({
            queryKey: ['attendee-profile', entry.contact_id, eventId],
            queryFn: () => contactPortalClientPublic.getMyContact(entry.token, eventId),
            staleTime: 60_000,
            retry: false,
        })),
    });

    const map = new Map<number, AttendeeProfileEntry>();
    (tokens ?? []).forEach((entry, idx) => {
        map.set(entry.contact_id, {token: entry.token, query: queries[idx]});
    });
    return map;
};

interface AttendeeProfileCardProps {
    token: string;
    data: MyContactResult | undefined;
}

export const AttendeeProfileCard = ({token, data}: AttendeeProfileCardProps) => {
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
        onSuccess: () => showSuccess(t`Profile updated.`),
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
                <Group grow>
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
                </Group>

                {data.attribute_definitions?.map((def) => (
                    <TextInput
                        key={def.id}
                        label={def.name}
                        value={attrs[def.name] ?? ''}
                        onChange={(e) => setAttrs({...attrs, [def.name]: e.currentTarget.value})}
                    />
                ))}

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
