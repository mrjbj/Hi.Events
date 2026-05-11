import {t} from "@lingui/macro";
import {Button, Group, Modal, TextInput} from "@mantine/core";
import {useForm} from "@mantine/form";
import {useEffect} from "react";
import {Attendee} from "../../../types.ts";
import {usePatchCheckInListAttendee} from "../../../mutations/usePatchCheckInListAttendee.ts";
import {showError, showSuccess} from "../../../utilites/notifications.tsx";
import {useFormErrorResponseHandler} from "../../../hooks/useFormErrorResponseHandler.tsx";

interface EditAttendeeCheckInModalProps {
    opened: boolean;
    attendee: Attendee | null;
    checkInListShortId: string;
    onClose: () => void;
}

export const EditAttendeeCheckInModal = ({
                                             opened,
                                             attendee,
                                             checkInListShortId,
                                             onClose,
                                         }: EditAttendeeCheckInModalProps) => {
    const mutation = usePatchCheckInListAttendee({checkInListShortId});
    const formErrorHandler = useFormErrorResponseHandler();

    const form = useForm({
        initialValues: {
            first_name: '',
            last_name: '',
            email: '',
        },
        validate: {
            email: (value) => {
                if (!value) return null;
                return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value) ? null : t`Invalid email`;
            },
        },
    });

    useEffect(() => {
        if (attendee) {
            form.setValues({
                first_name: attendee.first_name ?? '',
                last_name: attendee.last_name ?? '',
                email: attendee.email ?? '',
            });
            form.resetDirty();
        }
    }, [attendee?.public_id]);

    const handleSave = form.onSubmit((values) => {
        if (!attendee) return;

        const payload: Record<string, string> = {};
        if (values.first_name.trim() && values.first_name !== attendee.first_name) {
            payload.first_name = values.first_name.trim();
        }
        if (values.last_name.trim() && values.last_name !== attendee.last_name) {
            payload.last_name = values.last_name.trim();
        }
        if (values.email.trim() && values.email !== attendee.email) {
            payload.email = values.email.trim();
        }

        if (Object.keys(payload).length === 0) {
            showError(t`No changes to save`);
            return;
        }

        mutation.mutate(
            {attendeePublicId: attendee.public_id, payload},
            {
                onSuccess: () => {
                    showSuccess(t`Attendee details updated`);
                    onClose();
                },
                onError: (error) => formErrorHandler(form, error),
            },
        );
    });

    return (
        <Modal
            opened={opened}
            onClose={onClose}
            title={t`Edit attendee details`}
            size="md"
        >
            <form onSubmit={handleSave}>
                <TextInput
                    label={t`First name`}
                    placeholder={t`Enter first name`}
                    {...form.getInputProps('first_name')}
                />
                <TextInput
                    label={t`Last name`}
                    placeholder={t`Enter last name`}
                    mt="sm"
                    {...form.getInputProps('last_name')}
                />
                <TextInput
                    label={t`Email`}
                    placeholder={t`Enter email`}
                    type="email"
                    mt="sm"
                    {...form.getInputProps('email')}
                />
                <Group justify="flex-end" gap="sm" mt="md">
                    <Button variant="default" onClick={onClose} disabled={mutation.isPending}>
                        {t`Cancel`}
                    </Button>
                    <Button type="submit" loading={mutation.isPending}>
                        {t`Save`}
                    </Button>
                </Group>
            </form>
        </Modal>
    );
};
