import {t} from "@lingui/macro";
import {Alert, Button, Divider, Group, Loader, Modal, MultiSelect, Select, SimpleGrid, Stack, Text, TextInput} from "@mantine/core";
import {useForm} from "@mantine/form";
import {useQuery, useQueryClient} from "@tanstack/react-query";
import {useEffect, useState} from "react";
import {IconInfoCircle} from "@tabler/icons-react";
import {Attendee} from "../../../types.ts";
import {contactPortalClientPublic, MyContactResult, ContactAttributeDefinition} from "../../../api/contact-portal.client.ts";
import {usePatchCheckInListAttendee} from "../../../mutations/usePatchCheckInListAttendee.ts";
import {showError, showSuccess} from "../../../utilites/notifications.tsx";
import {GET_CHECK_IN_LIST_ATTENDEES_PUBLIC_QUERY_KEY} from "../../../queries/useGetCheckInListAttendeesPublic.ts";

type AttrValue = string | string[];

const isMultiType = (type: string | null | undefined): boolean => type === 'multi_select';

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
        } else {
            out[name] = isMulti ? [String(value)] : String(value);
        }
    }
    return out;
};

interface CaptureAttendeeOnArrivalModalProps {
    opened: boolean;
    attendee: Attendee | null;
    eventId: number | undefined;
    checkInListShortId: string | undefined;
    onCheckInConfirmed: (attendee: Attendee) => Promise<void> | void;
    onClose: () => void;
}

/**
 * Door-staff modal that gates check-in on capturing missing attendee details.
 *
 * Fires when an attendee marked `profile_completion_recommended` is about to be
 * checked in. Required fields (name + email + any required registration
 * questions) must be filled before the actual check-in lands — otherwise the
 * staff can press Cancel and bail out with no record of the attempt.
 *
 * Save flow on submit:
 *   1. PATCH /check-in-lists/{shortId}/attendees/{publicId} → name + email.
 *      The backend `resyncContactLink` will re-route attendees.contact_id to a
 *      new contact when the email changes.
 *   2. PATCH /contacts/me → name + registration attributes on the (possibly
 *      newly linked) contact.
 *   3. Invalidate the attendees list query so the row reflects the new data.
 *   4. Trigger the normal check-in via the parent's onCheckInConfirmed.
 */
export const CaptureAttendeeOnArrivalModal = ({
                                                  opened,
                                                  attendee,
                                                  eventId,
                                                  checkInListShortId,
                                                  onCheckInConfirmed,
                                                  onClose,
                                              }: CaptureAttendeeOnArrivalModalProps) => {
    const queryClient = useQueryClient();
    const contactToken = attendee?.contact_token ?? null;
    const contactId = attendee?.contact_id ?? null;

    const profileQuery = useQuery<MyContactResult>({
        queryKey: ['attendee-profile', contactId, eventId],
        queryFn: () => contactPortalClientPublic.getMyContact(contactToken!, eventId!),
        enabled: opened && !!contactToken && typeof contactId === 'number' && !!eventId,
        staleTime: 60_000,
        retry: false,
    });

    const patchAttendee = usePatchCheckInListAttendee({
        checkInListShortId: checkInListShortId ?? '',
    });

    const [attrs, setAttrs] = useState<Record<string, AttrValue>>({});
    const [submitting, setSubmitting] = useState(false);

    const attributeDefinitions = profileQuery.data?.attribute_definitions ?? [];

    const form = useForm({
        initialValues: {
            first_name: '',
            last_name: '',
            email: '',
            seat_info: '',
        },
        validate: {
            first_name: (value) => !value?.trim() ? t`First name is required` : null,
            last_name: (value) => !value?.trim() ? t`Last name is required` : null,
            email: (value) => {
                if (!value?.trim()) return t`Email is required`;
                return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value) ? null : t`Invalid email`;
            },
        },
    });

    // Pre-fill the form when a new attendee is opened. Keep the contact-portal
    // attribute values in sync if they load later.
    useEffect(() => {
        if (attendee) {
            form.setValues({
                first_name: attendee.first_name ?? '',
                last_name: attendee.last_name ?? '',
                email: attendee.email ?? '',
                seat_info: attendee.seat_info ?? '',
            });
            form.resetDirty();
        }
    }, [attendee?.public_id]);

    useEffect(() => {
        if (profileQuery.data?.found) {
            setAttrs(toAttributeValues(profileQuery.data.attributes, profileQuery.data.attribute_definitions));
        } else {
            setAttrs({});
        }
    }, [profileQuery.data?.found, profileQuery.data?.attribute_definitions, attendee?.public_id]);

    const handleSubmit = form.onSubmit(async (values) => {
        if (!attendee || !checkInListShortId) return;

        // Required-attribute check: every registration question marked
        // required must have a non-empty answer. Door staff can't skip these.
        const missingRequired: string[] = [];
        for (const def of attributeDefinitions) {
            // `required` isn't on the ContactAttributeDefinition shape today —
            // treat it as optional metadata. Guarded read keeps this forward-
            // compatible.
            const isRequired = (def as ContactAttributeDefinition & {required?: boolean}).required === true;
            if (!isRequired) continue;
            const v = attrs[def.name];
            const empty = v === undefined || v === null || v === ''
                || (Array.isArray(v) && v.length === 0);
            if (empty) {
                missingRequired.push(def.name);
            }
        }
        if (missingRequired.length > 0) {
            showError(t`Please answer: ${missingRequired.join(', ')}`);
            return;
        }

        setSubmitting(true);
        try {
            // 1. Update the attendee row first (name + email + seat). The
            //    backend relink logic spins up a contact for the new email if
            //    needed; seat changes also fan out to bundle siblings.
            const trimmedSeat = values.seat_info.trim();
            const patchResult = await patchAttendee.mutateAsync({
                attendeePublicId: attendee.public_id,
                payload: {
                    first_name: values.first_name.trim(),
                    last_name: values.last_name.trim(),
                    email: values.email.trim(),
                    seat_info: trimmedSeat === '' ? null : trimmedSeat,
                },
            });

            // 2. Save registration question answers + name on the contact.
            //    Prefer the new token from the patch response (when relink
            //    happened); fall back to the cached one otherwise.
            // The check-in PATCH response shape mirrors the attendees one:
            // when relink fires it returns a fresh contact_token.
            const refreshedAttendee = (patchResult as any)?.data ?? null;
            const refreshedToken: string | null = refreshedAttendee?.contact_token ?? contactToken;
            if (refreshedToken) {
                try {
                    await contactPortalClientPublic.updateMyContact({
                        token: refreshedToken,
                        first_name: values.first_name.trim(),
                        last_name: values.last_name.trim(),
                        attributes: attrs,
                    });
                } catch {
                    // Don't block check-in on attribute save failure — the
                    // attendee row update already landed. Surface a soft warn.
                    showError(t`Saved attendee details, but couldn't save registration question answers.`);
                }
            }

            // 3. Refresh the attendees list so the row reflects the changes.
            void queryClient.invalidateQueries({
                queryKey: [GET_CHECK_IN_LIST_ATTENDEES_PUBLIC_QUERY_KEY, checkInListShortId],
            });

            // 4. Fire the actual check-in. Use the updated attendee so any
            //    downstream logic sees the new identity.
            const updatedAttendee: Attendee = {
                ...attendee,
                first_name: values.first_name.trim(),
                last_name: values.last_name.trim(),
                email: values.email.trim(),
                seat_info: trimmedSeat === '' ? null : trimmedSeat,
            };
            await onCheckInConfirmed(updatedAttendee);

            showSuccess(t`Attendee captured and checked in.`);
            onClose();
        } catch (error: any) {
            showError(error?.response?.data?.message || t`Couldn't complete check-in. Please try again.`);
        } finally {
            setSubmitting(false);
        }
    });

    const renderAttributeField = (def: ContactAttributeDefinition) => {
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
    };

    return (
        <Modal
            opened={opened}
            onClose={onClose}
            title={t`Confirm attendee details before check-in`}
            size="lg"
            closeOnClickOutside={!submitting}
            closeOnEscape={!submitting}
        >
            <Alert
                color="orange"
                icon={<IconInfoCircle size={16}/>}
                mb="md"
                radius="md"
            >
                <Text size="sm">
                    {t`Please confirm this attendee's check-in details below, then check them in.`}
                </Text>
                {!attendee?.email && (
                    <Text size="sm" fw={500} mt={4}>
                        {t`No email is on file — please add one so this attendee can receive their ticket and confirmations.`}
                    </Text>
                )}
                {attendee?.confirm_at_checkin && (
                    <Text size="xs" c="dimmed" mt={4}>
                        {t`This attendee is flagged "Confirm details at check-in." Saving these details will clear that flag.`}
                    </Text>
                )}
                {attendee?.buyer_first_name && (
                    <Text size="xs" c="dimmed" mt={4}>
                        {t`Originally purchased by ${attendee.buyer_first_name} ${attendee.buyer_last_name ?? ''}`}{attendee.buyer_email ? ` (${attendee.buyer_email})` : ''}
                    </Text>
                )}
            </Alert>

            <form onSubmit={handleSubmit}>
                <Stack gap="sm">
                    <SimpleGrid cols={{base: 1, sm: 2}} spacing="sm">
                        <TextInput
                            label={t`First name`}
                            placeholder={t`Guest's first name`}
                            required
                            {...form.getInputProps('first_name')}
                        />
                        <TextInput
                            label={t`Last name`}
                            placeholder={t`Guest's last name`}
                            required
                            {...form.getInputProps('last_name')}
                        />
                    </SimpleGrid>
                    <TextInput
                        label={t`Email`}
                        placeholder={t`name@example.com`}
                        type="email"
                        required
                        {...form.getInputProps('email')}
                    />
                    <TextInput
                        label={t`Table / Seat (optional)`}
                        placeholder={t`e.g. Table 5`}
                        maxLength={100}
                        {...form.getInputProps('seat_info')}
                    />

                    {contactToken && profileQuery.isLoading && (
                        <Group justify="center" p="sm">
                            <Loader size="sm"/>
                        </Group>
                    )}

                    {contactToken && !profileQuery.isLoading && attributeDefinitions.length > 0 && (
                        <>
                            <Divider my="xs" label={t`Registration questions`} labelPosition="left"/>
                            <SimpleGrid cols={{base: 1, sm: 2}} spacing="sm">
                                {attributeDefinitions.map(renderAttributeField)}
                            </SimpleGrid>
                        </>
                    )}
                </Stack>

                <Group justify="flex-end" gap="sm" mt="md">
                    <Button variant="default" onClick={onClose} disabled={submitting}>
                        {t`Cancel`}
                    </Button>
                    <Button type="submit" loading={submitting} color="teal">
                        {t`Save & Check in`}
                    </Button>
                </Group>
            </form>
        </Modal>
    );
};
