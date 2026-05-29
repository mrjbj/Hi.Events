import {t} from "@lingui/macro";
import {Button, Divider, Group, Loader, Modal, Switch, TextInput} from "@mantine/core";
import {useForm} from "@mantine/form";
import {useQuery} from "@tanstack/react-query";
import {useEffect, useState} from "react";
import {Attendee} from "../../../types.ts";
import {contactPortalClientPublic, MyContactResult} from "../../../api/contact-portal.client.ts";
import {AttendeeProfileCard} from "../../routes/product-widget/OrderSummaryAndProducts/AttendeeProfiles";
import {usePatchCheckInListAttendee} from "../../../mutations/usePatchCheckInListAttendee.ts";
import {showError, showSuccess} from "../../../utilites/notifications.tsx";
import {useFormErrorResponseHandler} from "../../../hooks/useFormErrorResponseHandler.tsx";
import {confirmationDialog} from "../../../utilites/confirmationDialog.tsx";

interface AttendeeProfileModalProps {
    opened: boolean;
    attendee: Attendee | null;
    eventId: number | undefined;
    checkInListShortId: string | undefined;
    onClose: () => void;
}

/**
 * Check-in app's "edit attendee details" surface. Two save paths:
 *  - Name + registration questions + the confirm-at-check-in flag are committed
 *    together by "Save changes": the contact portal (PATCH /contacts/me) updates
 *    the contact record, and — when the flag was toggled — a check-in-list PATCH
 *    persists confirm_at_checkin on the attendee row. The switch is deferred (it
 *    does NOT write on toggle); it only persists on "Save changes". Saving closes
 *    the modal.
 *  - Email goes through the check-in-list-scoped PATCH endpoint
 *    (PATCH /check-in-lists/{shortId}/attendees/{publicId}). "Save email" asks
 *    for confirmation (the previous address is emailed a "details changed"
 *    notice), then saves, surfaces a toast, and closes the modal.
 */
export const AttendeeProfileModal = ({
                                         opened,
                                         attendee,
                                         eventId,
                                         checkInListShortId,
                                         onClose,
                                     }: AttendeeProfileModalProps) => {
    const contactToken = attendee?.contact_token ?? null;
    const contactId = attendee?.contact_id ?? null;

    const profileQuery = useQuery<MyContactResult>({
        queryKey: ['attendee-profile', contactId, eventId],
        queryFn: () => contactPortalClientPublic.getMyContact(contactToken!, eventId!),
        enabled: opened && !!contactToken && typeof contactId === 'number' && !!eventId,
        staleTime: 60_000,
        retry: false,
    });

    const emailMutation = usePatchCheckInListAttendee({
        checkInListShortId: checkInListShortId ?? '',
    });
    const confirmMutation = usePatchCheckInListAttendee({
        checkInListShortId: checkInListShortId ?? '',
    });
    const formErrorHandler = useFormErrorResponseHandler();

    const [confirmAtCheckin, setConfirmAtCheckin] = useState<boolean>(!!attendee?.confirm_at_checkin);

    useEffect(() => {
        setConfirmAtCheckin(!!attendee?.confirm_at_checkin);
    }, [attendee?.public_id, attendee?.confirm_at_checkin]);

    // The flag is deferred: toggling only updates local state. It persists when
    // "Save changes" is clicked (alongside the contact update), or via the
    // standalone Save button shown when there's no editable contact profile.
    const confirmDirty = !!attendee && confirmAtCheckin !== !!attendee.confirm_at_checkin;

    const persistConfirmIfChanged = async () => {
        if (!attendee || !checkInListShortId || !confirmDirty) return;
        await confirmMutation.mutateAsync({
            attendeePublicId: attendee.public_id,
            payload: {confirm_at_checkin: confirmAtCheckin},
        });
    };

    const handleConfirmOnlySave = async () => {
        if (!attendee || !checkInListShortId) return;
        try {
            await persistConfirmIfChanged();
            showSuccess(t`Changes saved`);
            onClose();
        } catch {
            showError(t`Couldn't save changes`);
        }
    };

    const emailForm = useForm({
        initialValues: {email: ''},
        validate: {
            email: (value) => {
                if (!value) return t`Email is required`;
                return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value) ? null : t`Invalid email`;
            },
        },
    });

    useEffect(() => {
        if (attendee) {
            emailForm.setValues({email: attendee.email ?? ''});
            emailForm.resetDirty();
        }
    }, [attendee?.public_id]);

    const handleEmailSave = emailForm.onSubmit((values) => {
        if (!attendee || !checkInListShortId) return;

        const trimmed = values.email.trim();
        const oldEmail = (attendee.email ?? '').trim();
        if (trimmed === oldEmail) {
            showError(t`Email is unchanged`);
            return;
        }

        const saveEmail = () => emailMutation.mutate(
            {attendeePublicId: attendee.public_id, payload: {email: trimmed, notify_email_change: !!oldEmail}},
            {
                onSuccess: () => {
                    showSuccess(oldEmail
                        ? t`Email updated. A notification was sent to ${oldEmail}.`
                        : t`Email updated`);
                    onClose();
                },
                onError: (error) => formErrorHandler(emailForm, error),
            },
        );

        if (oldEmail) {
            confirmationDialog(
                t`The current address (${oldEmail}) will be emailed to let them know the ticket's email address has changed. Continue?`,
                saveEmail,
                {confirm: t`Yes, save and notify`, cancel: t`Cancel`},
            );
        } else {
            saveEmail();
        }
    });

    return (
        <Modal
            opened={opened}
            onClose={onClose}
            title={t`Edit attendee details`}
            size="lg"
        >
            {attendee && checkInListShortId && (
                <Switch
                    mb="md"
                    checked={confirmAtCheckin}
                    disabled={confirmMutation.isPending}
                    onChange={(event) => setConfirmAtCheckin(event.currentTarget.checked)}
                    label={t`Confirm details at check-in`}
                    description={t`When on, this attendee is flagged for check-in staff to confirm their details. Turn it off once their details are confirmed — saved when you click "Save changes".`}
                />
            )}

            {!contactToken && (
                <div style={{padding: '1rem', color: '#666'}}>
                    {t`This attendee isn't linked to a contact yet, so profile editing isn't available here.`}
                </div>
            )}

            {!contactToken && attendee && checkInListShortId && (
                <Group justify="flex-end" mb="md">
                    <Button
                        loading={confirmMutation.isPending}
                        disabled={!confirmDirty}
                        onClick={handleConfirmOnlySave}
                    >
                        {t`Save changes`}
                    </Button>
                </Group>
            )}

            {contactToken && profileQuery.isLoading && (
                <div style={{display: 'flex', justifyContent: 'center', padding: '2rem'}}>
                    <Loader size="md"/>
                </div>
            )}

            {contactToken && !profileQuery.isLoading && typeof contactId === 'number' && (
                <AttendeeProfileCard
                    token={contactToken}
                    data={profileQuery.data}
                    contactId={contactId}
                    eventId={eventId}
                    additionalSaveAsync={persistConfirmIfChanged}
                    onSaved={onClose}
                />
            )}

            {checkInListShortId && attendee && (
                <>
                    <Divider my="md" label={t`Email address`} labelPosition="left"/>
                    <form onSubmit={handleEmailSave}>
                        <TextInput
                            label={t`Email`}
                            placeholder={t`name@example.com`}
                            type="email"
                            description={t`Updates the ticket recipient address. Doesn't resend the ticket automatically.`}
                            {...emailForm.getInputProps('email')}
                        />
                        <Group justify="flex-end" mt="sm">
                            <Button type="submit" loading={emailMutation.isPending}>
                                {t`Save email`}
                            </Button>
                        </Group>
                    </form>
                </>
            )}
        </Modal>
    );
};
