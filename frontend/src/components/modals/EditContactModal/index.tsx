import {useEffect, useState} from "react";
import {useForm} from "@mantine/form";
import {Contact, GenericModalProps} from "../../../types.ts";
import {Modal} from "../../common/Modal";
import {Button, Divider, Group, MultiSelect, Select, Tabs, TextInput} from "@mantine/core";
import {IconForms, IconHistory} from "@tabler/icons-react";
import {useUpdateContact} from "../../../mutations/useUpdateContact.ts";
import {useFormErrorResponseHandler} from "../../../hooks/useFormErrorResponseHandler.tsx";
import {showSuccess} from "../../../utilites/notifications.tsx";
import {t} from "@lingui/macro";
import {useGetContactAttributeDefinitions} from "../../../queries/useGetContactAttributeDefinitions.ts";
import {AttributeHistoryPanel} from "./AttributeHistoryPanel";
import classes from "./EditContactModal.module.scss";

interface EditContactModalProps extends GenericModalProps {
    contact: Contact;
}

export const EditContactModal = ({contact, onClose}: EditContactModalProps) => {
    const updateMutation = useUpdateContact();
    const formErrorHandler = useFormErrorResponseHandler();
    const {data: definitionsData} = useGetContactAttributeDefinitions();
    const activeDefinitions = definitionsData?.data?.filter(d => d.is_active) ?? [];
    // Guard against legacy double-encoded history values (rare prod data) that
    // arrive as a string — accessing .length on a string returns its char
    // count and shows a bogus History (N) badge.
    const historyCount = Array.isArray(contact.attributes_history) ? contact.attributes_history.length : 0;
    const [activeTab, setActiveTab] = useState<string | null>('details');

    const form = useForm<{
        first_name: string;
        last_name: string;
        attributes: Record<string, string | string[]>;
    }>({
        initialValues: {
            first_name: contact.first_name || '',
            last_name: contact.last_name || '',
            attributes: {},
        },
    });

    useEffect(() => {
        if (!activeDefinitions.length) return;
        const attrs: Record<string, string | string[]> = {};
        activeDefinitions.forEach(def => {
            const existing = contact.attributes?.[def.name];
            attrs[def.name] = def.type === 'multi_select'
                ? Array.isArray(existing) ? (existing as string[]) : []
                : (typeof existing === 'string' ? existing : '');
        });
        form.setFieldValue('attributes', attrs);
    }, [definitionsData]);

    const handleSubmit = () => {
        form.validate();
        if (form.isValid()) {
            updateMutation.mutate({
                contactId: contact.id,
                contactData: form.values,
            }, {
                onSuccess: () => {
                    showSuccess(t`Contact updated successfully`);
                    onClose();
                },
                onError: (error) => formErrorHandler(form, error),
            });
        }
    };

    return (
        <Modal heading={t`Edit Contact`} onClose={onClose} opened>
            <Tabs value={activeTab} onChange={setActiveTab}>
                <Tabs.List mb="md">
                    <Tabs.Tab value="details" leftSection={<IconForms size={14}/>}>
                        {t`Details`}
                    </Tabs.Tab>
                    <Tabs.Tab value="history" leftSection={<IconHistory size={14}/>}>
                        {historyCount > 0 ? t`History (${historyCount})` : t`History`}
                    </Tabs.Tab>
                </Tabs.List>

                <Tabs.Panel value="details">
                    <TextInput
                        label={t`Email`}
                        value={contact.email}
                        disabled
                        mb="sm"
                    />
                    <TextInput
                        label={t`First Name`}
                        placeholder={t`First Name`}
                        {...form.getInputProps('first_name')}
                        mb="sm"
                    />
                    <TextInput
                        label={t`Last Name`}
                        placeholder={t`Last Name`}
                        {...form.getInputProps('last_name')}
                        mb="md"
                    />
                    {activeDefinitions.length > 0 && (
                        <>
                            <Divider label={t`Attributes`} labelPosition="left" mb="sm"/>
                            <div className={classes.attributesGrid}>
                                {activeDefinitions.map(def => {
                                    const fieldPath = `attributes.${def.name}`;
                                    const opts = def.options ?? [];
                                    const value = form.values.attributes[def.name];

                                    if (def.type === 'select') {
                                        // If the stored value isn't in the current option list (option
                                        // list edited since, typo from an import, etc.) inject it as a
                                        // disabled-looking row so Mantine selects it instead of rendering
                                        // empty — otherwise the admin can't see what's stored and would
                                        // silently overwrite it on save.
                                        const isStale = typeof value === 'string'
                                            && value !== ''
                                            && !opts.includes(value);
                                        const data = isStale
                                            ? [
                                                {value, label: t`${value} (not in current options)`},
                                                ...opts.map(o => ({value: o, label: o})),
                                            ]
                                            : opts;
                                        // form.getInputProps spreads an `error` key (undefined when
                                        // there's no form-level validation error), so the stale-value
                                        // error MUST go AFTER the spread or it gets clobbered to undefined.
                                        return (
                                            <Select
                                                key={def.name}
                                                label={def.label}
                                                data={data}
                                                clearable
                                                {...form.getInputProps(fieldPath)}
                                                error={isStale
                                                    ? t`Stored value isn't in the current option list. Pick a valid option to replace it.`
                                                    : undefined}
                                            />
                                        );
                                    }
                                    if (def.type === 'multi_select') {
                                        const currentValues = Array.isArray(value) ? value : [];
                                        const staleValues = currentValues.filter(v => !opts.includes(v));
                                        const data = staleValues.length > 0
                                            ? [
                                                ...staleValues.map(v => ({value: v, label: t`${v} (not in current options)`})),
                                                ...opts.map(o => ({value: o, label: o})),
                                            ]
                                            : opts;
                                        return (
                                            <MultiSelect
                                                key={def.name}
                                                label={def.label}
                                                data={data}
                                                {...form.getInputProps(fieldPath)}
                                                error={staleValues.length > 0
                                                    ? t`Some stored values aren't in the current option list. Replace them with valid options.`
                                                    : undefined}
                                            />
                                        );
                                    }
                                    return (
                                        <TextInput
                                            key={def.name}
                                            label={def.label}
                                            {...form.getInputProps(fieldPath)}
                                        />
                                    );
                                })}
                            </div>
                        </>
                    )}
                    <Group justify="flex-end" mt="xl" mb="md">
                        <Button variant="default" onClick={onClose} disabled={updateMutation.isPending}>
                            {t`Cancel`}
                        </Button>
                        <Button loading={updateMutation.isPending} onClick={handleSubmit}>
                            {updateMutation.isPending ? t`Working...` : t`Update Contact`}
                        </Button>
                    </Group>
                </Tabs.Panel>

                <Tabs.Panel value="history">
                    <AttributeHistoryPanel contact={contact}/>
                    <Group justify="flex-end" mt="xl" mb="md">
                        <Button variant="default" onClick={onClose}>
                            {t`Close`}
                        </Button>
                    </Group>
                </Tabs.Panel>
            </Tabs>
        </Modal>
    );
};
