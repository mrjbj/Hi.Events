import {Event} from "../../../types.ts";
import classes from "./OrganizerBrandHeader.module.scss";

interface OrganizerBrandHeaderProps {
    event: Event | undefined;
}

/**
 * Renders the organizer's brand mark at the top of public buyer-facing pages
 * (Your Order, Your Ticket). Falls back to the event's TICKET_LOGO when no
 * ORGANIZER_LOGO is configured; renders nothing when neither is set.
 *
 * Non-interactive by design — the buyer is mid-flow on an order/ticket page
 * and shouldn't be navigated away accidentally.
 */
export const OrganizerBrandHeader = ({event}: OrganizerBrandHeaderProps) => {
    if (!event) return null;

    const organizerLogo = event.organizer?.images?.find((img) => img.type === 'ORGANIZER_LOGO');
    const ticketLogo = event.images?.find((img) => img.type === 'TICKET_LOGO');
    const logo = organizerLogo ?? ticketLogo;

    if (!logo?.url) return null;

    return (
        <div className={classes.header}>
            <img
                className={classes.logo}
                src={logo.url}
                alt={event.organizer?.name ?? event.title ?? ''}
            />
        </div>
    );
};
