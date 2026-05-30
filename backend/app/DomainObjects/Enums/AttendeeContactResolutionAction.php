<?php

namespace HiEvents\DomainObjects\Enums;

/**
 * Outcome of reconciling an attendee's contact link after its email was edited.
 * See {@see \HiEvents\Services\Domain\Contact\AttendeeContactLinkResolver}.
 */
enum AttendeeContactResolutionAction: string
{
    use BaseEnum;

    // Email still matches the linked contact (or no email) — nothing to do.
    case UNCHANGED = 'UNCHANGED';

    // A previously contactless attendee was linked to a found/created contact.
    case LINKED = 'LINKED';

    // The attendee was re-pointed to its own contact (found or created) for the
    // new email, leaving the previously-shared contact and its siblings intact.
    case SPLIT = 'SPLIT';

    // The divergence was left in place and queued for human review in the
    // Sync → "Email Changes" tab (door edits to a sole-owner contact).
    case FLAGGED = 'FLAGGED';

    // The (shared) contact's own email was renamed in place — e.g. a table
    // sponsor confirmed the new address is theirs. No cascade to siblings.
    case CONTACT_RENAMED = 'CONTACT_RENAMED';
}
