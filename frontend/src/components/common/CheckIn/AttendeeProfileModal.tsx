import {t} from "@lingui/macro";
import {Button, Divider, Group, Loader, Modal, Stack, Switch, Text, TextInput} from "@mantine/core";
import {useForm} from "@mantine/form";
import {useQuery} from "@tanstack/react-query";
import {useEffect, useRef, useState} from "react";
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

const namesMatch = (a?: string | null, b?: string | null, c?: string | null, d?: string | null): boolean => {
    const attendeeName = `${(a ?? '').trim()} ${(b ?? '').trim()}`.trim().toLowerCase();
    const buyerName = `${(c ?? '').trim()} ${(d ?? '').trim()}`.trim().toLowerCase();
    return attendeeName !== '' && attendeeName === buyerName;
};

/**
 * The email to seed the field/baseline with. A contactless bundle seat still
 * carries the buyer's email as an inherited placeholder; we treat that as "no
 * email captured yet" (blank) so the door prompts for the attendee's OWN
 * address — the only thing that separates them from the sponsor's contact
 * (contacts are keyed by email). Linked attendees and seats with their own
 * distinct email keep what's on file.
 */
const startingEmail = (attendee?: Attendee | null): string => {
    const email = (attendee?.email ?? '').trim();
    if (!email) return '';
    const isContactless = !attendee?.contact_id;
    const isBuyerPlaceholder = !!attendee?.buyer_email
        && email.toLowerCase() === (attendee.buyer_email ?? '').trim().toLowerCase();
    return isContactless && isBuyerPlaceholder ? '' : email;
};

/**
 * Check-in app's "edit attendee details" surface. A single "Save changes" button
 * commits everything at once. It works for every attendee, linked or not:
 *
 *  - Name + registration questions go to the contact portal (PATCH /contacts/me)
 *    via the embedded profile card, which only renders once a contact exists.
 *  - The confirm-at-check-in flag and the email go through the check-in-list PATCH
 *    (PATCH /check-in-lists/{shortId}/attendees/{publicId}).
 *
 * Two extra flows:
 *  - Contactless attendees have no contact yet, so the attribute card can't render.
 *    Saving an email links/creates their contact server-side; the PATCH response
 *    carries a fresh contact token, so we reveal the attribute card in the SAME
 *    modal for an optional second save (capturing registration answers).
 *  - When the edited attendee shares a bundle contact with the order buyer (a table
 *    sponsor) and their name matches the buyer's, an email change is ambiguous, so
 *    we ask whether it's the sponsor's new address (rename the contact) or a
 *    different person (split onto their own contact), passing the choice as
 *    contact_resolution.
 */
export const AttendeeProfileModal = ({
                                         opened,
                                         attendee,
                                         eventId,
                                         checkInListShortId,
                                         onClose,
                                     }: AttendeeProfileModalProps) => {
    // A contactless attendee gets a contact (and token) the moment an email is
    // saved; we hold the freshly minted link locally to reveal the attribute card.
    const [linkedToken, setLinkedToken] = useState<string | null>(null);
    const [linkedContactId, setLinkedContactId] = useState<number | null>(null);

    const contactToken = linkedToken ?? attendee?.contact_token ?? null;
    const contactId = linkedContactId ?? attendee?.contact_id ?? null;

    const profileQuery = useQuery<MyContactResult>({
        queryKey: ['attendee-profile', contactId, eventId],
        queryFn: () => contactPortalClientPublic.getMyContact(contactToken!, eventId!),
        enabled: opened && !!contactToken && typeof contactId === 'number' && !!eventId,
        staleTime: 60_000,
        retry: false,
    });

    const emailMutation = usePatchCheckInListAttendee({checkInListShortId: checkInListShortId ?? ''});
    const confirmMutation = usePatchCheckInListAttendee({checkInListShortId: checkInListShortId ?? ''});
    const formErrorHandler = useFormErrorResponseHandler();

    const profileSaveRef = useRef<(() => Promise<void>) | null>(null);
    const [savingAll, setSavingAll] = useState(false);
    const [sponsorPromptOpen, setSponsorPromptOpen] = useState(false);

    const [confirmAtCheckin, setConfirmAtCheckin] = useState<boolean>(!!attendee?.confirm_at_checkin);
    // Baseline for "did the email change" — advanced after a successful save so a
    // second (attribute) save doesn't re-submit the email.
    const [savedEmail, setSavedEmail] = useState('');

    useEffect(() => {
        setConfirmAtCheckin(!!attendee?.confirm_at_checkin);
        setLinkedToken(null);
        setLinkedContactId(null);
        setSavedEmail(startingEmail(attendee));
        setSponsorPromptOpen(false);
    }, [attendee?.public_id, attendee?.confirm_at_checkin]);

    const confirmDirty = !!attendee && confirmAtCheckin !== !!attendee.confirm_at_checkin;

    const persistConfirmIfChanged = async () => {
        if (!attendee || !checkInListShortId || !confirmDirty) return;
        await confirmMutation.mutateAsync({
            attendeePublicId: attendee.public_id,
            payload: {confirm_at_checkin: confirmAtCheckin},
        });
    };

    // The profile card writes name + registration questions to the CONTACT, but
    // the check-in list row renders attendee.first_name/last_name. Mirror the
    // saved name onto the attendee row (folding in the confirm flag) so the
    // listing reflects the edit. Runs as the card's additionalSaveAsync, after
    // the contact update lands.
    const persistAttendeeRowOnProfileSave = async (saved: {firstName: string; lastName: string}) => {
        if (!attendee || !checkInListShortId) return;
        const payload: {first_name?: string; last_name?: string; confirm_at_checkin?: boolean} = {};
        const newFirst = saved.firstName.trim();
        const newLast = saved.lastName.trim();
        if (newFirst && newFirst !== (attendee.first_name ?? '').trim()) payload.first_name = newFirst;
        if (newLast && newLast !== (attendee.last_name ?? '').trim()) payload.last_name = newLast;
        if (confirmDirty) payload.confirm_at_checkin = confirmAtCheckin;
        if (Object.keys(payload).length === 0) return;
        await confirmMutation.mutateAsync({attendeePublicId: attendee.public_id, payload});
    };

    const emailForm = useForm({
        initialValues: {email: ''},
        validate: {
            email: (value) => {
                if (!value) return null;
                return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value) ? null : t`Invalid email`;
            },
        },
    });

    useEffect(() => {
        if (attendee) {
            emailForm.setValues({email: startingEmail(attendee)});
            emailForm.resetDirty();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [attendee?.public_id]);

    const commit = async (resolution?: 'same_person' | 'different_person') => {
        if (!attendee || !checkInListShortId) return;

        const trimmed = emailForm.values.email.trim();
        const oldEmail = savedEmail;
        const emailChanged = trimmed !== oldEmail;
        const wasContactless = !contactToken;

        setSavingAll(true);
        try {
            // Name + registration questions + confirm flag. With an editable
            // contact the card's silent saver covers all three; otherwise the
            // confirm flag is the only profile-side change to persist.
            if (profileSaveRef.current) {
                await profileSaveRef.current();
            } else {
                await persistConfirmIfChanged();
            }
        } catch {
            setSavingAll(false);
            showError(t`Couldn't save changes`);
            return;
        }

        if (emailChanged) {
            try {
                const res = await emailMutation.mutateAsync({
                    attendeePublicId: attendee.public_id,
                    payload: {
                        email: trimmed,
                        notify_email_change: !!oldEmail,
                        ...(resolution ? {contact_resolution: resolution} : {}),
                    },
                });
                setSavedEmail(trimmed);

                // Contactless → just linked. Reveal the attribute card for an
                // optional second save instead of closing.
                const newToken = res?.data?.contact_token ?? null;
                const newContactId = res?.data?.contact_id ?? null;
                if (wasContactless && newToken && typeof newContactId === 'number') {
                    setLinkedToken(newToken);
                    setLinkedContactId(newContactId);
                    setSavingAll(false);
                    showSuccess(t`Saved. You can now add this attendee's details below.`);
                    return;
                }
            } catch (error) {
                setSavingAll(false);
                formErrorHandler(emailForm, error);
                return;
            }
        }

        setSavingAll(false);
        showSuccess(emailChanged && oldEmail
            ? t`Changes saved. A notification was sent to ${oldEmail}.`
            : t`Changes saved`);
        onClose();
    };

    const handleSaveAll = emailForm.onSubmit(() => {
        if (!attendee || !checkInListShortId) return;

        const trimmed = emailForm.values.email.trim();
        const oldEmail = savedEmail;
        const emailChanged = trimmed !== oldEmail;

        const looksLikeSponsor = emailChanged
            && !!oldEmail
            && !!attendee.from_group_purchase
            && namesMatch(attendee.first_name, attendee.last_name, attendee.buyer_first_name, attendee.buyer_last_name);

        if (looksLikeSponsor) {
            setSponsorPromptOpen(true);
            return;
        }

        if (emailChanged && oldEmail) {
            confirmationDialog(
                t`The current address (${oldEmail}) will be emailed to let them know the ticket's email address has changed. Continue?`,
                () => { void commit(); },
                {confirm: t`Yes, save and notify`, cancel: t`Cancel`},
            );
            return;
        }

        void commit();
    });

    const buyerName = `${(attendee?.buyer_first_name ?? '').trim()} ${(attendee?.buyer_last_name ?? '').trim()}`.trim();

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
                <Text size="sm" c="dimmed" mb="md">
                    {t`This attendee isn't on file as a contact yet. Add an email below and save to create their contact — then you can fill in their registration details.`}
                </Text>
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
                    additionalSaveAsync={persistAttendeeRowOnProfileSave}
                    onSaved={onClose}
                    saveRef={profileSaveRef}
                    showSaveButton={false}
                />
            )}

            {checkInListShortId && attendee && (
                <form onSubmit={handleSaveAll}>
                    <Divider my="md" label={t`Email address`} labelPosition="left"/>
                    <TextInput
                        label={t`Email`}
                        placeholder={t`name@example.com`}
                        type="email"
                        description={t`Updates the ticket recipient address. Doesn't resend the ticket automatically.`}
                        {...emailForm.getInputProps('email')}
                    />
                    <Group justify="flex-end" mt="lg">
                        <Button type="submit" loading={savingAll}>
                            {t`Save changes`}
                        </Button>
                    </Group>
                </form>
            )}

            <Modal
                opened={sponsorPromptOpen}
                onClose={() => setSponsorPromptOpen(false)}
                title={t`Whose email is this?`}
                size="md"
                zIndex={1100}
            >
                <Stack gap="md">
                    <Text size="sm">
                        {buyerName
                            ? t`${buyerName} placed this order and these tickets share their email. Is this a new email address for them, or a different person taking this seat?`
                            : t`These tickets share the buyer's email. Is this a new email address for the buyer, or a different person taking this seat?`}
                    </Text>
                    <Stack gap="xs">
                        <Button
                            variant="light"
                            onClick={() => { setSponsorPromptOpen(false); void commit('same_person'); }}
                        >
                            {t`Same person — new email address`}
                        </Button>
                        <Button
                            variant="light"
                            color="grape"
                            onClick={() => { setSponsorPromptOpen(false); void commit('different_person'); }}
                        >
                            {t`A different person taking this seat`}
                        </Button>
                        <Button variant="subtle" color="gray" onClick={() => setSponsorPromptOpen(false)}>
                            {t`Cancel`}
                        </Button>
                    </Stack>
                </Stack>
            </Modal>
        </Modal>
    );
};
