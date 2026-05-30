import {t} from "@lingui/macro";
import {Button, Divider, Group, Modal, MultiSelect, Select, SimpleGrid, Switch, TextInput} from "@mantine/core";
import {useForm} from "@mantine/form";
import {useEffect, useState} from "react";
import {useQueryClient} from "@tanstack/react-query";
import {Attendee} from "../../../../../types";
import classes from "./EditAttendeeModal.module.scss";
import {InputGroup} from "../../../../common/InputGroup";
import {AttendeeProfileEntry} from "../AttendeeProfiles";
import {contactPortalClientPublic} from "../../../../../api/contact-portal.client.ts";
import {SelfServiceUpdateResult} from "../../../../../api/self-service.client.ts";
import {showError} from "../../../../../utilites/notifications.tsx";
import {confirmationDialog} from "../../../../../utilites/confirmationDialog.tsx";

type AttrValue = string | string[];

const isMultiType = (type: string | null | undefined): boolean => type === 'multi_select';

const toAttributeValues = (
    attrs: Record<string, unknown> | undefined,
    definitions: { id: number; name: string; type: string | null; options: string[] }[] | undefined,
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

interface EditAttendeeModalProps {
    opened: boolean;
    onClose: () => void;
    attendee: Attendee;
    profileEntry?: AttendeeProfileEntry;
    eventId?: number;
    /**
     * Performs the attendee PATCH and resolves with the server response (which
     * may include `new_contact_token` when the backend relinked the attendee
     * to a different contact due to an email change). The modal then uses that
     * token to save registration-question attributes against the new contact.
     */
    editAttendeeAsync: (data: { first_name?: string; last_name?: string; email?: string; confirm_at_checkin?: boolean }) => Promise<SelfServiceUpdateResult>;
}

/**
 * Unified attendee editor: name + email + registration questions in one form.
 *
 * Two records get touched on save:
 *  - The attendee row (first_name, last_name, email) via the parent's
 *    onSuccess callback, which fires PATCH /attendees. The backend relink
 *    logic in SelfServiceEditAttendeeService keeps attendees.contact_id
 *    pointing at the right contact (find-or-create on email change).
 *  - The contact (registration question answers + name) via PATCH /contacts/me,
 *    only when the email hasn't changed. If the email DID change, the
 *    backend will relink to a different contact; applying these answers
 *    via the stale contact_token would write to the wrong contact, so we
 *    skip — the user can re-enter answers for the new person if needed.
 */
export const EditAttendeeModal = ({
                                      opened,
                                      onClose,
                                      attendee,
                                      profileEntry,
                                      eventId,
                                      editAttendeeAsync,
                                  }: EditAttendeeModalProps) => {
    const queryClient = useQueryClient();
    const [submitting, setSubmitting] = useState(false);
    const form = useForm({
        initialValues: {
            first_name: attendee.first_name,
            last_name: attendee.last_name,
            email: attendee.email,
            confirm_at_checkin: attendee.confirm_at_checkin ?? false,
        },
        validate: {
            first_name: (value) => !value ? t`First name is required` : null,
            last_name: (value) => !value ? t`Last name is required` : null,
            email: (value) => {
                if (!value) return t`Email is required`;
                if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) return t`Invalid email`;
                return null;
            },
        },
    });

    const contactData = profileEntry?.query.data;
    const attributeDefinitions = contactData?.attribute_definitions ?? [];

    const [attrs, setAttrs] = useState<Record<string, AttrValue>>({});

    useEffect(() => {
        if (contactData?.found) {
            setAttrs(toAttributeValues(contactData.attributes, contactData.attribute_definitions));
        } else {
            setAttrs({});
        }
    }, [contactData?.found, contactData?.attribute_definitions, attendee.id]);

    const handleSubmit = (values: typeof form.values) => {
        const oldEmail = attendee.email;
        const emailChanged = !!values.email && values.email !== oldEmail;

        // Mirror the check-in door: when the email actually changes and there
        // was a previous address on file, warn that the old address will be
        // notified before we send anything.
        if (emailChanged && oldEmail) {
            confirmationDialog(
                t`The current address (${oldEmail}) will be emailed to let them know the ticket's email address has changed. Continue?`,
                () => void performSave(values),
                {confirm: t`Yes, save and notify`, cancel: t`Cancel`},
            );
            return;
        }

        void performSave(values);
    };

    const performSave = async (values: typeof form.values) => {
        setSubmitting(true);
        try {
            // 1. Save the attendee row first. The backend's resyncContactLink may
            //    re-route attendees.contact_id to a different contact (find or
            //    create by email) and return a fresh contact_token in the
            //    response. Capture that to chain the attribute save below.
            const attendeeResult = await editAttendeeAsync({
                first_name: values.first_name,
                last_name: values.last_name,
                email: values.email,
                confirm_at_checkin: values.confirm_at_checkin,
            });

            // 2. If we have any contact token (the new one from the response,
            //    or the cached one when email didn't change), save the
            //    registration-question attributes plus name fields. Skipped
            //    silently when no token is available (legacy orders pre-contacts).
            const contactToken = attendeeResult.new_contact_token ?? profileEntry?.token;
            if (contactToken) {
                try {
                    await contactPortalClientPublic.updateMyContact({
                        token: contactToken,
                        first_name: values.first_name,
                        last_name: values.last_name,
                        attributes: attrs,
                    });

                    // Invalidate the per-contact profile query so the order
                    // summary page re-reads the attributes on next render.
                    const contactId = attendee.contact_id;
                    if (typeof contactId === 'number' && typeof eventId === 'number') {
                        void queryClient.invalidateQueries({
                            queryKey: ['attendee-profile', contactId, eventId],
                        });
                    }
                } catch {
                    showError(t`The ticket info was saved, but we couldn't update the registration questions. Try editing again in a moment.`);
                }
            }
        } finally {
            setSubmitting(false);
        }
    };

    return (
        <Modal
            opened={opened}
            onClose={onClose}
            title={t`Edit Attendee Details`}
            size={profileEntry ? "lg" : "md"}
            className={classes.modal}
        >
            <form onSubmit={form.onSubmit(handleSubmit)}>
                <div>
                    <InputGroup>
                        <TextInput
                            label={t`First Name`}
                            placeholder={t`Enter first name`}
                            required
                            {...form.getInputProps('first_name')}
                        />

                        <TextInput
                            label={t`Last Name`}
                            placeholder={t`Enter last name`}
                            required
                            {...form.getInputProps('last_name')}
                        />
                    </InputGroup>

                    <TextInput
                        label={t`Email`}
                        placeholder={t`Enter email`}
                        required
                        type="email"
                        {...form.getInputProps('email')}
                    />

                    <Switch
                        mt="md"
                        label={t`Confirm details at check-in`}
                        description={t`When on, check-in staff are prompted to confirm this attendee's details at the door.`}
                        {...form.getInputProps('confirm_at_checkin', {type: 'checkbox'})}
                    />

                    {profileEntry && attributeDefinitions.length > 0 && (
                        <>
                            <Divider my="md" label={t`Registration questions`} labelPosition="left"/>
                            <SimpleGrid cols={{base: 1, sm: 2}} spacing="sm">
                                {attributeDefinitions.map((def) => {
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
                        </>
                    )}

                    <Group justify="flex-end" gap="sm" mt="md">
                        <Button variant="default" onClick={onClose}>
                            {t`Cancel`}
                        </Button>
                        <Button type="submit" loading={submitting}>
                            {t`Save`}
                        </Button>
                    </Group>
                </div>
            </form>
        </Modal>
    );
};
