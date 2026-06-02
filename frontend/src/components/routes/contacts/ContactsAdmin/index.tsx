import {t} from "@lingui/macro";
import {PageTitle} from "../../../common/PageTitle";
import {PageBody} from "../../../common/PageBody";
import {ActionIcon, Group, Tabs, Tooltip} from "@mantine/core";
import {useCallback, useState} from "react";
import {IconAddressBook, IconForms, IconMailOff, IconRefresh, IconRefreshDot} from "@tabler/icons-react";
import {useQueryClient} from "@tanstack/react-query";
import {ContactsTab} from "./ContactsTab";
import {ExtendedAttributesTab} from "./ExtendedAttributesTab";
import {BackfillTab} from "./BackfillTab";
import {SuppressionsTab} from "./SuppressionsTab";
import {GET_ACCOUNT_EMAIL_SUPPRESSIONS_QUERY_KEY} from "../../../../queries/useGetAccountEmailSuppressions.ts";
import {BackfillHelpPopover} from "./BackfillTab/BackfillHelpPanel";
import {GET_CONTACTS_QUERY_KEY} from "../../../../queries/useGetContacts.ts";
import {GET_CONTACT_ATTRIBUTE_DEFINITIONS_QUERY_KEY} from "../../../../queries/useGetContactAttributeDefinitions.ts";
import {GET_BACKFILL_SUMMARY_QUERY_KEY} from "../../../../queries/useGetBackfillSummary.ts";
import {GET_BACKFILL_UNLINKED_ATTENDEES_QUERY_KEY} from "../../../../queries/useGetBackfillUnlinkedAttendees.ts";
import {GET_BACKFILL_UNMAPPED_QUESTIONS_QUERY_KEY} from "../../../../queries/useGetBackfillUnmappedQuestions.ts";
import {GET_BACKFILL_CONFLICTS_QUERY_KEY} from "../../../../queries/useGetBackfillConflicts.ts";
import {GET_BACKFILL_STALE_VALUES_QUERY_KEY} from "../../../../queries/useGetBackfillStaleValues.ts";
import {GET_BACKFILL_EMAIL_CHANGES_QUERY_KEY} from "../../../../queries/useGetBackfillEmailChanges.ts";
import {useIsCurrentUserAdmin} from "../../../../hooks/useIsCurrentUserAdmin.ts";

const ContactsAdmin = () => {
    const [activeTab, setActiveTab] = useState<string | null>('contacts');
    const [spinning, setSpinning] = useState(false);
    const queryClient = useQueryClient();
    // Organizers browse contacts (read-only); attribute/sync/suppression management
    // is admin-only.
    const isAdmin = useIsCurrentUserAdmin();

    const handleRefresh = useCallback(() => {
        const keys: string[] = (() => {
            if (activeTab === 'contacts') return [GET_CONTACTS_QUERY_KEY];
            if (activeTab === 'attributes') return [GET_CONTACT_ATTRIBUTE_DEFINITIONS_QUERY_KEY];
            if (activeTab === 'suppressions') return [GET_ACCOUNT_EMAIL_SUPPRESSIONS_QUERY_KEY];
            if (activeTab === 'backfill') {
                return [
                    GET_BACKFILL_SUMMARY_QUERY_KEY,
                    GET_BACKFILL_UNLINKED_ATTENDEES_QUERY_KEY,
                    GET_BACKFILL_UNMAPPED_QUESTIONS_QUERY_KEY,
                    GET_BACKFILL_CONFLICTS_QUERY_KEY,
                    GET_BACKFILL_EMAIL_CHANGES_QUERY_KEY,
                    GET_BACKFILL_STALE_VALUES_QUERY_KEY,
                ];
            }
            return [];
        })();
        keys.forEach((key) => queryClient.invalidateQueries({queryKey: [key]}));
        setSpinning(true);
        setTimeout(() => setSpinning(false), 600);
    }, [activeTab, queryClient]);

    return (
        <PageBody>
            <style>{`@keyframes refresh-nudge { 0% { transform: rotate(0deg); } 50% { transform: rotate(90deg); } 100% { transform: rotate(0deg); } }`}</style>
            <Group justify="space-between" align="center" wrap="nowrap">
                <Group gap={4} align="center">
                    <PageTitle>
                        {t`Contacts`}
                    </PageTitle>
                    <Tooltip label={t`Refresh`}>
                        <ActionIcon variant="subtle" color="gray" size="lg" onClick={handleRefresh} mb={10}>
                            <IconRefresh size={20} style={{animation: spinning ? 'refresh-nudge 0.4s ease' : 'none'}}/>
                        </ActionIcon>
                    </Tooltip>
                </Group>
                <BackfillHelpPopover/>
            </Group>

            <Tabs value={activeTab} onChange={setActiveTab}>
                <Tabs.List mb="md">
                    <Tabs.Tab value="contacts" leftSection={<IconAddressBook size={16}/>}>
                        {t`Contacts`}
                    </Tabs.Tab>
                    {isAdmin && (
                        <Tabs.Tab value="attributes" leftSection={<IconForms size={16}/>}>
                            {t`Extended Attributes`}
                        </Tabs.Tab>
                    )}
                    {isAdmin && (
                        <Tabs.Tab value="backfill" leftSection={<IconRefreshDot size={16}/>}>
                            {t`Sync`}
                        </Tabs.Tab>
                    )}
                    {isAdmin && (
                        <Tabs.Tab value="suppressions" leftSection={<IconMailOff size={16}/>}>
                            {t`Suppressions`}
                        </Tabs.Tab>
                    )}
                </Tabs.List>

                <Tabs.Panel value="contacts">
                    <ContactsTab/>
                </Tabs.Panel>
                {isAdmin && (
                    <Tabs.Panel value="attributes">
                        <ExtendedAttributesTab/>
                    </Tabs.Panel>
                )}
                {isAdmin && (
                    <Tabs.Panel value="backfill">
                        <BackfillTab/>
                    </Tabs.Panel>
                )}
                {isAdmin && (
                    <Tabs.Panel value="suppressions">
                        {activeTab === 'suppressions' && <SuppressionsTab/>}
                    </Tabs.Panel>
                )}
            </Tabs>
        </PageBody>
    );
};

export default ContactsAdmin;
