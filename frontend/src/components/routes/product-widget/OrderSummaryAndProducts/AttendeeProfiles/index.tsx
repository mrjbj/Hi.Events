import {t, Trans} from "@lingui/macro";
import {Accordion, Button, Group, Stack, Text, TextInput} from "@mantine/core";
import {IconCheck, IconUser} from "@tabler/icons-react";
import {useMutation, useQueries} from "@tanstack/react-query";
import {useEffect, useState} from "react";

import {Card} from "../../../../common/Card";
import {contactPortalClientPublic, MyContactResult} from "../../../../../api/contact-portal.client.ts";
import {Attendee, AttendeeContactToken} from "../../../../../types.ts";
import {showError, showSuccess} from "../../../../../utilites/notifications.tsx";

interface AttendeeProfilesProps {
    eventId: number;
    attendees: Attendee[];
    attendeeContactTokens: AttendeeContactToken[];
    buyerEmail?: string;
}

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

interface AttendeeProfileCardProps {
    token: string;
    data: MyContactResult | undefined;
}

const AttendeeProfileCard = ({token, data}: AttendeeProfileCardProps) => {
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

export const AttendeeProfiles = ({eventId, attendees, attendeeContactTokens, buyerEmail}: AttendeeProfilesProps) => {
    if (!attendeeContactTokens || attendeeContactTokens.length === 0) return null;

    const queries = useQueries({
        queries: attendeeContactTokens.map((entry) => ({
            queryKey: ['attendee-profile', entry.contact_id, eventId],
            queryFn: () => contactPortalClientPublic.getMyContact(entry.token, eventId),
            staleTime: 60_000,
            retry: false,
        })),
    });

    const attendeeByContactId = new Map<number, Attendee>();
    for (const a of attendees) {
        if (typeof a.contact_id === 'number' && !attendeeByContactId.has(a.contact_id)) {
            attendeeByContactId.set(a.contact_id, a);
        }
    }

    return (
        <Card>
            <Stack gap="sm">
                <div>
                    <Text size="lg" fw={600}>{t`Update your profile`}</Text>
                    <Text c="dimmed" size="sm">
                        <Trans>
                            Keep your details current so they pre-fill on your next order.
                        </Trans>
                    </Text>
                </div>

                <Accordion multiple variant="separated" radius="md">
                    {attendeeContactTokens.map((entry, idx) => {
                        const attendee = attendeeByContactId.get(entry.contact_id);
                        const isBuyer = buyerEmail && attendee?.email && attendee.email === buyerEmail;
                        const name = attendee
                            ? `${attendee.first_name} ${attendee.last_name}`.trim()
                            : t`Attendee`;
                        const header = isBuyer ? t`Your profile` : name || t`Attendee`;
                        const query = queries[idx];
                        return (
                            <Accordion.Item key={entry.contact_id} value={String(entry.contact_id)}>
                                <Accordion.Control icon={<IconUser size={16}/>}>
                                    {header}
                                </Accordion.Control>
                                <Accordion.Panel>
                                    <AttendeeProfileCard
                                        token={entry.token}
                                        data={query.data}
                                    />
                                </Accordion.Panel>
                            </Accordion.Item>
                        );
                    })}
                </Accordion>
            </Stack>
        </Card>
    );
};
