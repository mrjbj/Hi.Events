import {t} from "@lingui/macro";
import {Button, Divider, Group, Loader, Modal, TextInput} from "@mantine/core";
import {useForm} from "@mantine/form";
import {useQuery} from "@tanstack/react-query";
import {useEffect} from "react";
import {Attendee} from "../../../types.ts";
import {contactPortalClientPublic, MyContactResult} from "../../../api/contact-portal.client.ts";
import {AttendeeProfileCard} from "../../routes/product-widget/OrderSummaryAndProducts/AttendeeProfiles";
import {usePatchCheckInListAttendee} from "../../../mutations/usePatchCheckInListAttendee.ts";
import {showError, showSuccess} from "../../../utilites/notifications.tsx";
import {useFormErrorResponseHandler} from "../../../hooks/useFormErrorResponseHandler.tsx";

interface AttendeeProfileModalProps {
    opened: boolean;
    attendee: Attendee | null;
    eventId: number | undefined;
    checkInListShortId: string | undefined;
    onClose: () => void;
}

/**
 * Check-in app's "edit attendee details" surface. Two save paths:
 *  - Name + registration questions go through the contact portal
 *    (AttendeeProfileCard → PATCH /contacts/me) and update the contact record.
 *  - Email goes through the check-in-list-scoped PATCH endpoint
 *    (PATCH /check-in-lists/{shortId}/attendees/{publicId}) and updates
 *    the attendee row directly. The contact portal doesn't touch
 *    attendees.email, so this split is intentional.
 *
 * Each section has its own Save button to keep the trust boundary visible.
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
    const formErrorHandler = useFormErrorResponseHandler();

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
        if (trimmed === (attendee.email ?? '').trim()) {
            showError(t`Email is unchanged`);
            return;
        }

        emailMutation.mutate(
            {attendeePublicId: attendee.public_id, payload: {email: trimmed}},
            {
                onSuccess: () => {
                    showSuccess(t`Email updated`);
                },
                onError: (error) => formErrorHandler(emailForm, error),
            },
        );
    });

    return (
        <Modal
            opened={opened}
            onClose={onClose}
            title={t`Edit attendee details`}
            size="lg"
        >
            {!contactToken && (
                <div style={{padding: '1rem', color: '#666'}}>
                    {t`This attendee isn't linked to a contact yet, so profile editing isn't available here.`}
                </div>
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
