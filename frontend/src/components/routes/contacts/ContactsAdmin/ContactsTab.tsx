import {t} from "@lingui/macro";
import {useMemo, useState} from "react";
import {ActionIcon, Alert, Button, Group, Select, Switch, Table, Text, TextInput, Tooltip, UnstyledButton} from "@mantine/core";
import {IconArrowMerge, IconPencil, IconSearch, IconSortAscending, IconSortDescending, IconTrash} from "@tabler/icons-react";
import {useDisclosure} from "@mantine/hooks";
import {Card} from "../../../common/Card";
import {Pagination} from "../../../common/Pagination";
import {TableSkeleton} from "../../../common/TableSkeleton";
import {SuppressionBadge} from "../../../common/SuppressionBadge";
import {Contact, EventLifecycleStatus, EventStatus, QueryFilterOperator, QueryFilters} from "../../../../types.ts";
import {useGetContacts} from "../../../../queries/useGetContacts.ts";
import {useEscapeClearsFilters} from "../../../../hooks/useEscapeClearsFilters.ts";
import {useIsCurrentUserAdmin} from "../../../../hooks/useIsCurrentUserAdmin.ts";
import {useGetEvents} from "../../../../queries/useGetEvents.ts";
import {useDeleteContact} from "../../../../mutations/useDeleteContact.ts";
import {showError, showSuccess} from "../../../../utilites/notifications.tsx";
import {confirmationDialog} from "../../../../utilites/confirmationDialog.tsx";
import {CreateContactModal} from "../../../modals/CreateContactModal";
import {EditContactModal} from "../../../modals/EditContactModal";
import {MergeContactModal} from "../../../modals/MergeContactModal";

interface SortableThProps {
    label: string;
    field: string;
    sortBy: string;
    sortDir: string;
    onSort: (field: string) => void;
}

const SortableTh = ({label, field, sortBy, sortDir, onSort}: SortableThProps) => {
    const isActive = sortBy === field;
    const Icon = isActive && sortDir === 'asc' ? IconSortAscending : IconSortDescending;
    return (
        <Table.Th>
            <UnstyledButton onClick={() => onSort(field)} style={{display: 'flex', alignItems: 'center', gap: 4, fontWeight: 700}}>
                {label}
                {isActive && <Icon size={14} style={{opacity: 0.6}}/>}
            </UnstyledButton>
        </Table.Th>
    );
};

export const ContactsTab = () => {
    const [page, setPage] = useState(1);
    const [query, setQuery] = useState('');
    const [eventFilter, setEventFilter] = useState<string | null>(null);
    const [includePastEvents, setIncludePastEvents] = useState(false);
    const [suppressionFilter, setSuppressionFilter] = useState<string | null>(null);
    const [sortBy, setSortBy] = useState('created_at');
    const [sortDir, setSortDir] = useState('desc');
    const [createModalOpen, {open: openCreateModal, close: closeCreateModal}] = useDisclosure(false);
    const [editModalOpen, {open: openEditModal, close: closeEditModal}] = useDisclosure(false);
    const [mergeModalOpen, {open: openMergeModal, close: closeMergeModal}] = useDisclosure(false);
    const [selectedContact, setSelectedContact] = useState<Contact>();

    const eventsQuery = useGetEvents({pageNumber: 1, perPage: 100});
    const eventOptions = useMemo(() => {
        const events = eventsQuery.data?.data ?? [];
        // "Active" = not yet ended and not archived; the toggle widens to all.
        const visible = includePastEvents
            ? events
            : events.filter((e: any) =>
                e.lifecycle_status !== EventLifecycleStatus.ENDED && e.status !== EventStatus.ARCHIVED);
        return visible.map((e: any) => ({value: String(e.id), label: e.title}));
    }, [eventsQuery.data, includePastEvents]);

    const handleSort = (field: string) => {
        if (sortBy === field) {
            setSortDir(sortDir === 'asc' ? 'desc' : 'asc');
        } else {
            setSortBy(field);
            setSortDir('asc');
        }
        setPage(1);
    };

    const filterFields: Record<string, any> = {};
    if (eventFilter) {
        filterFields.event_id = {operator: QueryFilterOperator.Equals, value: eventFilter};
    }
    if (suppressionFilter) {
        filterFields.suppression_status = {operator: QueryFilterOperator.Equals, value: suppressionFilter};
    }

    const searchParams: QueryFilters = {
        pageNumber: page,
        perPage: 20,
        query: query || undefined,
        filterFields: Object.keys(filterFields).length > 0 ? filterFields : undefined,
        sortBy,
        sortDirection: sortDir,
    };

    const contactsQuery = useGetContacts(searchParams);
    const contacts = contactsQuery.data?.data;
    const pagination = contactsQuery.data?.meta;
    const deleteMutation = useDeleteContact();
    // Organizers get a read-only browse view; create/edit/merge/delete are admin-only.
    const isAdmin = useIsCurrentUserAdmin();

    useEscapeClearsFilters({
        steps: [
            {isActive: () => query !== '', clear: () => { setQuery(''); setPage(1); }},
            {isActive: () => suppressionFilter !== null, clear: () => { setSuppressionFilter(null); setPage(1); }},
            {isActive: () => eventFilter !== null, clear: () => { setEventFilter(null); setPage(1); }},
            {isActive: () => includePastEvents, clear: () => setIncludePastEvents(false)},
        ],
    });

    const handleEdit = (contact: Contact) => {
        setSelectedContact(contact);
        openEditModal();
    };

    const handleMerge = (contact: Contact) => {
        setSelectedContact(contact);
        openMergeModal();
    };

    const handleDelete = (contact: Contact) => {
        const displayName = [contact.first_name, contact.last_name].filter(Boolean).join(' ').trim() || contact.email;
        confirmationDialog(
            t`Delete contact "${displayName}"? This cannot be undone.`,
            () => {
                deleteMutation.mutate({contactId: contact.id}, {
                    onSuccess: () => showSuccess(t`Contact deleted successfully`),
                    onError: () => showError(t`Something went wrong while deleting the contact`),
                });
            },
            {confirm: t`Delete`, cancel: t`Cancel`},
        );
    };

    return (
        <>
            <Card>
                <Group gap="sm" wrap="wrap" mb="md" align="center">
                    <TextInput
                        placeholder={t`Search by name or email...`}
                        leftSection={<IconSearch size={16}/>}
                        value={query}
                        onChange={(e) => { setQuery(e.currentTarget.value); setPage(1); }}
                        size="sm"
                        style={{flex: 1, minWidth: 220, marginBottom: 0}}
                    />
                    <Select
                        placeholder={t`Sends`}
                        data={[
                            {value: 'active', label: t`Always`},
                            {value: 'marketing_only', label: t`Transactional`},
                            {value: 'always', label: t`Never`},
                        ]}
                        value={suppressionFilter}
                        onChange={(val) => { setSuppressionFilter(val); setPage(1); }}
                        clearable
                        size="sm"
                        style={{width: 170, marginBottom: 0}}
                    />
                    <Select
                        placeholder={t`Filter by event`}
                        data={eventOptions}
                        value={eventFilter}
                        onChange={(val) => { setEventFilter(val); setPage(1); }}
                        clearable
                        searchable
                        size="sm"
                        style={{width: 240, marginBottom: 0}}
                    />
                    <Switch
                        label={t`Include past events`}
                        checked={includePastEvents}
                        onChange={(e) => { setIncludePastEvents(e.currentTarget.checked); setEventFilter(null); setPage(1); }}
                        size="sm"
                        h={36}
                        styles={{body: {height: '100%', alignItems: 'center'}}}
                    />
                    {isAdmin && (
                        <Button onClick={openCreateModal} size="sm">
                            {t`Add Contact`}
                        </Button>
                    )}
                </Group>

                {contactsQuery.isLoading && <TableSkeleton isVisible/>}

                {!!contactsQuery.error && (
                    <Alert color="red" radius="md">
                        {t`Failed to load contacts`}
                    </Alert>
                )}

                {!contactsQuery.isLoading && !contactsQuery.error && contacts && contacts.length === 0 && (
                    <Text c="dimmed" ta="center" py="xl">{t`No contacts found.`}</Text>
                )}

                {contacts && contacts.length > 0 && (
                    <>
                        <Table striped highlightOnHover>
                            <Table.Thead>
                                <Table.Tr>
                                    <SortableTh label={t`Email`} field="email" sortBy={sortBy} sortDir={sortDir} onSort={handleSort}/>
                                    <Table.Th>{t`Sends`}</Table.Th>
                                    <SortableTh label={t`First Name`} field="first_name" sortBy={sortBy} sortDir={sortDir} onSort={handleSort}/>
                                    <SortableTh label={t`Last Name`} field="last_name" sortBy={sortBy} sortDir={sortDir} onSort={handleSort}/>
                                    <SortableTh label={t`Created`} field="created_at" sortBy={sortBy} sortDir={sortDir} onSort={handleSort}/>
                                    {isAdmin && <Table.Th/>}
                                </Table.Tr>
                            </Table.Thead>
                            <Table.Tbody>
                                {contacts.map((contact) => (
                                    <Table.Tr key={contact.id}>
                                        <Table.Td>{contact.email}</Table.Td>
                                        <Table.Td>
                                            <SuppressionBadge status={contact.suppression_status} detail={contact.suppression_detail}/>
                                        </Table.Td>
                                        <Table.Td>{contact.first_name || '-'}</Table.Td>
                                        <Table.Td>{contact.last_name || '-'}</Table.Td>
                                        <Table.Td>{contact.created_at ? new Date(contact.created_at).toLocaleDateString() : '-'}</Table.Td>
                                        {isAdmin && (
                                            <Table.Td>
                                                <Group gap={4} wrap="nowrap">
                                                    <Tooltip label={t`Edit`}>
                                                        <ActionIcon
                                                            variant="subtle"
                                                            onClick={() => handleEdit(contact)}
                                                            aria-label={t`Edit contact`}
                                                        >
                                                            <IconPencil size={16}/>
                                                        </ActionIcon>
                                                    </Tooltip>
                                                    <Tooltip label={t`Merge duplicate`}>
                                                        <ActionIcon
                                                            variant="subtle"
                                                            onClick={() => handleMerge(contact)}
                                                            aria-label={t`Merge duplicate into this contact`}
                                                        >
                                                            <IconArrowMerge size={16}/>
                                                        </ActionIcon>
                                                    </Tooltip>
                                                    <Tooltip label={t`Delete`}>
                                                        <ActionIcon
                                                            variant="subtle"
                                                            color="red"
                                                            onClick={() => handleDelete(contact)}
                                                            aria-label={t`Delete contact`}
                                                        >
                                                            <IconTrash size={16}/>
                                                        </ActionIcon>
                                                    </Tooltip>
                                                </Group>
                                            </Table.Td>
                                        )}
                                    </Table.Tr>
                                ))}
                            </Table.Tbody>
                        </Table>

                        {pagination && Number(pagination.last_page) > 1 && (
                            <Pagination
                                value={page}
                                onChange={setPage}
                                total={Number(pagination.last_page)}
                            />
                        )}
                    </>
                )}
            </Card>

            {createModalOpen && <CreateContactModal onClose={closeCreateModal}/>}
            {editModalOpen && selectedContact && <EditContactModal contact={selectedContact} onClose={closeEditModal}/>}
            {mergeModalOpen && selectedContact && <MergeContactModal survivor={selectedContact} onClose={closeMergeModal}/>}
        </>
    );
};
