import {t} from "@lingui/macro";
import {useState} from "react";
import {Badge, Stack, Tabs} from "@mantine/core";
import {IconAlertTriangle, IconExclamationCircle, IconLink, IconMail, IconUserPlus} from "@tabler/icons-react";
import {UnlinkedAttendeesSubTab} from "./UnlinkedAttendeesSubTab";
import {UnmappedQuestionsSubTab} from "./UnmappedQuestionsSubTab";
import {ConflictsSubTab} from "./ConflictsSubTab";
import {StaleValuesSubTab} from "./StaleValuesSubTab";
import {EmailChangesSubTab} from "./EmailChangesSubTab";
import {useGetBackfillSummary} from "../../../../../queries/useGetBackfillSummary.ts";
import classes from "./BackfillTab.module.scss";

export const BackfillTab = () => {
    const [activeSubTab, setActiveSubTab] = useState<string | null>('attendees');

    const summaryQuery = useGetBackfillSummary();
    const summary = summaryQuery.data?.data;

    const renderBadge = (count: number | undefined, color: string) => {
        if (count === undefined) return null;
        return <Badge size="xs" color={count > 0 ? color : 'gray'} variant="light">{count}</Badge>;
    };

    return (
        <Stack gap="md">
            <Tabs value={activeSubTab} onChange={setActiveSubTab} variant="outline" classNames={{tab: classes.tab}}>
                <Tabs.List mb="md">
                    <Tabs.Tab
                        value="attendees"
                        leftSection={<IconUserPlus size={14}/>}
                        rightSection={renderBadge(summary?.unlinked_attendees_count, 'blue')}
                    >
                        {t`New Contacts`}
                    </Tabs.Tab>
                    <Tabs.Tab
                        value="questions"
                        leftSection={<IconLink size={14}/>}
                        rightSection={renderBadge(summary?.unmapped_questions_count, 'blue')}
                    >
                        {t`New Questions`}
                    </Tabs.Tab>
                    <Tabs.Tab
                        value="conflicts"
                        leftSection={<IconAlertTriangle size={14}/>}
                        rightSection={renderBadge(summary?.conflicts_count, 'orange')}
                    >
                        {t`Different Answers`}
                    </Tabs.Tab>
                    <Tabs.Tab
                        value="email-changes"
                        leftSection={<IconMail size={14}/>}
                        rightSection={renderBadge(summary?.email_changes_count, 'orange')}
                    >
                        {t`Email Changes`}
                    </Tabs.Tab>
                    <Tabs.Tab
                        value="stale-values"
                        leftSection={<IconExclamationCircle size={14}/>}
                        rightSection={renderBadge(summary?.stale_values_count, 'red')}
                    >
                        {t`Stale Values`}
                    </Tabs.Tab>
                </Tabs.List>

                <Tabs.Panel value="attendees">
                    <UnlinkedAttendeesSubTab/>
                </Tabs.Panel>
                <Tabs.Panel value="questions">
                    <UnmappedQuestionsSubTab/>
                </Tabs.Panel>
                <Tabs.Panel value="conflicts">
                    <ConflictsSubTab/>
                </Tabs.Panel>
                <Tabs.Panel value="email-changes">
                    <EmailChangesSubTab/>
                </Tabs.Panel>
                <Tabs.Panel value="stale-values">
                    <StaleValuesSubTab/>
                </Tabs.Panel>
            </Tabs>
        </Stack>
    );
};
