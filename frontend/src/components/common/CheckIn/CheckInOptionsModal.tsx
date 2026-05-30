import {Alert, Button, Group, Modal, NumberInput, Select, Stack, Text, TextInput} from "@mantine/core";
import {IconAlertCircle, IconCreditCard, IconUserCheck} from "@tabler/icons-react";
import {t, Trans} from "@lingui/macro";
import {useEffect, useState} from "react";
import {Attendee} from "../../../types.ts";
import {formatCurrency} from "../../../utilites/currency.ts";

export type OfflinePaymentMethod = 'CASH' | 'CHECK' | 'CREDIT_CARD' | 'BANK_TRANSFER' | 'OTHER';

export interface MarkAsPaidPayload {
    payment_method: OfflinePaymentMethod;
    payment_reference?: string | null;
    amount?: number | null;
}

interface CheckInOptionsModalProps {
    isOpen: boolean;
    attendee: Attendee | null;
    isPending: boolean;
    onClose: () => void;
    onCheckIn: (action: 'check-in') => void;
    onCheckInAndMarkAsPaid: (payload: MarkAsPaidPayload) => void;
}

export const CheckInOptionsModal = ({
    isOpen,
    attendee,
    isPending,
    onClose,
    onCheckIn,
    onCheckInAndMarkAsPaid,
}: CheckInOptionsModalProps) => {
    const [showPaymentForm, setShowPaymentForm] = useState(false);
    const [method, setMethod] = useState<OfflinePaymentMethod>('CASH');
    const [reference, setReference] = useState('');
    const owed = attendee?.order_total_gross ?? 0;
    const currency = attendee?.order_currency ?? 'USD';
    const [amount, setAmount] = useState<number | ''>(owed);

    // Default the amount-received field to the full order total whenever the
    // modal is (re)opened for an attendee — the agent overrides with the cash
    // actually collected (which may be more, e.g. no change given, or less).
    useEffect(() => {
        setAmount(owed);
    }, [attendee?.public_id, owed]);

    if (!attendee) return null;

    const reset = () => {
        setShowPaymentForm(false);
        setMethod('CASH');
        setReference('');
        setAmount(owed);
    };

    const handleClose = () => {
        reset();
        onClose();
    };

    const submitPaid = () => {
        onCheckInAndMarkAsPaid({
            payment_method: method,
            payment_reference: reference.trim() === '' ? null : reference.trim(),
            amount: amount === '' ? null : Number(amount),
        });
    };

    return (
        <Modal
            opened={isOpen}
            onClose={handleClose}
            title={<Trans>Check in {attendee.first_name} {attendee.last_name}</Trans>}
            size="md"
        >
            <Stack>
                <Alert
                    icon={<IconAlertCircle size={20}/>}
                    variant={'light'}
                    title={t`Unpaid Order`}>
                    {t`This attendee has an unpaid order.`}
                </Alert>

                {!showPaymentForm ? (
                    <>
                        <Button
                            leftSection={<IconUserCheck size={20}/>}
                            onClick={() => onCheckIn('check-in')}
                            loading={isPending}
                            fullWidth
                        >
                            {t`Check in only`}
                        </Button>
                        <Button
                            leftSection={<IconCreditCard size={20}/>}
                            onClick={() => setShowPaymentForm(true)}
                            disabled={isPending}
                            variant="filled"
                            fullWidth
                        >
                            {t`Check in and record payment`}
                        </Button>
                        <Button
                            onClick={handleClose}
                            variant="light"
                            fullWidth
                        >
                            {t`Cancel`}
                        </Button>
                    </>
                ) : (
                    <>
                        <Group justify="space-between" wrap="nowrap">
                            <Text size="sm" c="dimmed">{t`Order total`}</Text>
                            <Text size="sm" fw={600}>{formatCurrency(owed, currency)}</Text>
                        </Group>
                        <Select
                            label={t`Payment method`}
                            required
                            data={[
                                {value: 'CASH', label: t`Cash`},
                                {value: 'CHECK', label: t`Check`},
                                {value: 'CREDIT_CARD', label: t`Credit card`},
                                {value: 'BANK_TRANSFER', label: t`Bank transfer`},
                                {value: 'OTHER', label: t`Other`},
                            ]}
                            value={method}
                            onChange={(val) => val && setMethod(val as OfflinePaymentMethod)}
                            allowDeselect={false}
                        />
                        <NumberInput
                            label={<Trans>Amount received ({currency})</Trans>}
                            description={t`Enter the amount actually collected. Less than the total leaves a balance due; more is recorded as overpaid.`}
                            min={0}
                            decimalScale={2}
                            fixedDecimalScale
                            value={amount}
                            onChange={(val) => setAmount(val === '' ? '' : Number(val))}
                        />
                        <TextInput
                            label={t`Reference (optional)`}
                            placeholder={t`e.g. collected by J. Doe, Square #1234`}
                            value={reference}
                            onChange={(e) => setReference(e.currentTarget.value)}
                            maxLength={255}
                        />
                        <Button
                            onClick={submitPaid}
                            loading={isPending}
                            disabled={amount === '' || Number(amount) <= 0}
                            variant="filled"
                            fullWidth
                        >
                            {amount !== '' && Number(amount) < owed
                                ? <Trans>Check in and record {formatCurrency(Number(amount), currency)} payment</Trans>
                                : t`Check in and record payment`}
                        </Button>
                        <Button
                            onClick={() => setShowPaymentForm(false)}
                            variant="subtle"
                            disabled={isPending}
                            fullWidth
                        >
                            {t`Back`}
                        </Button>
                    </>
                )}
            </Stack>
        </Modal>
    );
};
