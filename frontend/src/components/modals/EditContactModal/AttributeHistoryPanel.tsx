import {useMemo, useState} from "react";
import {Badge, Box, Button, Group, Select, Stack, Table, Text, Tooltip} from "@mantine/core";
import {t, Trans} from "@lingui/macro";
import {IconHistory} from "@tabler/icons-react";
import {Contact, ContactAttributeChange} from "../../../types.ts";
import {useGetContactAttributeDefinitions} from "../../../queries/useGetContactAttributeDefinitions.ts";
import {useGetUsers} from "../../../queries/useGetUsers.ts";
import {relativeDate} from "../../../utilites/dates.ts";

interface HistoryRow {
    changedAt: string;
    attributeName: string;
    attributeLabel: string;
    isDefinitionMissing: boolean;
    oldValue: unknown;
    newValue: unknown;
    changedBy: number | null;
    sourceQuestionAnswerIds?: number[];
    isEmailChange?: boolean;
    reason?: string;
}

// Sentinel attribute name for email-change rows (email isn't a contact attribute).
const EMAIL_ROW_KEY = '__email__';

const INITIAL_LIMIT = 100;

export const AttributeHistoryPanel = ({contact}: { contact: Contact }) => {
    const {data: definitionsData} = useGetContactAttributeDefinitions();
    const {data: usersData} = useGetUsers();
    const [attributeFilter, setAttributeFilter] = useState<string | null>(null);
    const [showAll, setShowAll] = useState(false);

    const definitionsByName = useMemo(() => {
        const map = new Map<string, { label: string; type: string }>();
        (definitionsData?.data ?? []).forEach(d => map.set(d.name, {label: d.label, type: d.type}));
        return map;
    }, [definitionsData]);

    const usersById = useMemo(() => {
        const map = new Map<number, string>();
        (usersData?.data ?? []).forEach(u => {
            if (u.id != null) {
                const fallback = `${u.first_name ?? ''} ${u.last_name ?? ''}`.trim();
                map.set(Number(u.id), u.full_name || fallback || u.email);
            }
        });
        return map;
    }, [usersData]);

    const rows = useMemo<HistoryRow[]>(() => {
        // A handful of legacy prod rows have a double-encoded JSON string here
        // (e.g. from an older email-change flow). The api/Eloquent layer
        // sometimes hands that through as a string rather than an array, and
        // calling .forEach on it crashes the whole modal. Treat anything
        // non-array as empty; the rest of the contact still renders.
        const raw = contact.attributes_history;
        const history: ContactAttributeChange[] = Array.isArray(raw) ? raw : [];
        const emitted: HistoryRow[] = [];
        history.forEach(change => {
            // Email-change entries use a flat {field, old_value, new_value, reason}
            // shape rather than the attribute old_values/new_values map.
            if (change.field === 'email') {
                if (!valuesEqual(change.old_value, change.new_value)) {
                    emitted.push({
                        changedAt: change.changed_at,
                        attributeName: EMAIL_ROW_KEY,
                        attributeLabel: t`Email address`,
                        isDefinitionMissing: false,
                        oldValue: change.old_value,
                        newValue: change.new_value,
                        changedBy: change.changed_by ?? null,
                        isEmailChange: true,
                        reason: change.reason,
                    });
                }
                return;
            }

            const keys = new Set<string>([
                ...Object.keys(change.old_values ?? {}),
                ...Object.keys(change.new_values ?? {}),
            ]);
            keys.forEach(name => {
                const oldVal = change.old_values?.[name];
                const newVal = change.new_values?.[name];
                if (valuesEqual(oldVal, newVal)) return;
                const def = definitionsByName.get(name);
                emitted.push({
                    changedAt: change.changed_at,
                    attributeName: name,
                    attributeLabel: def?.label ?? name,
                    isDefinitionMissing: !def,
                    oldValue: oldVal,
                    newValue: newVal,
                    changedBy: change.changed_by,
                    sourceQuestionAnswerIds: change.source_question_answer_ids,
                });
            });
        });
        emitted.sort((a, b) => {
            const byLabel = a.attributeLabel.localeCompare(b.attributeLabel);
            if (byLabel !== 0) return byLabel;
            return b.changedAt.localeCompare(a.changedAt);
        });
        return emitted;
    }, [contact.attributes_history, definitionsByName]);

    const attributeOptions = useMemo(() => {
        const seen = new Map<string, string>();
        rows.forEach(r => {
            if (!seen.has(r.attributeName)) seen.set(r.attributeName, r.attributeLabel);
        });
        return Array.from(seen.entries()).map(([value, label]) => ({value, label}));
    }, [rows]);

    const filteredRows = useMemo(
        () => attributeFilter ? rows.filter(r => r.attributeName === attributeFilter) : rows,
        [rows, attributeFilter]
    );

    if (rows.length === 0) {
        return (
            <Box py="xl" ta="center">
                <IconHistory size={32} style={{opacity: 0.4}}/>
                <Text c="dimmed" mt="xs">
                    <Trans>No changes yet.</Trans>
                </Text>
            </Box>
        );
    }

    const uniqueAttrs = new Set(rows.map(r => r.attributeName)).size;
    const oldestChange = rows.reduce((min, r) => r.changedAt < min ? r.changedAt : min, rows[0].changedAt);
    const visibleRows = showAll ? filteredRows : filteredRows.slice(0, INITIAL_LIMIT);
    const hiddenCount = filteredRows.length - visibleRows.length;

    return (
        <Stack gap="sm">
            <Group justify="space-between" align="flex-end" wrap="wrap">
                <Text size="sm" c="dimmed">
                    {t`${rows.length} changes across ${uniqueAttrs} attributes · since ${relativeDate(oldestChange)}`}
                </Text>
                <Select
                    placeholder={t`Filter by attribute`}
                    clearable
                    value={attributeFilter}
                    onChange={setAttributeFilter}
                    data={attributeOptions}
                    w={260}
                    size="xs"
                />
            </Group>
            <Table striped highlightOnHover verticalSpacing="xs">
                <Table.Thead>
                    <Table.Tr>
                        <Table.Th>{t`Attribute`}</Table.Th>
                        <Table.Th>{t`Begin`}</Table.Th>
                        <Table.Th>{t`End`}</Table.Th>
                        <Table.Th>{t`Changed by`}</Table.Th>
                        <Table.Th>{t`When`}</Table.Th>
                        <Table.Th>{t`Source`}</Table.Th>
                    </Table.Tr>
                </Table.Thead>
                <Table.Tbody>
                    {visibleRows.map((row, idx) => {
                        const userName = row.changedBy != null ? usersById.get(row.changedBy) : null;
                        const isSync = (row.sourceQuestionAnswerIds?.length ?? 0) > 0;
                        return (
                            <Table.Tr key={`${row.changedAt}-${row.attributeName}-${idx}`}>
                                <Table.Td>
                                    <Text size="sm" fw={500}>{row.attributeLabel}</Text>
                                    {row.isDefinitionMissing && (
                                        <Text size="xs" c="dimmed" fs="italic">
                                            <Trans>(deleted attribute)</Trans>
                                        </Text>
                                    )}
                                </Table.Td>
                                <Table.Td><ValueCell value={row.oldValue}/></Table.Td>
                                <Table.Td><ValueCell value={row.newValue}/></Table.Td>
                                <Table.Td>
                                    {userName ? (
                                        <Text size="sm">{userName}</Text>
                                    ) : row.changedBy != null ? (
                                        <Text size="sm" c="dimmed">#{row.changedBy}</Text>
                                    ) : (
                                        <Text size="sm" c="dimmed" fs="italic"><Trans>System</Trans></Text>
                                    )}
                                </Table.Td>
                                <Table.Td>
                                    <Tooltip label={row.changedAt} withArrow>
                                        <Text size="sm" span>{relativeDate(row.changedAt)}</Text>
                                    </Tooltip>
                                </Table.Td>
                                <Table.Td>
                                    {row.isEmailChange ? (
                                        <Tooltip label={row.reason ? t`Reason: ${row.reason}` : t`Email address change`} withArrow>
                                            <Badge size="sm" variant="light" color="violet">{t`Email change`}</Badge>
                                        </Tooltip>
                                    ) : isSync ? (
                                        <Badge size="sm" variant="light" color="blue">{t`From question answer`}</Badge>
                                    ) : (
                                        <Badge size="sm" variant="light" color="gray">{t`Manual edit`}</Badge>
                                    )}
                                </Table.Td>
                            </Table.Tr>
                        );
                    })}
                </Table.Tbody>
            </Table>
            {hiddenCount > 0 && (
                <Group justify="center">
                    <Button variant="subtle" size="xs" onClick={() => setShowAll(true)}>
                        {t`Show ${hiddenCount} more`}
                    </Button>
                </Group>
            )}
        </Stack>
    );
};

const ValueCell = ({value}: { value: unknown }) => {
    if (value === null || value === undefined || value === '') {
        return <Text size="sm" c="dimmed">—</Text>;
    }
    if (Array.isArray(value)) {
        if (value.length === 0) return <Text size="sm" c="dimmed">—</Text>;
        return (
            <Group gap={4}>
                {value.map((v, i) => (
                    <Badge key={i} size="xs" variant="light" color="gray">{String(v)}</Badge>
                ))}
            </Group>
        );
    }
    if (typeof value === 'boolean') {
        return <Text size="sm">{value ? t`Yes` : t`No`}</Text>;
    }
    return <Text size="sm">{String(value)}</Text>;
};

const valuesEqual = (a: unknown, b: unknown): boolean => {
    if (a === b) return true;
    const aEmpty = a === null || a === undefined || a === '';
    const bEmpty = b === null || b === undefined || b === '';
    if (aEmpty && bEmpty) return true;
    if (aEmpty !== bEmpty) return false;
    if (Array.isArray(a) && Array.isArray(b)) {
        if (a.length !== b.length) return false;
        const sa = [...a].map(String).sort();
        const sb = [...b].map(String).sort();
        return sa.every((v, i) => v === sb[i]);
    }
    return false;
};
