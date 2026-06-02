import {t} from "@lingui/macro";
import {useEffect, useState} from "react";
import {ActionIcon, Alert, Badge, Button, Group, Modal, Select, Stack, Table, Text, TextInput, Tooltip} from "@mantine/core";
import {IconMailOff, IconPlus, IconSearch, IconTrash} from "@tabler/icons-react";
import {useForm} from "@mantine/form";
import {useDisclosure} from "@mantine/hooks";
import {Card} from "../../../common/Card";
import {Pagination} from "../../../common/Pagination";
import {TableSkeleton} from "../../../common/TableSkeleton";
import {useGetAccountEmailSuppressions} from "../../../../queries/useGetAccountEmailSuppressions.ts";
import {
    useCreateAccountEmailSuppression,
    useDeleteAccountEmailSuppression,
} from "../../../../mutations/useAccountEmailSuppressionMutations.ts";
import {useEscapeClearsFilters} from "../../../../hooks/useEscapeClearsFilters.ts";
import {showError, showSuccess} from "../../../../utilites/notifications.tsx";
import {confirmationDialog} from "../../../../utilites/confirmationDialog.tsx";
import {relativeDate} from "../../../../utilites/dates.ts";
import {IdParam} from "../../../../types.ts";

const reasonColor = (reason: string) => {
    switch (reason.toLowerCase()) {
        case 'bounce':
            return 'red';
        case 'complaint':
            return 'orange';
        case 'do_not_contact':
            return 'grape';
        default:
            return 'gray';
    }
};

export const SuppressionsTab = () => {
    const [page, setPage] = useState(1);
    const [search, setSearch] = useState('');
    const [debouncedSearch, setDebouncedSearch] = useState('');
    const [reasonFilter, setReasonFilter] = useState<string | null>(null);
    const [typeFilter, setTypeFilter] = useState<string | null>(null);
    const [createModalOpened, {open: openCreateModal, close: closeCreateModal}] = useDisclosure(false);

    useEffect(() => {
        const timer = setTimeout(() => {
            setDebouncedSearch(search);
            setPage(1);
        }, 400);
        return () => clearTimeout(timer);
    }, [search]);

    const suppressionsQuery = useGetAccountEmailSuppressions({
        page,
        per_page: 20,
        search: debouncedSearch || undefined,
        reason: reasonFilter || undefined,
        bounce_type: typeFilter || undefined,
    });
    const suppressions = suppressionsQuery.data?.data;
    const pagination = suppressionsQuery.data?.meta;

    const createMutation = useCreateAccountEmailSuppression();
    const deleteMutation = useDeleteAccountEmailSuppression();

    useEscapeClearsFilters({
        steps: [
            {isActive: () => search !== '', clear: () => { setSearch(''); setPage(1); }},
            {isActive: () => reasonFilter !== null, clear: () => { setReasonFilter(null); setPage(1); }},
            {isActive: () => typeFilter !== null, clear: () => { setTypeFilter(null); setPage(1); }},
        ],
    });

    const form = useForm({
        initialValues: {email: ''},
        validate: {
            email: (value) => (!value || !value.includes('@') ? t`Please enter a valid email address` : null),
        },
    });

    const handleCreate = (values: typeof form.values) => {
        createMutation.mutate(values.email, {
            onSuccess: () => {
                showSuccess(t`This address will no longer receive any email`);
                form.reset();
                closeCreateModal();
            },
            onError: () => showError(t`Failed to add suppression`),
        });
    };

    const handleDelete = (id: IdParam) => {
        confirmationDialog(
            t`Remove this suppression? The address will be able to receive email again.`,
            () => deleteMutation.mutate(id, {
                onSuccess: () => showSuccess(t`Suppression removed`),
                onError: () => showError(t`Failed to remove suppression`),
            }),
            {confirm: t`Remove`, cancel: t`Cancel`},
        );
    };

    return (
        <Card>
            <Group gap="sm" wrap="wrap" mb="md" align="center">
                <TextInput
                    placeholder={t`Search by email...`}
                    leftSection={<IconSearch size={16}/>}
                    value={search}
                    onChange={(e) => setSearch(e.currentTarget.value)}
                    size="sm"
                    style={{flex: 1, minWidth: 220, marginBottom: 0}}
                />
                <Select
                    placeholder={t`Reason`}
                    clearable
                    data={[
                        {value: 'bounce', label: t`Bounce`},
                        {value: 'complaint', label: t`Complaint`},
                        {value: 'do_not_contact', label: t`Do not contact`},
                    ]}
                    value={reasonFilter}
                    onChange={(val) => { setReasonFilter(val); setPage(1); }}
                    size="sm"
                    style={{width: 170, marginBottom: 0}}
                />
                <Select
                    placeholder={t`Type`}
                    clearable
                    data={[
                        {value: 'Permanent', label: t`Permanent`},
                        {value: 'Transient', label: t`Transient`},
                        {value: 'Undetermined', label: t`Undetermined`},
                    ]}
                    value={typeFilter}
                    onChange={(val) => { setTypeFilter(val); setPage(1); }}
                    size="sm"
                    style={{width: 160, marginBottom: 0}}
                />
                <Button leftSection={<IconPlus size={16}/>} onClick={openCreateModal} size="sm">
                    {t`Never email an address`}
                </Button>
            </Group>

            {suppressionsQuery.isLoading && <TableSkeleton isVisible/>}

            {!!suppressionsQuery.error && (
                <Alert color="red" radius="md">{t`Failed to load suppressions`}</Alert>
            )}

            {!suppressionsQuery.isLoading && !suppressionsQuery.error && suppressions && suppressions.length === 0 && (
                <Stack align="center" py="xl" gap={4}>
                    <IconMailOff size={40} color="gray"/>
                    <Text c="dimmed">{t`No suppressed emails for this account.`}</Text>
                </Stack>
            )}

            {suppressions && suppressions.length > 0 && (
                <>
                    <Table striped highlightOnHover>
                        <Table.Thead>
                            <Table.Tr>
                                <Table.Th>{t`Email`}</Table.Th>
                                <Table.Th>{t`Reason`}</Table.Th>
                                <Table.Th>{t`Type`}</Table.Th>
                                <Table.Th>{t`Source`}</Table.Th>
                                <Table.Th>{t`Date`}</Table.Th>
                                <Table.Th/>
                            </Table.Tr>
                        </Table.Thead>
                        <Table.Tbody>
                            {suppressions.map((s) => (
                                <Table.Tr key={s.id}>
                                    <Table.Td>{s.email}</Table.Td>
                                    <Table.Td>
                                        <Badge color={reasonColor(s.reason)} variant="light">{s.reason}</Badge>
                                    </Table.Td>
                                    <Table.Td>
                                        <Text size="sm" c="dimmed">{s.bounce_type || s.complaint_type || '—'}</Text>
                                    </Table.Td>
                                    <Table.Td>
                                        <Text size="sm" c="dimmed">{s.source === 'ses_notification' ? t`SES` : t`Manual`}</Text>
                                    </Table.Td>
                                    <Table.Td>
                                        <Text size="sm">{s.created_at ? relativeDate(s.created_at) : '—'}</Text>
                                    </Table.Td>
                                    <Table.Td>
                                        {/* Only this account's own rows are removable here; platform-wide
                                            (null account_id) rows are managed by superadmin. */}
                                        {s.account_id != null && (
                                            <Tooltip label={t`Remove suppression`}>
                                                <ActionIcon
                                                    variant="subtle"
                                                    color="red"
                                                    onClick={() => handleDelete(s.id)}
                                                    loading={deleteMutation.isPending}
                                                    aria-label={t`Remove suppression`}
                                                >
                                                    <IconTrash size={16}/>
                                                </ActionIcon>
                                            </Tooltip>
                                        )}
                                    </Table.Td>
                                </Table.Tr>
                            ))}
                        </Table.Tbody>
                    </Table>

                    {pagination && Number(pagination.last_page) > 1 && (
                        <Pagination value={page} onChange={setPage} total={Number(pagination.last_page)}/>
                    )}
                </>
            )}

            {createModalOpened && (
                <Modal opened onClose={closeCreateModal} title={t`Never email an address`}>
                    <form onSubmit={form.onSubmit(handleCreate)}>
                        <Stack gap="md">
                            <Text size="sm" c="dimmed">
                                {t`Adds a do-not-contact rule for this account. The address will receive no marketing or transactional email.`}
                            </Text>
                            <TextInput
                                label={t`Email Address`}
                                placeholder="user@example.com"
                                required
                                {...form.getInputProps('email')}
                            />
                            <Button type="submit" loading={createMutation.isPending}>
                                {t`Add`}
                            </Button>
                        </Stack>
                    </form>
                </Modal>
            )}
        </Card>
    );
};
