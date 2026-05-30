import {t} from "@lingui/macro";
import {useMemo, useState} from "react";
import {Alert, Badge, Button, Checkbox, Group, Select, Switch, Table, Text, TextInput, Tooltip} from "@mantine/core";
import {IconArrowRight, IconCheck, IconEyeOff, IconSearch, IconUsersGroup} from "@tabler/icons-react";
import {Card} from "../../../../common/Card";
import {Pagination} from "../../../../common/Pagination";
import {SortableTh} from "../../../../common/SortableTh";
import {TableSkeleton} from "../../../../common/TableSkeleton";
import {QueryFilterOperator, QueryFilters} from "../../../../../types.ts";
import {useGetEvents} from "../../../../../queries/useGetEvents.ts";
import {useGetBackfillEmailChanges} from "../../../../../queries/useGetBackfillEmailChanges.ts";
import {useApplyEmailChangeDecisions} from "../../../../../mutations/useApplyEmailChangeDecisions.ts";
import {useRowSelection} from "../../../../../hooks/useRowSelection.ts";
import {showError, showSuccess} from "../../../../../utilites/notifications.tsx";

export const EmailChangesSubTab = () => {
    const [page, setPage] = useState(1);
    const [query, setQuery] = useState('');
    const [eventFilter, setEventFilter] = useState<string | null>(null);
    const [sortBy, setSortBy] = useState('contact_email');
    const [sortDir, setSortDir] = useState('asc');
    const [showProcessed, setShowProcessed] = useState(false);

    const applyMutation = useApplyEmailChangeDecisions();
    const eventsQuery = useGetEvents({pageNumber: 1, perPage: 100});
    const eventOptions = useMemo(
        () => (eventsQuery.data?.data ?? []).map((e: any) => ({value: String(e.id), label: e.title})),
        [eventsQuery.data],
    );

    const handleSort = (field: string) => {
        if (sortBy === field) setSortDir(sortDir === 'asc' ? 'desc' : 'asc');
        else { setSortBy(field); setSortDir('asc'); }
        setPage(1);
    };

    const filterFields: Record<string, any> = {};
    if (eventFilter) filterFields.event_id = {operator: QueryFilterOperator.Equals, value: eventFilter};

    const params: QueryFilters = {
        pageNumber: page,
        perPage: 25,
        query: query || undefined,
        filterFields: Object.keys(filterFields).length > 0 ? filterFields : undefined,
        sortBy,
        sortDirection: sortDir,
    };

    const result = useGetBackfillEmailChanges(params, showProcessed);
    const rows = result.data?.data;
    const meta = result.data?.meta;

    const activeIdsInOrder = useMemo(
        () => (rows ?? []).filter((r) => !r.processed).map((r) => r.attendee_id),
        [rows],
    );
    const selection = useRowSelection<number>(activeIdsInOrder);

    const selectedIds = useMemo(
        () => (rows ?? [])
            .filter((r) => !r.processed && selection.isSelected(r.attendee_id))
            .map((r) => r.attendee_id),
        [rows, selection],
    );

    const allActiveSelected = activeIdsInOrder.length > 0
        && activeIdsInOrder.every((id) => selection.isSelected(id));

    // "Update contact" renames the contact in place — only safe when the contact
    // is the attendee's alone. If any selected row is on a shared contact, steer
    // to "Split" instead (the backend degrades update→split as a backstop anyway).
    const anySharedSelected = useMemo(
        () => (rows ?? []).some((r) => !r.processed && selection.isSelected(r.attendee_id) && r.shared),
        [rows, selection],
    );

    const applyDecision = (decision: 'update' | 'split' | 'ignore') => {
        if (selectedIds.length === 0) return;
        const decisions = selectedIds.map((id) => ({attendee_id: id, decision}));
        applyMutation.mutate({decisions}, {
            onSuccess: (res) => {
                if (decision === 'update') {
                    showSuccess(t`Updated ${res.data.count} contact(s).`);
                } else if (decision === 'split') {
                    showSuccess(t`Split ${res.data.count} ticket(s) to their own contact.`);
                } else {
                    showSuccess(t`Kept ${res.data.count} ticket-only change(s).`);
                }
                selection.clear();
            },
            onError: () => showError(t`Could not apply decisions.`),
        });
    };

    const contactName = (row: { contact_first_name: string | null; contact_last_name: string | null }) =>
        [row.contact_first_name, row.contact_last_name].filter(Boolean).join(' ').trim();

    return (
        <Card>
            <Group gap="sm" wrap="wrap" mb="md" align="center">
                <TextInput
                    placeholder={t`Search by email or name...`}
                    leftSection={<IconSearch size={16}/>}
                    value={query}
                    onChange={(e) => { setQuery(e.currentTarget.value); setPage(1); }}
                    size="sm"
                    style={{flex: 1, minWidth: 220, marginBottom: 0}}
                />
                <Select
                    placeholder={t`Filter by event`}
                    data={eventOptions}
                    value={eventFilter}
                    onChange={(val) => { setEventFilter(val); setPage(1); }}
                    clearable
                    searchable
                    size="sm"
                    style={{width: 220, marginBottom: 0}}
                />
                <Switch
                    label={t`Show kept`}
                    checked={showProcessed}
                    onChange={(e) => { setShowProcessed(e.currentTarget.checked); setPage(1); }}
                    size="sm"
                    style={{marginBottom: 0}}
                />
                <Tooltip
                    label={t`Some selected tickets share a contact (e.g. a group/sponsor order). Use "Split to own contact" so the shared contact isn't changed.`}
                    disabled={!anySharedSelected}
                    multiline
                    w={260}
                >
                    <Button
                        size="sm"
                        leftSection={<IconCheck size={14}/>}
                        disabled={selectedIds.length === 0 || anySharedSelected}
                        loading={applyMutation.isPending && applyMutation.variables?.decisions?.[0]?.decision === 'update'}
                        onClick={() => applyDecision('update')}
                    >
                        {selectedIds.length > 0 ? t`Update contact (${selectedIds.length})` : t`Update contact`}
                    </Button>
                </Tooltip>
                <Button
                    size="sm"
                    variant="light"
                    color="grape"
                    leftSection={<IconUsersGroup size={14}/>}
                    disabled={selectedIds.length === 0}
                    loading={applyMutation.isPending && applyMutation.variables?.decisions?.[0]?.decision === 'split'}
                    onClick={() => applyDecision('split')}
                >
                    {t`Split to own contact`}
                </Button>
                <Button
                    size="sm"
                    variant="default"
                    leftSection={<IconEyeOff size={14}/>}
                    disabled={selectedIds.length === 0}
                    loading={applyMutation.isPending && applyMutation.variables?.decisions?.[0]?.decision === 'ignore'}
                    onClick={() => applyDecision('ignore')}
                >
                    {t`Keep ticket-only`}
                </Button>
            </Group>

            <Text size="sm" c="dimmed" mb="md">
                {t`Tickets whose email was changed (e.g. at check-in) so it no longer matches the linked contact's email. Select rows and click "Update contact" to write the new address onto the contact — this also updates the person's other tickets. Click "Keep ticket-only" to leave the contact untouched; the change stays on just this ticket and the row drops off the list.`}
            </Text>

            {result.isLoading && <TableSkeleton isVisible/>}

            {!!result.error && (
                <Alert color="red" radius="md">{t`Failed to load email changes`}</Alert>
            )}

            {!result.isLoading && !result.error && rows && rows.length === 0 && (
                <Text c="dimmed" ta="center" py="xl">
                    {showProcessed
                        ? t`No email changes.`
                        : t`No pending email changes. Toggle "Show kept" to see ones you've left as ticket-only.`}
                </Text>
            )}

            {rows && rows.length > 0 && (
                <>
                    <Table striped highlightOnHover>
                        <Table.Thead>
                            <Table.Tr>
                                <Table.Th style={{width: 36}}>
                                    <Checkbox
                                        aria-label={t`Select all`}
                                        checked={allActiveSelected}
                                        indeterminate={selection.count > 0 && !allActiveSelected}
                                        disabled={activeIdsInOrder.length === 0}
                                        onChange={() => {
                                            if (selection.count > 0) selection.clear();
                                            else selection.selectAll();
                                        }}
                                    />
                                </Table.Th>
                                <SortableTh label={t`Contact`} field="contact_email" sortBy={sortBy} sortDir={sortDir} onSort={handleSort}/>
                                <SortableTh label={t`New ticket email`} field="attendee_email" sortBy={sortBy} sortDir={sortDir} onSort={handleSort}/>
                                <SortableTh label={t`Event`} field="event_title" sortBy={sortBy} sortDir={sortDir} onSort={handleSort}/>
                                <Table.Th style={{width: 110}}>{t`Status`}</Table.Th>
                            </Table.Tr>
                        </Table.Thead>
                        <Table.Tbody>
                            {rows.map((row) => {
                                const rowStyle = row.processed ? {opacity: 0.55} : undefined;
                                const name = contactName(row);
                                return (
                                    <Table.Tr key={row.attendee_id} style={rowStyle}>
                                        <Table.Td>
                                            {!row.processed && (
                                                <Checkbox
                                                    aria-label={t`Select row`}
                                                    checked={selection.isSelected(row.attendee_id)}
                                                    onMouseDown={(e) => selection.captureShift(e.shiftKey)}
                                                    onKeyDown={(e) => selection.captureShift(e.shiftKey)}
                                                    onChange={() => selection.toggleWithStoredShift(row.attendee_id)}
                                                />
                                            )}
                                        </Table.Td>
                                        <Table.Td>
                                            <Group gap={6} wrap="nowrap">
                                                {name && <Text size="sm" fw={500}>{name}</Text>}
                                                {row.shared && (
                                                    <Tooltip label={t`Shared by ${row.shared_count} tickets — updating the contact would affect them all, so split instead.`} multiline w={240}>
                                                        <Badge size="xs" color="grape" variant="light" leftSection={<IconUsersGroup size={10}/>}>
                                                            {t`Shared ×${row.shared_count}`}
                                                        </Badge>
                                                    </Tooltip>
                                                )}
                                            </Group>
                                            <Text size="sm" c="dimmed">{row.contact_email}</Text>
                                        </Table.Td>
                                        <Table.Td>
                                            <Group gap={6} wrap="nowrap">
                                                <IconArrowRight size={14} style={{flexShrink: 0, opacity: 0.5}}/>
                                                <Text size="sm">{row.attendee_email}</Text>
                                            </Group>
                                        </Table.Td>
                                        <Table.Td>{row.event_title || '-'}</Table.Td>
                                        <Table.Td>
                                            {row.processed && (
                                                <Badge size="sm" variant="light" color="gray">{t`Ticket only`}</Badge>
                                            )}
                                        </Table.Td>
                                    </Table.Tr>
                                );
                            })}
                        </Table.Tbody>
                    </Table>

                    {meta && Number(meta.last_page) > 1 && (
                        <Pagination value={page} onChange={setPage} total={Number(meta.last_page)}/>
                    )}
                </>
            )}
        </Card>
    );
};
