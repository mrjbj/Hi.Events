import {t} from "@lingui/macro";
import {useMemo, useState} from "react";
import {Alert, Badge, Button, Group, Select, Table, Text, TextInput, Tooltip} from "@mantine/core";
import {IconCheck, IconSearch} from "@tabler/icons-react";
import {Card} from "../../../../common/Card";
import {Pagination} from "../../../../common/Pagination";
import {SortableTh} from "../../../../common/SortableTh";
import {TableSkeleton} from "../../../../common/TableSkeleton";
import {QueryFilters} from "../../../../../types.ts";
import {ContactBackfillStaleValueRow} from "../../../../../api/contact.client.ts";
import {useGetBackfillStaleValues} from "../../../../../queries/useGetBackfillStaleValues.ts";
import {useApplyStaleValueRemaps} from "../../../../../mutations/useApplyStaleValueRemaps.ts";
import {showError, showSuccess} from "../../../../../utilites/notifications.tsx";

const CLEAR_VALUE = '__CLEAR__';
const ADD_TO_OPTIONS = '__ADD_TO_OPTIONS__';

const formatCurrent = (value: string | string[]): string => {
    if (Array.isArray(value)) return value.join(', ');
    return value;
};

export const StaleValuesSubTab = () => {
    const [page, setPage] = useState(1);
    const [query, setQuery] = useState('');
    const [sortBy, setSortBy] = useState('contact_email');
    const [sortDir, setSortDir] = useState('asc');
    // Per-row replacement choices, keyed by the row's synthetic id. Cleared after
    // a successful bulk apply so the table re-renders empty.
    const [picks, setPicks] = useState<Record<string, string | null>>({});

    const applyMutation = useApplyStaleValueRemaps();

    const handleSort = (field: string) => {
        if (sortBy === field) setSortDir(sortDir === 'asc' ? 'desc' : 'asc');
        else { setSortBy(field); setSortDir('asc'); }
        setPage(1);
    };

    const params: QueryFilters = {
        pageNumber: page,
        perPage: 25,
        query: query || undefined,
        sortBy,
        sortDirection: sortDir,
    };

    const result = useGetBackfillStaleValues(params);
    const rows = result.data?.data;
    const meta = result.data?.meta;

    const pickedRows = useMemo(
        () => (rows ?? []).filter((r) => picks[r.id] !== undefined),
        [rows, picks],
    );

    const apply = () => {
        if (pickedRows.length === 0) return;

        const remaps = pickedRows.map((r) => buildRemap(r, picks[r.id]));

        applyMutation.mutate({remaps}, {
            onSuccess: (res) => {
                const {attributes_written, options_added} = res.data;
                const parts: string[] = [];
                if (attributes_written > 0) parts.push(t`Updated ${attributes_written} value(s).`);
                if (options_added > 0) parts.push(t`Added ${options_added} option(s) to allowed list.`);
                showSuccess(parts.length > 0 ? parts.join(' ') : t`Applied.`);
                setPicks({});
            },
            onError: () => showError(t`Could not apply remaps.`),
        });
    };

    return (
        <Card>
            <Group gap="sm" wrap="wrap" mb="md" align="center">
                <TextInput
                    placeholder={t`Search by email, attribute, or value...`}
                    leftSection={<IconSearch size={16}/>}
                    value={query}
                    onChange={(e) => { setQuery(e.currentTarget.value); setPage(1); }}
                    size="sm"
                    style={{flex: 1, minWidth: 240, marginBottom: 0}}
                />
                <Tooltip
                    label={t`Pick a replacement for at least one row first`}
                    disabled={pickedRows.length > 0}
                    withArrow
                >
                    <Button
                        size="sm"
                        leftSection={<IconCheck size={14}/>}
                        disabled={pickedRows.length === 0}
                        loading={applyMutation.isPending}
                        onClick={apply}
                    >
                        {pickedRows.length > 0 ? t`Apply (${pickedRows.length})` : t`Apply`}
                    </Button>
                </Tooltip>
            </Group>

            <Text size="sm" c="dimmed" mb="md">
                {t`Contact attributes whose stored value isn't in the attribute's current dropdown options — usually because the option list was edited, or the value came from a typo or import. Pick a replacement for each row (or "Clear") then click Apply. Changes are recorded in each contact's attribute history.`}
            </Text>

            {result.isLoading && <TableSkeleton isVisible/>}

            {!!result.error && (
                <Alert color="red" radius="md">{t`Failed to load stale values`}</Alert>
            )}

            {!result.isLoading && !result.error && rows && rows.length === 0 && (
                <Text c="dimmed" ta="center" py="xl">
                    {t`No stale values. All contact attribute values match their current option lists.`}
                </Text>
            )}

            {rows && rows.length > 0 && (
                <>
                    <Table striped highlightOnHover>
                        <Table.Thead>
                            <Table.Tr>
                                <SortableTh label={t`Contact`} field="contact_email" sortBy={sortBy} sortDir={sortDir} onSort={handleSort}/>
                                <SortableTh label={t`Attribute`} field="attribute_label" sortBy={sortBy} sortDir={sortDir} onSort={handleSort}/>
                                <Table.Th>{t`Current Value`}</Table.Th>
                                <Table.Th style={{width: 260}}>{t`Replace With`}</Table.Th>
                            </Table.Tr>
                        </Table.Thead>
                        <Table.Tbody>
                            {rows.map((row) => {
                                const suggested = suggestReplacement(row);
                                const data = buildSelectOptions(row);
                                return (
                                    <Table.Tr key={row.id}>
                                        <Table.Td>{row.contact_email}</Table.Td>
                                        <Table.Td>
                                            {row.attribute_label}
                                            <Text size="xs" c="dimmed">{row.attribute_name}</Text>
                                        </Table.Td>
                                        <Table.Td>
                                            <Badge size="sm" color="orange" variant="light">
                                                {formatCurrent(row.current_value)}
                                            </Badge>
                                        </Table.Td>
                                        <Table.Td>
                                            <Select
                                                size="sm"
                                                placeholder={suggested ? t`Suggested: ${suggested}` : t`Pick a replacement...`}
                                                data={data}
                                                value={picks[row.id] ?? null}
                                                onChange={(val) => {
                                                    setPicks((prev) => {
                                                        const next = {...prev};
                                                        if (val === null) delete next[row.id];
                                                        else next[row.id] = val;
                                                        return next;
                                                    });
                                                }}
                                                clearable
                                                searchable
                                                comboboxProps={{withinPortal: true}}
                                            />
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

type Remap = {
    contact_id: number;
    attribute_name: string;
    new_value?: string | string[] | null;
    add_values_to_options?: string[];
};

const buildRemap = (row: ContactBackfillStaleValueRow, pick: string | null | undefined): Remap => {
    const base = {contact_id: row.contact_id, attribute_name: row.attribute_name};

    // "+ Add to options": extend the definition's options to include the
    // stale value(s) on this row. No contact write — the current_value
    // becomes valid as-is once options widen.
    if (pick === ADD_TO_OPTIONS) {
        return {...base, add_values_to_options: row.invalid_values};
    }
    if (pick === CLEAR_VALUE || pick == null) {
        return {...base, new_value: null};
    }
    if (row.attribute_type === 'multi_select') {
        // Keep already-valid current values, swap each invalid one for the picked option.
        const current = Array.isArray(row.current_value) ? row.current_value : [row.current_value];
        const invalidSet = new Set(row.invalid_values);
        const cleaned = current.filter((v) => !invalidSet.has(v));
        return {...base, new_value: Array.from(new Set([...cleaned, pick]))};
    }
    return {...base, new_value: pick};
};

const buildSelectOptions = (row: ContactBackfillStaleValueRow) => {
    const addLabel = row.invalid_values.length === 1
        ? t`+ Add "${row.invalid_values[0]}" to allowed options`
        : t`+ Add ${row.invalid_values.length} stale values to allowed options`;
    return [
        ...row.options.map((o) => ({value: o, label: o})),
        {value: ADD_TO_OPTIONS, label: addLabel},
        {value: CLEAR_VALUE, label: t`(clear value)`},
    ];
};

/**
 * Best-guess replacement: case-insensitive exact match against the options list.
 * Catches the lowercase / typo / minor-whitespace cases without overreaching.
 * Returns the matched option, or null if no confident guess. Frontend hint only —
 * the backend still validates the actual pick.
 */
const suggestReplacement = (row: ContactBackfillStaleValueRow): string | null => {
    const first = Array.isArray(row.current_value) ? row.invalid_values[0] : row.current_value;
    if (typeof first !== 'string') return null;
    const needle = first.toLowerCase().trim();
    return row.options.find((o) => o.toLowerCase().trim() === needle) ?? null;
};
