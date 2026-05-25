import {useForm} from "@mantine/form";
import {ContactAttributeDefinition, GenericModalProps} from "../../../types.ts";
import {Modal} from "../../common/Modal";
import {Button, Group, Select, Stack, Switch, Text, TextInput} from "@mantine/core";
import {useUpdateContactAttributeDefinition} from "../../../mutations/useUpdateContactAttributeDefinition.ts";
import {useFormErrorResponseHandler} from "../../../hooks/useFormErrorResponseHandler.tsx";
import {showSuccess} from "../../../utilites/notifications.tsx";
import {t} from "@lingui/macro";
import {SortableOptionsInput} from "../../common/SortableOptionsInput";
import {useGetMe} from "../../../queries/useGetMe.ts";
import {useGetContactAttributeOptionUsage} from "../../../queries/useGetContactAttributeOptionUsage.ts";
import {useState} from "react";
import {OptionMigration} from "../../../api/contact-attribute-definition.client.ts";
import {OptionMigrationModal} from "../OptionMigrationModal";

interface EditContactAttributeDefinitionModalProps extends GenericModalProps {
    definition: ContactAttributeDefinition;
}

interface PendingMigrationPrompt {
    value: string;
    usageCount: number;
    resolve: (proceed: boolean) => void;
}

export const EditContactAttributeDefinitionModal = ({definition, onClose}: EditContactAttributeDefinitionModalProps) => {
    const updateMutation = useUpdateContactAttributeDefinition();
    const formErrorHandler = useFormErrorResponseHandler();
    const {data: me} = useGetMe();
    const usageQuery = useGetContactAttributeOptionUsage(me?.account_id, definition.id ?? null);
    const usageCounts = usageQuery.data?.data ?? {};

    const form = useForm<Partial<ContactAttributeDefinition>>({
        initialValues: {
            name: definition.name,
            label: definition.label,
            type: definition.type,
            options: definition.options || [],
            sort_order: definition.sort_order,
            is_active: definition.is_active,
            is_globally_recommended: definition.is_globally_recommended ?? false,
        },
        validate: {
            name: (value) => (!value ? t`Name is required` : null),
            label: (value) => (!value ? t`Label is required` : null),
        },
    });

    const [stagedMigrations, setStagedMigrations] = useState<OptionMigration[]>([]);
    const [pendingPrompt, setPendingPrompt] = useState<PendingMigrationPrompt | null>(null);

    const handleSubmit = () => {
        form.validate();
        if (form.isValid()) {
            const payload: any = {...form.values};
            if (stagedMigrations.length > 0) {
                payload.option_migrations = stagedMigrations;
            }
            updateMutation.mutate({
                definitionId: definition.id,
                definitionData: payload,
            }, {
                onSuccess: () => {
                    showSuccess(t`Attribute definition updated successfully`);
                    onClose();
                },
                onError: (error) => formErrorHandler(form, error),
            });
        }
    };

    const showOptions = form.values.type === 'select' || form.values.type === 'multi_select';

    const handleRemoveInUse = (value: string, count: number): Promise<boolean> => {
        return new Promise<boolean>((resolve) => {
            setPendingPrompt({value, usageCount: count, resolve});
        });
    };

    const otherOptionsForPrompt = (pendingPrompt
        ? (form.values.options ?? []).filter((o) => o !== pendingPrompt.value)
        : []);

    return (
        <Modal heading={t`Edit Attribute Definition`} onClose={onClose} opened>
            <TextInput
                label={t`Label`}
                placeholder={t`e.g., Role`}
                required
                {...form.getInputProps('label')}
                mb="sm"
            />
            <TextInput
                label={t`Name`}
                placeholder={t`e.g., role`}
                required
                {...form.getInputProps('name')}
                mb="sm"
            />
            <Select
                label={t`Type`}
                description={t`Determines the underlying data shape. Linked event questions can use any compatible widget: 'Text' supports single/multi-line text, date, address, phone. 'Single Select' supports radio buttons and dropdowns. 'Multi Select' supports checkboxes and multi-select dropdowns.`}
                data={[
                    {value: 'text', label: t`Text`},
                    {value: 'select', label: t`Single Select`},
                    {value: 'multi_select', label: t`Multi Select`},
                ]}
                {...form.getInputProps('type')}
                mb="sm"
            />
            {showOptions && (
                <Stack gap={6} mb="sm">
                    <SortableOptionsInput
                        label={t`Options`}
                        value={form.values.options ?? []}
                        onChange={(value) => form.setFieldValue('options', value)}
                        usageCounts={usageCounts}
                        onRemoveInUse={handleRemoveInUse}
                    />
                    {stagedMigrations.length > 0 && (
                        <Text size="xs" c="orange.7">
                            {t`${stagedMigrations.length} pending migration(s):`}{' '}
                            {stagedMigrations.map((m) =>
                                m.action === 'delete'
                                    ? t`delete "${m.from}"`
                                    : t`rename "${m.from}" → "${m.to ?? ''}"`,
                            ).join(', ')}
                        </Text>
                    )}
                </Stack>
            )}
            <Switch
                label={t`Active`}
                checked={form.values.is_active}
                onChange={(event) => form.setFieldValue('is_active', event.currentTarget.checked)}
                mb="sm"
            />
            <Switch
                label={t`Globally recommended`}
                description={t`When enabled, this attribute is auto-attached as an order-level question on newly created events.`}
                checked={form.values.is_globally_recommended ?? false}
                onChange={(event) => form.setFieldValue('is_globally_recommended', event.currentTarget.checked)}
                mb="md"
            />
            <Group justify="flex-end" mt="xl" mb="md">
                <Button variant="default" onClick={onClose} disabled={updateMutation.isPending}>
                    {t`Cancel`}
                </Button>
                <Button loading={updateMutation.isPending} onClick={handleSubmit}>
                    {updateMutation.isPending ? t`Working...` : t`Update Attribute`}
                </Button>
            </Group>

            {pendingPrompt && (
                <OptionMigrationModal
                    optionBeingRemoved={pendingPrompt.value}
                    usageCount={pendingPrompt.usageCount}
                    otherOptions={otherOptionsForPrompt}
                    onConfirm={(migration) => {
                        setStagedMigrations((prev) => [
                            ...prev.filter((m) => m.from !== migration.from),
                            migration,
                        ]);

                        // Drive the options list update atomically here (instead of letting
                        // SortableOptionsInput auto-remove + then us racing to add). This avoids
                        // losing data when the user renames to a value not already in the list.
                        const currentOptions = form.values.options ?? [];
                        const withoutRemoved = currentOptions.filter((o) => o !== pendingPrompt.value);
                        const finalOptions = (migration.action === 'rename' && migration.to && !withoutRemoved.includes(migration.to))
                            ? [...withoutRemoved, migration.to]
                            : withoutRemoved;
                        form.setFieldValue('options', finalOptions);

                        // Tell SortableOptionsInput NOT to also remove — we've already done it.
                        pendingPrompt.resolve(false);
                        setPendingPrompt(null);
                    }}
                    onCancel={() => {
                        pendingPrompt.resolve(false);
                        setPendingPrompt(null);
                    }}
                />
            )}
        </Modal>
    );
};
