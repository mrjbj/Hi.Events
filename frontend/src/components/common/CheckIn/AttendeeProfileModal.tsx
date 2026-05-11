import {t} from "@lingui/macro";
import {Loader, Modal} from "@mantine/core";
import {useQuery} from "@tanstack/react-query";
import {Attendee} from "../../../types.ts";
import {contactPortalClientPublic, MyContactResult} from "../../../api/contact-portal.client.ts";
import {AttendeeProfileCard} from "../../routes/product-widget/OrderSummaryAndProducts/AttendeeProfiles";

interface AttendeeProfileModalProps {
    opened: boolean;
    attendee: Attendee | null;
    eventId: number | undefined;
    onClose: () => void;
}

/**
 * Check-in app's "edit attendee details" surface. Reuses the same profile card
 * the buyer/attendee sees on the public ticket page — so door staff can fix a
 * guest's name and registration-question answers via the contact portal. Email
 * edits remain out of this flow (the contact portal doesn't touch attendee.email).
 */
export const AttendeeProfileModal = ({
                                         opened,
                                         attendee,
                                         eventId,
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
        </Modal>
    );
};
