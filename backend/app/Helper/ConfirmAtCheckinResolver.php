<?php

namespace HiEvents\Helper;

class ConfirmAtCheckinResolver
{
    /**
     * Resolve the confirm_at_checkin flag for an attendee.
     *
     * When an explicit value is supplied (admin toggled the control) it wins.
     * Otherwise we default to true for placeholder/guest rows — a blank first
     * or last name, or a first name containing "guest" — so check-in staff are
     * prompted to capture real details at the door.
     */
    public static function resolve(?bool $explicit, ?string $firstName, ?string $lastName): bool
    {
        if ($explicit !== null) {
            return $explicit;
        }

        $first = trim((string) $firstName);
        $last = trim((string) $lastName);

        if ($first === '' || $last === '') {
            return true;
        }

        return str_contains(strtolower($first), 'guest');
    }
}
