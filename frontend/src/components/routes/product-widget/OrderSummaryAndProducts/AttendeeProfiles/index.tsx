import {t, Trans} from "@lingui/macro";
import {Button, Group, MultiSelect, Select, SimpleGrid, Stack, Text, TextInput} from "@mantine/core";
import {IconCheck} from "@tabler/icons-react";
import {useQueries, useQueryClient, UseQueryResult} from "@tanstack/react-query";
import {MutableRefObject, useEffect, useState} from "react";

import {ContactAttributeDefinition, contactPortalClientPublic, MyContactResult} from "../../../../../api/contact-portal.client.ts";
import {AttendeeContactToken} from "../../../../../types.ts";
import {showError, showSuccess} from "../../../../../utilites/notifications.tsx";

type AttrValue = string | string[];

const isMultiType = (type: string | null | undefined): boolean => type === 'multi_select';

/**
 * Normalize the raw attributes map into the shape the form expects:
 * - multi_select definitions become string[] (even if DB had a single value)
 * - everything else becomes a string
 */
const toAttributeValues = (
    attrs: Record<string, unknown> | undefined,
    definitions: ContactAttributeDefinition[] | undefined,
): Record<string, AttrValue> => {
    const typeByName = new Map<string, string | null>();
    (definitions ?? []).forEach((d) => typeByName.set(d.name, d.type));
    const out: Record<string, AttrValue> = {};
    for (const [name, value] of Object.entries(attrs ?? {})) {
        const isMulti = isMultiType(typeByName.get(name));
        if (value === null || value === undefined) {
            out[name] = isMulti ? [] : '';
        } else if (Array.isArray(value)) {
            out[name] = isMulti ? value.map(String) : value.join(', ');
        } else if (typeof value === 'object') {
            out[name] = isMulti ? [] : JSON.stringify(value);
        } else {
            out[name] = isMulti ? [String(value)] : String(value);
        }
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
    /** Optional extra persistence run as part of "Save changes" (e.g. the
     *  check-in modal saving the confirm-at-check-in flag and mirroring the
     *  edited name onto the attendee row). Receives the names just written to
     *  the contact so the caller can keep the attendee record in sync. Awaited
     *  alongside the contact update; a rejection fails the whole save. */
    additionalSaveAsync?: (saved: {firstName: string; lastName: string}) => Promise<void>;
    /** Called after a successful save — e.g. to close the containing modal. */
    onSaved?: () => void;
    /** When provided, the card publishes a silent saver into this ref so a
     *  parent can fold the profile save into a larger commit (e.g. the check-in
     *  modal's "Save email", which persists name, questions, the confirm flag
     *  and the email together). The silent saver suppresses this card's own
     *  toast/onSaved and rethrows on failure so the parent can react. */
    saveRef?: MutableRefObject<(() => Promise<void>) | null>;
    /** Hide the card's own "Save changes" button when a parent drives the save
     *  itself (via saveRef) — e.g. the check-in modal's single combined save. */
    showSaveButton?: boolean;
}

export const AttendeeProfileCard = ({token, data, contactId, eventId, additionalSaveAsync, onSaved, saveRef, showSaveButton = true}: AttendeeProfileCardProps) => {
    const queryClient = useQueryClient();
    const [firstName, setFirstName] = useState('');
    const [lastName, setLastName] = useState('');
    const [attrs, setAttrs] = useState<Record<string, AttrValue>>({});
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        if (!data?.found) return;
        setFirstName(data.first_name ?? '');
        setLastName(data.last_name ?? '');
        setAttrs(toAttributeValues(data.attributes, data.attribute_definitions));
    }, [data?.found, data?.first_name, data?.last_name, data?.attribute_definitions]);

    const performSave = async (silent = false) => {
        setSaving(true);
        try {
            await contactPortalClientPublic.updateMyContact({
                token,
                first_name: firstName,
                last_name: lastName,
                attributes: attrs,
            });
            if (additionalSaveAsync) {
                await additionalSaveAsync({firstName, lastName});
            }
            if (typeof contactId === 'number' && typeof eventId === 'number') {
                void queryClient.invalidateQueries({queryKey: ['attendee-profile', contactId, eventId]});
            }
            if (!silent) {
                showSuccess(t`Profile updated.`);
                onSaved?.();
            }
        } catch (error) {
            if (silent) throw error;
            showError(t`We couldn't save your changes. Please try again.`);
        } finally {
            setSaving(false);
        }
    };

    useEffect(() => {
        if (!saveRef) return;
        if (!data?.found) {
            saveRef.current = null;
            return;
        }
        saveRef.current = () => performSave(true);
        return () => {
            saveRef.current = null;
        };
    });

    if (!data?.found) {
        return (
            <Text c="dimmed" size="sm">
                <Trans>Profile is temporarily unavailable. You can manage it from your email link instead.</Trans>
            </Text>
        );
    }

    const fields = (
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
                    {data.attribute_definitions?.map((def) => {
                        const current = attrs[def.name];
                        const options = (def.options ?? []).map((o) => ({value: o, label: o}));

                        if (def.type === 'select') {
                            return (
                                <Select
                                    key={def.id}
                                    label={def.name}
                                    data={options}
                                    value={typeof current === 'string' ? current : ''}
                                    onChange={(v) => setAttrs({...attrs, [def.name]: v ?? ''})}
                                    clearable
                                    searchable
                                />
                            );
                        }

                        if (def.type === 'multi_select') {
                            const arr = Array.isArray(current)
                                ? current
                                : current
                                    ? String(current).split(',').map((s) => s.trim()).filter(Boolean)
                                    : [];
                            return (
                                <MultiSelect
                                    key={def.id}
                                    label={def.name}
                                    data={options}
                                    value={arr}
                                    onChange={(v) => setAttrs({...attrs, [def.name]: v})}
                                    clearable
                                    searchable
                                />
                            );
                        }

                        return (
                            <TextInput
                                key={def.id}
                                label={def.name}
                                value={typeof current === 'string' ? current : ''}
                                onChange={(e) => setAttrs({...attrs, [def.name]: e.currentTarget.value})}
                            />
                        );
                    })}
                </SimpleGrid>

                {showSaveButton && (
                    <Group justify="flex-end" mt="xs">
                        <Button
                            type="submit"
                            size="xs"
                            loading={saving}
                            leftSection={<IconCheck size={14}/>}
                        >
                            {t`Save changes`}
                        </Button>
                    </Group>
                )}
            </Stack>
    );

    if (!showSaveButton) {
        return fields;
    }

    return (
        <form onSubmit={(e) => { e.preventDefault(); void performSave(); }}>
            {fields}
        </form>
    );
};
