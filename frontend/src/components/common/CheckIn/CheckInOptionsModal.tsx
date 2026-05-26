import {Alert, Button, Modal, NumberInput, Select, Stack, Text, TextInput} from "@mantine/core";
import {IconAlertCircle, IconCreditCard, IconUserCheck} from "@tabler/icons-react";
import {t, Trans} from "@lingui/macro";
import {useState} from "react";
import {Attendee} from "../../../types.ts";

export type OfflinePaymentMethod = 'CASH' | 'CHECK' | 'CREDIT_CARD' | 'BANK_TRANSFER' | 'OTHER';

export interface MarkAsPaidPayload {
    payment_method: OfflinePaymentMethod;
    payment_reference?: string | null;
    collected_amount?: number | null;
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
    const orderTotal = attendee?.order_total_gross ?? 0;
    const [collected, setCollected] = useState<number>(orderTotal);
    const currency = attendee?.order_currency ?? 'USD';

    if (!attendee) return null;

    const reset = () => {
        setShowPaymentForm(false);
        setMethod('CASH');
        setReference('');
        setCollected(orderTotal);
    };

    const handleClose = () => {
        reset();
        onClose();
    };

    const submitPaid = () => {
        onCheckInAndMarkAsPaid({
            payment_method: method,
            payment_reference: reference.trim() === '' ? null : reference.trim(),
            collected_amount: collected,
        });
    };

    const amountDiffers = Math.abs(collected - orderTotal) > 0.001;

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
                            label={<Trans>Amount collected ({currency})</Trans>}
                            required
                            decimalScale={2}
                            fixedDecimalScale
                            min={0}
                            step={1}
                            value={collected}
                            onChange={(val) => setCollected(typeof val === 'number' ? val : parseFloat(String(val)) || 0)}
                            description={
                                <Trans>Order total is {orderTotal.toFixed(2)} {currency}. Override if you collected a different amount.</Trans>
                            }
                        />
                        {amountDiffers && (
                            <Alert
                                icon={<IconAlertCircle size={16}/>}
                                color="yellow"
                                variant="light"
                            >
                                <Text size="sm">
                                    <Trans>
                                        This will adjust the order total from {orderTotal.toFixed(2)} to {collected.toFixed(2)} {currency}.
                                        Taxes and fees will be cleared on this order. An audit entry will be saved.
                                    </Trans>
                                </Text>
                            </Alert>
                        )}
                        <TextInput
                            label={t`Reference (optional)`}
                            placeholder={t`e.g. check #1234, last 4 of card`}
                            value={reference}
                            onChange={(e) => setReference(e.currentTarget.value)}
                            maxLength={255}
                        />
                        <Button
                            onClick={submitPaid}
                            loading={isPending}
                            variant="filled"
                            fullWidth
                        >
                            {t`Check in and mark order as paid`}
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
