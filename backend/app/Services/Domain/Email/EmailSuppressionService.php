<?php

namespace HiEvents\Services\Domain\Email;

use HiEvents\DomainObjects\EmailSuppressionDomainObject;
use HiEvents\DomainObjects\Generated\EmailSuppressionDomainObjectAbstract;
use HiEvents\DomainObjects\Status\EmailSuppressionReasonEnum;
use HiEvents\Repository\Interfaces\EmailSuppressionRepositoryInterface;

class EmailSuppressionService
{
    public function __construct(
        private readonly EmailSuppressionRepositoryInterface $emailSuppressionRepository,
    ) {}

    /**
     * Check if an email is suppressed for a given email type.
     *
     * Always-on (independent of the SES feature flag):
     * - Placeholder/junk addresses (config('mail.suppressed_address_patterns')) — never email
     * - An explicit DO_NOT_CONTACT suppression — suppresses ALL email types
     *
     * SES-derived (gated behind services.ses.suppression_enabled):
     * - Permanent bounces suppress ALL email types (address doesn't exist)
     * - Complaints suppress only marketing emails (customer may still need transactional)
     * - Transient bounces suppress only marketing emails (may succeed on retry for transactional)
     */
    public function isEmailSuppressed(string $email, ?int $accountId, string $emailType = 'marketing'): bool
    {
        $email = strtolower($email);

        if ($this->isPlaceholderAddress($email)) {
            return true;
        }

        $suppressions = $this->emailSuppressionRepository->findByEmail($email, $accountId);

        foreach ($suppressions as $suppression) {
            /** @var EmailSuppressionDomainObject $suppression */
            if ($suppression->getReason() === EmailSuppressionReasonEnum::DO_NOT_CONTACT->value) {
                return true;
            }
        }

        if (! config('services.ses.suppression_enabled', false)) {
            return false;
        }

        if ($suppressions->isEmpty()) {
            return false;
        }

        foreach ($suppressions as $suppression) {
            /** @var EmailSuppressionDomainObject $suppression */
            if ($suppression->getReason() === EmailSuppressionReasonEnum::BOUNCE->value) {
                if ($suppression->getBounceType() === 'Permanent') {
                    // Permanent bounce: suppress for ALL email types
                    return true;
                }

                if ($emailType === 'marketing') {
                    // Transient/Undetermined bounce: suppress only marketing
                    return true;
                }
            }

            if ($suppression->getReason() === EmailSuppressionReasonEnum::COMPLAINT->value) {
                if ($emailType === 'marketing') {
                    // Complaint: suppress only marketing
                    return true;
                }
                // Transactional emails to complaint addresses still send
            }
        }

        return false;
    }

    /**
     * Placeholder / junk addresses (e.g. admin-entered unknown@unknown.com on
     * bulk-imported orders) are never deliverable, so they're suppressed by
     * default without needing a DB row. Patterns are configurable and matched
     * with fnmatch (case-insensitive).
     */
    public function isPlaceholderAddress(string $email): bool
    {
        $email = strtolower(trim($email));

        if ($email === '') {
            return false;
        }

        foreach ((array) config('mail.suppressed_address_patterns', []) as $pattern) {
            if (fnmatch(strtolower((string) $pattern), $email)) {
                return true;
            }
        }

        return false;
    }

    public function suppressEmail(
        string $email,
        string $reason,
        string $source,
        ?int $accountId = null,
        ?string $bounceType = null,
        ?string $bounceSubType = null,
        ?string $complaintType = null,
        ?string $snsMessageId = null,
        mixed $rawPayload = null,
    ): EmailSuppressionDomainObject {
        return $this->emailSuppressionRepository->findOrCreateSuppression(
            uniqueAttributes: [
                EmailSuppressionDomainObjectAbstract::EMAIL => strtolower($email),
                EmailSuppressionDomainObjectAbstract::ACCOUNT_ID => $accountId,
                EmailSuppressionDomainObjectAbstract::REASON => $reason,
            ],
            additionalValues: [
                EmailSuppressionDomainObjectAbstract::BOUNCE_TYPE => $bounceType,
                EmailSuppressionDomainObjectAbstract::BOUNCE_SUB_TYPE => $bounceSubType,
                EmailSuppressionDomainObjectAbstract::COMPLAINT_TYPE => $complaintType,
                EmailSuppressionDomainObjectAbstract::SOURCE => $source,
                EmailSuppressionDomainObjectAbstract::SNS_MESSAGE_ID => $snsMessageId,
                EmailSuppressionDomainObjectAbstract::RAW_PAYLOAD => $rawPayload ? json_encode($rawPayload) : null,
            ],
        );
    }

    public function removeSuppressionById(int $id): void
    {
        $this->emailSuppressionRepository->deleteById($id);
    }

    /**
     * Remove a suppression only if it belongs to the given account. Platform-wide
     * rows (account_id === null) and rows owned by another account are never
     * removable here — those are reserved for superadmin management. Returns
     * false when the row is absent or not owned by $accountId.
     */
    public function removeAccountSuppressionById(int $id, int $accountId): bool
    {
        // Matching on account_id folds the ownership check into the lookup and
        // naturally excludes platform-wide rows (account_id is null), which never
        // equal a concrete account id.
        $suppression = $this->emailSuppressionRepository->findFirstWhere([
            EmailSuppressionDomainObjectAbstract::ID => $id,
            EmailSuppressionDomainObjectAbstract::ACCOUNT_ID => $accountId,
        ]);

        if ($suppression === null) {
            return false;
        }

        $this->emailSuppressionRepository->deleteById($id);

        return true;
    }

    public function removeSuppression(string $email, ?int $accountId = null, ?string $reason = null): void
    {
        $where = [
            'email' => strtolower($email),
        ];

        if ($accountId !== null) {
            $where['account_id'] = $accountId;
        }

        if ($reason !== null) {
            $where['reason'] = $reason;
        }

        $suppressions = $this->emailSuppressionRepository->findWhere($where);

        foreach ($suppressions as $suppression) {
            $this->emailSuppressionRepository->deleteById($suppression->getId());
        }
    }
}
