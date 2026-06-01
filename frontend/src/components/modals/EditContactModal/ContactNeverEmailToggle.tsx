import {Switch} from "@mantine/core";
import {t} from "@lingui/macro";
import {useGetEmailSuppressions} from "../../../queries/useGetEmailSuppressions.ts";
import {useCreateEmailSuppression} from "../../../mutations/useCreateEmailSuppression.ts";
import {useDeleteEmailSuppression} from "../../../mutations/useDeleteEmailSuppression.ts";
import {showError, showSuccess} from "../../../utilites/notifications.tsx";

interface ContactNeverEmailToggleProps {
    email: string;
}

/**
 * "Never email this address" — reuses the superadmin email-suppression endpoints
 * to add/remove a do_not_contact rule for the contact's address. Only rendered
 * for superadmins (the admin endpoints are superadmin-gated).
 */
export const ContactNeverEmailToggle = ({email}: ContactNeverEmailToggleProps) => {
    const {data} = useGetEmailSuppressions({search: email, reason: 'do_not_contact', per_page: 20});
    const createMutation = useCreateEmailSuppression();
    const deleteMutation = useDeleteEmailSuppression();

    const existing = data?.data?.find(
        (s) => s.email?.toLowerCase() === email.toLowerCase() && s.reason === 'do_not_contact',
    );
    const suppressed = !!existing;
    const pending = createMutation.isPending || deleteMutation.isPending;

    const toggle = (checked: boolean) => {
        if (checked) {
            createMutation.mutate({email, reason: 'do_not_contact'}, {
                onSuccess: () => showSuccess(t`This address will no longer receive any email`),
                onError: () => showError(t`Couldn't update the do-not-contact setting`),
            });
        } else if (existing) {
            deleteMutation.mutate({id: existing.id}, {
                onSuccess: () => showSuccess(t`This address can receive email again`),
                onError: () => showError(t`Couldn't update the do-not-contact setting`),
            });
        }
    };

    return (
        <Switch
            mb="sm"
            label={t`Never email this address`}
            description={t`Suppresses all marketing and transactional email to this address, platform-wide. Use for placeholder or do-not-contact addresses.`}
            checked={suppressed}
            disabled={pending}
            onChange={(e) => toggle(e.currentTarget.checked)}
        />
    );
};
