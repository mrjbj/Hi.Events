import {useGetEventPublic} from "../../../../queries/useGetEventPublic.ts";
import {useParams} from "react-router";
import {useGetAttendeePublic} from "../../../../queries/useGetAttendeePublic.ts";
import {AttendeeTicket} from "../../../common/AttendeeTicket";
import {Attendee, Product} from "../../../../types.ts";
import {Alert, Collapse, Container, Group, UnstyledButton} from "@mantine/core";
import {IconChevronDown, IconChevronUp, IconInfoCircle, IconUser} from "@tabler/icons-react";
import {useQuery} from "@tanstack/react-query";
import {useState} from "react";
import {t} from "@lingui/macro";
import {PoweredByFooter} from "../../../common/PoweredByFooter";
import {OnlineEventDetails} from "../../../common/OnlineEventDetails";
import {HomepageInfoMessage} from "../../../common/HomepageInfoMessage";
import {AttendeeProfileCard} from "../OrderSummaryAndProducts/AttendeeProfiles";
import {contactPortalClientPublic, MyContactResult} from "../../../../api/contact-portal.client.ts";
import classes from './AttendeeProductAndInformation.module.scss';

export const AttendeeProductAndInformation = () => {
    const {eventId, attendeeShortId} = useParams();
    const {data: event, isError: eventError} = useGetEventPublic(eventId);
    const {data: attendee, isError: attendeeError} = useGetAttendeePublic(eventId, String(attendeeShortId));
    const [profileOpen, setProfileOpen] = useState(true);

    const contactToken = attendee?.contact_token ?? null;
    const contactId = attendee?.contact_id ?? null;
    const profileQuery = useQuery<MyContactResult>({
        queryKey: ['attendee-profile', contactId, Number(eventId)],
        queryFn: () => contactPortalClientPublic.getMyContact(contactToken!, Number(eventId)),
        enabled: !!contactToken && typeof contactId === 'number' && !!eventId,
        staleTime: 60_000,
        retry: false,
    });

    if (eventError || attendeeError) {
        return (
            <HomepageInfoMessage
                status="not_found"
                message={t`Ticket Not Found`}
                subtitle={t`We couldn't find the ticket you're looking for. The link may have expired or the ticket details may have changed.`}
            />
        );
    }

    if (!event || !attendee) {
        return null;
    }

    /**
     * (c) Hi.Events Ltd 2025
     *
     * PLEASE NOTE:
     *
     * Hi.Events is licensed under the GNU Affero General Public License (AGPL) version 3.
     *
     * You can find the full license text at: https://github.com/HiEventsDev/hi.events/blob/main/LICENCE
     *
     * In accordance with Section 7(b) of the AGPL, we ask that you retain the "Powered by Hi.Events" notice.
     *
     * If you wish to remove this notice, a commercial license is available at: https://hi.events/licensing
     */
    const showWelcomeBanner = Boolean(attendee.profile_completion_recommended) && Boolean(contactToken);

    return (
        <Container>
            <h2 className={classes.title}>{t`Your ticket for`} {event.title}</h2>

            {showWelcomeBanner && (
                <Alert
                    color="blue"
                    icon={<IconInfoCircle size={18}/>}
                    mb="md"
                >
                    {t`Welcome! Please confirm your details below before the event so we know who's attending.`}
                </Alert>
            )}

            <AttendeeTicket
                attendee={attendee as Attendee}
                product={attendee.product as Product}
                event={event}
                showPoweredBy
            />

            {(event?.settings?.is_online_event && <OnlineEventDetails eventSettings={event.settings}/>)}

            {contactToken && typeof contactId === 'number' && (
                <div className={classes.profileSection}>
                    <Group justify="flex-end" mb="xs">
                        <UnstyledButton
                            className={classes.profileToggle}
                            onClick={() => setProfileOpen((v) => !v)}
                            aria-expanded={profileOpen}
                        >
                            <IconUser size={14}/>
                            <span>{t`Do we have this right?`}</span>
                            {profileOpen ? <IconChevronUp size={14}/> : <IconChevronDown size={14}/>}
                        </UnstyledButton>
                    </Group>
                    <Collapse in={profileOpen}>
                        <div className={classes.profilePanel}>
                            <AttendeeProfileCard
                                token={contactToken}
                                data={profileQuery.data}
                                contactId={contactId}
                                eventId={Number(eventId)}
                            />
                        </div>
                    </Collapse>
                </div>
            )}

            <PoweredByFooter/>
        </Container>
    )
}

export default AttendeeProductAndInformation;
