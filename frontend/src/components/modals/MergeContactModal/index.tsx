import {useState} from "react";
import {t, Trans} from "@lingui/macro";
import {Alert, Button, Group, Stack, Text, TextInput, UnstyledButton} from "@mantine/core";
import {IconAlertTriangle, IconSearch} from "@tabler/icons-react";
import {Contact, GenericModalProps, QueryFilters} from "../../../types.ts";
import {Modal} from "../../common/Modal";
import {useGetContacts} from "../../../queries/useGetContacts.ts";
import {useMergeContacts} from "../../../mutations/useMergeContacts.ts";
import {showError, showSuccess} from "../../../utilites/notifications.tsx";

interface MergeContactModalProps extends GenericModalProps {
    survivor: Contact;
}

const displayName = (c: Contact) => [c.first_name, c.last_name].filter(Boolean).join(' ').trim() || c.email;

const isEmpty = (v: unknown) => v == null || v === '' || (Array.isArray(v) && v.length === 0);
const displayVal = (v: unknown) => Array.isArray(v) ? v.join(', ') : (v == null ? '' : String(v));

/** Survivor-wins / fill-gaps: list the survivor fields the duplicate will fill. */
const computeGapFills = (survivor: Contact, source: Contact): {label: string; value: string}[] => {
    const fills: {label: string; value: string}[] = [];
    if (isEmpty(survivor.first_name) && !isEmpty(source.first_name)) {
        fills.push({label: t`First name`, value: displayVal(source.first_name)});
    }
    if (isEmpty(survivor.last_name) && !isEmpty(source.last_name)) {
        fills.push({label: t`Last name`, value: displayVal(source.last_name)});
    }
    const sAttrs = survivor.attributes ?? {};
    const dAttrs = source.attributes ?? {};
    Object.entries(dAttrs).forEach(([key, value]) => {
        if (isEmpty(sAttrs[key]) && !isEmpty(value)) {
            fills.push({label: key, value: displayVal(value)});
        }
    });
    return fills;
};

export const MergeContactModal = ({survivor, onClose}: MergeContactModalProps) => {
    const [query, setQuery] = useState('');
    const [source, setSource] = useState<Contact | null>(null);
    const mergeMutation = useMergeContacts();

    const searchParams: QueryFilters = {pageNumber: 1, perPage: 10, query: query || undefined};
    const {data} = useGetContacts(searchParams);
    const candidates = (data?.data ?? []).filter((c) => c.id !== survivor.id);
    const gapFills = source ? computeGapFills(survivor, source) : [];

    const handleMerge = () => {
        if (!source) return;
        mergeMutation.mutate({survivorId: survivor.id, sourceContactId: source.id}, {
            onSuccess: () => {
                showSuccess(t`Contacts merged`);
                onClose();
            },
            onError: () => showError(t`Couldn't merge these contacts`),
        });
    };

    return (
        <Modal heading={t`Merge contacts`} onClose={onClose} opened>
            <Stack gap="sm">
                <Text size="sm">
                    <Trans>Keep <b>{displayName(survivor)}</b> ({survivor.email}) and fold a duplicate into it.</Trans>
                </Text>

                {!source ? (
                    <>
                        <TextInput
                            placeholder={t`Search the duplicate by name or email...`}
                            leftSection={<IconSearch size={16}/>}
                            value={query}
                            onChange={(e) => setQuery(e.currentTarget.value)}
                        />
                        <Stack gap={4}>
                            {candidates.map((c) => (
                                <UnstyledButton
                                    key={c.id}
                                    onClick={() => setSource(c)}
                                    style={{padding: 8, borderRadius: 6, border: '1px solid var(--mantine-color-gray-3)'}}
                                >
                                    <Text size="sm" fw={500}>{displayName(c)}</Text>
                                    <Text size="xs" c="dimmed">{c.email}</Text>
                                </UnstyledButton>
                            ))}
                            {!!query && candidates.length === 0 && (
                                <Text size="sm" c="dimmed" ta="center" py="sm">{t`No matching contacts.`}</Text>
                            )}
                        </Stack>
                    </>
                ) : (
                    <Stack gap="sm">
                        <Text size="sm">
                            <Trans>
                                Keep <b>{displayName(survivor)}</b> (<b>{survivor.email}</b>) · remove <b>{displayName(source)}</b> ({source.email}).
                            </Trans>
                        </Text>

                        <div style={{padding: 10, borderRadius: 6, background: 'var(--mantine-color-gray-0)'}}>
                            <Text size="xs" fw={700} c="dimmed" mb={4} tt="uppercase">{t`Fields filled on the survivor`}</Text>
                            {gapFills.length > 0 ? (
                                <Stack gap={2}>
                                    {gapFills.map((f) => (
                                        <Text key={f.label} size="sm"><b>{f.label}:</b> {f.value}</Text>
                                    ))}
                                </Stack>
                            ) : (
                                <Text size="sm" c="dimmed">{t`None — the survivor already has every field set, so its values are kept.`}</Text>
                            )}
                        </div>

                        <Alert color="orange" icon={<IconAlertTriangle size={16}/>} p="xs">
                            <Text size="xs">
                                {t`The duplicate's attendees (and the order history reached through them) move to the survivor, and its change history is merged in. The duplicate contact is then removed. This can't be undone.`}
                            </Text>
                        </Alert>
                    </Stack>
                )}

                <Group justify="space-between" mt="md">
                    {source ? (
                        <Button variant="subtle" color="gray" onClick={() => setSource(null)} disabled={mergeMutation.isPending}>
                            {t`Back`}
                        </Button>
                    ) : <span/>}
                    <Group>
                        <Button variant="default" onClick={onClose} disabled={mergeMutation.isPending}>
                            {t`Cancel`}
                        </Button>
                        <Button color="red" onClick={handleMerge} disabled={!source} loading={mergeMutation.isPending}>
                            {t`Merge & remove duplicate`}
                        </Button>
                    </Group>
                </Group>
            </Stack>
        </Modal>
    );
};
