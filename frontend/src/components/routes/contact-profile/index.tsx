import {t, Trans} from "@lingui/macro";
import {useSearchParams} from "react-router";
import {useMutation, useQuery} from "@tanstack/react-query";
import {Button, Group, Stack, Text, TextInput} from "@mantine/core";
import {IconCheck} from "@tabler/icons-react";
import {useForm} from "@mantine/form";
import {useEffect} from "react";

import {contactPortalClientPublic, MyContactResult} from "../../../api/contact-portal.client.ts";
import {Card} from "../../common/Card";
import {LoadingMask} from "../../common/LoadingMask";
import {CheckoutContent} from "../../layouts/Checkout/CheckoutContent";
import {PoweredByFooter} from "../../common/PoweredByFooter";
import {showError, showSuccess} from "../../../utilites/notifications.tsx";

interface ProfileFormValues {
    first_name: string;
    last_name: string;
    attributes: Record<string, string>;
}

const toAttributeStrings = (attrs: Record<string, unknown>): Record<string, string> => {
    const out: Record<string, string> = {};
    for (const [name, value] of Object.entries(attrs || {})) {
        if (value === null || value === undefined) {
            out[name] = '';
        } else if (Array.isArray(value)) {
            out[name] = value.join(', ');
        } else if (typeof value === 'object') {
            out[name] = JSON.stringify(value);
        } else {
            out[name] = String(value);
        }
    }
    return out;
};

const ContactProfile = () => {
    const [searchParams] = useSearchParams();
    const token = searchParams.get('c') ?? '';

    const {data, isFetched, isError, error} = useQuery<MyContactResult>({
        queryKey: ['my-contact', token],
        queryFn: () => contactPortalClientPublic.getMyContact(token),
        enabled: !!token,
        retry: false,
    });

    const form = useForm<ProfileFormValues>({
        initialValues: {
            first_name: '',
            last_name: '',
            attributes: {},
        },
    });

    useEffect(() => {
        if (!data?.found) return;
        form.setValues({
            first_name: data.first_name ?? '',
            last_name: data.last_name ?? '',
            attributes: toAttributeStrings(data.attributes ?? {}),
        });
    }, [data?.found, data?.first_name, data?.last_name]);

    const mutation = useMutation({
        mutationFn: (values: ProfileFormValues) => contactPortalClientPublic.updateMyContact({
            token,
            first_name: values.first_name,
            last_name: values.last_name,
            attributes: values.attributes,
        }),
        onSuccess: () => {
            showSuccess(t`Profile updated.`);
        },
        onError: () => {
            showError(t`We couldn't save your changes. Please try again.`);
        },
    });

    if (!token) {
        return (
            <CheckoutContent>
                <Card>
                    <Text size="lg" fw={600}>{t`Missing link`}</Text>
                    <Text c="dimmed" mt="sm">
                        <Trans>
                            This page can only be opened from the link in one of our emails.
                        </Trans>
                    </Text>
                </Card>
                <PoweredByFooter/>
            </CheckoutContent>
        );
    }

    if (!isFetched) {
        return <LoadingMask/>;
    }

    if (isError || !data?.found) {
        return (
            <CheckoutContent>
                <Card>
                    <Text size="lg" fw={600}>{t`Invalid or expired link`}</Text>
                    <Text c="dimmed" mt="sm">
                        <Trans>
                            The link you used is no longer valid. Open the most recent email we
                            sent you and click the profile link there to refresh it.
                        </Trans>
                    </Text>
                    {error && <Text size="xs" c="red" mt="sm">{(error as Error).message}</Text>}
                </Card>
                <PoweredByFooter/>
            </CheckoutContent>
        );
    }

    return (
        <CheckoutContent>
            <Card>
                <Stack gap="md">
                    <div>
                        <Text size="lg" fw={600}>{t`Your profile`}</Text>
                        <Text c="dimmed" size="sm">
                            <Trans>
                                Update your details once here and they'll pre-fill the next time
                                you register for one of our events.
                            </Trans>
                        </Text>
                    </div>

                    <form onSubmit={form.onSubmit((values) => mutation.mutate(values))}>
                        <Stack gap="sm">
                            <Group grow>
                                <TextInput
                                    label={t`First name`}
                                    {...form.getInputProps('first_name')}
                                />
                                <TextInput
                                    label={t`Last name`}
                                    {...form.getInputProps('last_name')}
                                />
                            </Group>

                            {data.attribute_definitions?.map((def) => (
                                <TextInput
                                    key={def.id}
                                    label={def.name}
                                    {...form.getInputProps(`attributes.${def.name}`)}
                                />
                            ))}

                            <Group justify="flex-end" mt="sm">
                                <Button
                                    type="submit"
                                    loading={mutation.isPending}
                                    leftSection={<IconCheck size={16}/>}
                                >
                                    {t`Save`}
                                </Button>
                            </Group>
                        </Stack>
                    </form>
                </Stack>
            </Card>
            <PoweredByFooter/>
        </CheckoutContent>
    );
};

export default ContactProfile;
