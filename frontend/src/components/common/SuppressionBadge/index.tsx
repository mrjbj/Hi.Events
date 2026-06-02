import {Badge, Tooltip} from "@mantine/core";
import {t} from "@lingui/macro";
import {SuppressionStatus} from "../../../types.ts";

interface SuppressionBadgeProps {
    status?: SuppressionStatus;
    detail?: string | null;
}

/**
 * Deliverability badge for a contact's email address, framed as what the address
 * WILL receive (not what's suppressed):
 *   active         -> "Always"        (all email sends)
 *   marketing_only -> "Transactional" (only receipts/order mail; marketing off)
 *   always         -> "Never"         (nothing sends)
 * The bucket mirrors EmailSuppressionService send behavior; `detail` carries the
 * raw reason(s) (e.g. "bounce:Transient, complaint") for the tooltip.
 */
export const SuppressionBadge = ({status, detail}: SuppressionBadgeProps) => {
    if (!status || status === 'active') {
        return (
            <Tooltip label={t`Receives all email — marketing and transactional`} withArrow>
                <Badge color="green" variant="light">{t`Always`}</Badge>
            </Tooltip>
        );
    }

    if (status === 'marketing_only') {
        const tip = detail
            ? t`Only transactional email sends (receipts, order updates). Marketing is suppressed (${detail}).`
            : t`Only transactional email sends (receipts, order updates). Marketing is suppressed.`;
        return (
            <Tooltip label={tip} withArrow multiline w={260}>
                <Badge color="yellow" variant="light">{t`Transactional`}</Badge>
            </Tooltip>
        );
    }

    const tip = detail
        ? t`No email sends — marketing or transactional (${detail}).`
        : t`No email sends — marketing or transactional.`;
    return (
        <Tooltip label={tip} withArrow multiline w={260}>
            <Badge color="red" variant="light">{t`Never`}</Badge>
        </Tooltip>
    );
};
