import {ActionIcon, Badge, Group, Loader, Paper, Table, Text, ThemeIcon, Tooltip} from "@mantine/core";
import {IconCalendarEvent, IconExternalLink, IconReceipt2} from "@tabler/icons-react";
import {t} from "@lingui/macro";
import {IdParam, Order} from "../../../types.ts";
import {useGetContactActivity} from "../../../queries/useGetContactActivity.ts";
import {OrderStatusBadge} from "../../common/OrderStatusBadge";
import {formatCurrency} from "../../../utilites/currency.ts";
import {relativeDate} from "../../../utilites/dates.ts";
import classes from "./EditContactModal.module.scss";

interface ContactActivityPanelProps {
    contactId: IdParam;
    contactEmail?: string;
}

export const ContactActivityPanel = ({contactId, contactEmail}: ContactActivityPanelProps) => {
    const {data, isLoading, error} = useGetContactActivity(contactId);
    const events = data?.data.events ?? [];
    const orders = data?.data.orders ?? [];

    if (isLoading) {
        return <Group justify="center" py="xl"><Loader size="sm"/></Group>;
    }

    if (error) {
        return <Text c="dimmed" ta="center" py="md">{t`Couldn't load this contact's activity.`}</Text>;
    }

    return (
        <div>
            <Paper withBorder radius="md" mb="md">
                <Group className={classes.activityHeader} justify="space-between">
                    <Group gap="xs">
                        <ThemeIcon variant="light" color="primary" size="sm" radius="sm">
                            <IconReceipt2 size={15}/>
                        </ThemeIcon>
                        <Text fw={600} size="sm">{t`Orders`}</Text>
                    </Group>
                    <Badge variant="light" color="gray">{orders.length}</Badge>
                </Group>
                {orders.length === 0 ? (
                    <Text c="dimmed" size="sm" p="md">{t`No orders yet.`}</Text>
                ) : (
                    <Table verticalSpacing="xs" highlightOnHover>
                        <Table.Thead>
                            <Table.Tr>
                                <Table.Th>{t`Order`}</Table.Th>
                                <Table.Th>{t`Event`}</Table.Th>
                                <Table.Th>{t`Status`}</Table.Th>
                                <Table.Th style={{textAlign: 'right'}}>{t`Total`}</Table.Th>
                                <Table.Th>{t`When`}</Table.Th>
                                <Table.Th/>
                            </Table.Tr>
                        </Table.Thead>
                        <Table.Tbody>
                            {orders.map((o) => (
                                <Table.Tr key={o.id}>
                                    <Table.Td>{o.public_id ?? `#${o.id}`}</Table.Td>
                                    <Table.Td>{o.event_title ?? '—'}</Table.Td>
                                    <Table.Td><OrderStatusBadge order={o as unknown as Order}/></Table.Td>
                                    <Table.Td style={{textAlign: 'right'}}>
                                        {formatCurrency(o.total_gross, o.currency ?? 'USD')}
                                    </Table.Td>
                                    <Table.Td>{o.created_at ? relativeDate(o.created_at) : '—'}</Table.Td>
                                    <Table.Td style={{textAlign: 'right'}}>
                                        <Tooltip label={t`Open order`} withArrow>
                                            <ActionIcon
                                                component="a"
                                                href={`/manage/event/${o.event_id}/orders?query=${encodeURIComponent(o.public_id ?? '')}`}
                                                variant="subtle"
                                                color="gray"
                                                aria-label={t`Open order`}
                                            >
                                                <IconExternalLink size={15}/>
                                            </ActionIcon>
                                        </Tooltip>
                                    </Table.Td>
                                </Table.Tr>
                            ))}
                        </Table.Tbody>
                    </Table>
                )}
            </Paper>

            <Paper withBorder radius="md">
                <Group className={classes.activityHeader} justify="space-between">
                    <Group gap="xs">
                        <ThemeIcon variant="light" color="grape" size="sm" radius="sm">
                            <IconCalendarEvent size={15}/>
                        </ThemeIcon>
                        <Text fw={600} size="sm">{t`Attended`}</Text>
                    </Group>
                    <Badge variant="light" color="gray">{events.length}</Badge>
                </Group>
                {events.length === 0 ? (
                    <Text c="dimmed" size="sm" p="md">{t`No events yet.`}</Text>
                ) : (
                    <Table verticalSpacing="xs" highlightOnHover>
                        <Table.Thead>
                            <Table.Tr>
                                <Table.Th>{t`Event`}</Table.Th>
                                <Table.Th>{t`When`}</Table.Th>
                                <Table.Th style={{textAlign: 'right'}}>{t`Tickets`}</Table.Th>
                                <Table.Th/>
                            </Table.Tr>
                        </Table.Thead>
                        <Table.Tbody>
                            {events.map((e) => (
                                <Table.Tr key={e.id}>
                                    <Table.Td>{e.title}</Table.Td>
                                    <Table.Td>{e.start_date ? relativeDate(e.start_date) : '—'}</Table.Td>
                                    <Table.Td style={{textAlign: 'right'}}>{e.tickets_count}</Table.Td>
                                    <Table.Td style={{textAlign: 'right'}}>
                                        {/* Land on the event's attendee list filtered to this contact (search
                                            matches email), so you see their specific attendee row(s). */}
                                        <Tooltip label={t`View attendance`} withArrow>
                                            <ActionIcon
                                                component="a"
                                                href={`/manage/event/${e.id}/attendees${contactEmail ? `?query=${encodeURIComponent(contactEmail)}` : ''}`}
                                                variant="subtle"
                                                color="gray"
                                                aria-label={t`View attendance`}
                                            >
                                                <IconExternalLink size={15}/>
                                            </ActionIcon>
                                        </Tooltip>
                                    </Table.Td>
                                </Table.Tr>
                            ))}
                        </Table.Tbody>
                    </Table>
                )}
            </Paper>
        </div>
    );
};
