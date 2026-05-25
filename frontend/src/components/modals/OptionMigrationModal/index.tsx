import {Button, Group, Radio, Select, Stack, Text, TextInput} from "@mantine/core";
import {Modal} from "../../common/Modal";
import {t, Trans} from "@lingui/macro";
import {useState} from "react";
import {OptionMigration} from "../../../api/contact-attribute-definition.client.ts";

interface OptionMigrationModalProps {
    optionBeingRemoved: string;
    usageCount: number;
    otherOptions: string[];
    onConfirm: (migration: OptionMigration) => void;
    onCancel: () => void;
}

export const OptionMigrationModal = ({
    optionBeingRemoved,
    usageCount,
    otherOptions,
    onConfirm,
    onCancel,
}: OptionMigrationModalProps) => {
    const [action, setAction] = useState<'rename' | 'delete'>('rename');
    const [renameMode, setRenameMode] = useState<'existing' | 'new'>(otherOptions.length > 0 ? 'existing' : 'new');
    const [existingTarget, setExistingTarget] = useState<string>(otherOptions[0] ?? '');
    const [newTarget, setNewTarget] = useState('');

    const renameValue = renameMode === 'existing' ? existingTarget : newTarget.trim();
    const canConfirm = action === 'delete' || (action === 'rename' && renameValue !== '' && renameValue !== optionBeingRemoved);

    const handleConfirm = () => {
        if (action === 'delete') {
            onConfirm({from: optionBeingRemoved, action: 'delete'});
        } else {
            onConfirm({from: optionBeingRemoved, action: 'rename', to: renameValue});
        }
    };

    return (
        <Modal opened onClose={onCancel} heading={t`Remove "${optionBeingRemoved}"?`}>
            <Stack gap="md">
                <Text size="sm">
                    {usageCount === 1
                        ? t`1 stored answer references this option.`
                        : t`${usageCount} stored answers reference this option.`}{' '}
                    {t`Choose what to do with them:`}
                </Text>

                <Radio.Group value={action} onChange={(v) => setAction(v as 'rename' | 'delete')}>
                    <Stack gap="xs">
                        <Radio
                            value="rename"
                            label={t`Move existing answers to another value`}
                            description={t`Replace "${optionBeingRemoved}" with a value you specify, in both event answers and contact profiles.`}
                        />
                        {action === 'rename' && (
                            <Stack gap="xs" pl="lg">
                                {otherOptions.length > 0 && (
                                    <Radio.Group value={renameMode} onChange={(v) => setRenameMode(v as 'existing' | 'new')}>
                                        <Group gap="md">
                                            <Radio value="existing" label={t`Use another existing option`}/>
                                            <Radio value="new" label={t`Type a new value`}/>
                                        </Group>
                                    </Radio.Group>
                                )}
                                {renameMode === 'existing' && otherOptions.length > 0 ? (
                                    <Select
                                        label={t`Replace with`}
                                        data={otherOptions.map((o) => ({value: o, label: o}))}
                                        value={existingTarget}
                                        onChange={(v) => setExistingTarget(v ?? '')}
                                        allowDeselect={false}
                                    />
                                ) : (
                                    <TextInput
                                        label={t`Replace with`}
                                        placeholder={t`e.g. Other`}
                                        value={newTarget}
                                        onChange={(e) => setNewTarget(e.currentTarget.value)}
                                    />
                                )}
                            </Stack>
                        )}

                        <Radio
                            value="delete"
                            label={t`Delete the answers entirely`}
                            description={t`Drop "${optionBeingRemoved}" from every answer and contact profile. For single-select answers this clears the field; for multi-select it removes just this value from the array.`}
                        />
                    </Stack>
                </Radio.Group>

                <Text size="xs" c="dimmed">
                    <Trans>
                        The change is applied when you click Save on the attribute. Click Cancel to keep the option for now.
                    </Trans>
                </Text>

                <Group justify="flex-end">
                    <Button variant="default" onClick={onCancel}>{t`Cancel`}</Button>
                    <Button color="red" onClick={handleConfirm} disabled={!canConfirm}>
                        {t`Stage removal`}
                    </Button>
                </Group>
            </Stack>
        </Modal>
    );
};
