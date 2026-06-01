import {ActionIcon, Badge, Box, Divider, Group, Modal, NumberInput, Select, Stack, Table, Text, TextInput, Textarea, Tooltip} from "@mantine/core";
import {useForm} from "@mantine/form";
import {useDisclosure} from "@mantine/hooks";
import {modals} from "@mantine/modals";
import {useState} from "react";
import {t, Trans} from "@lingui/macro";
import {IconArrowBackUp, IconGift, IconLock, IconReceiptRefund} from "@tabler/icons-react";
import {useParams} from "react-router";
import {Order, OrderPayment, OrderPaymentMethod, OrderPaymentTransactionType} from "../../../types.ts";
import {formatCurrency} from "../../../utilites/currency.ts";
import {useRecordOrderPayment} from "../../../mutations/useRecordOrderPayment.ts";
import {useReverseOrderPayment} from "../../../mutations/useReverseOrderPayment.ts";
import {useFormErrorResponseHandler} from "../../../hooks/useFormErrorResponseHandler.tsx";
import {showSuccess} from "../../../utilites/notifications.tsx";
import {Button} from "../Button";
import {RefundOrderModal} from "../../modals/RefundOrderModal";
import {prettyDate} from "../../../utilites/dates.ts";

interface OrderPaymentManagementProps {
    order: Order;
    timezone: string;
    onUpdated: () => void;
}

const transactionTypeLabels = (): Record<OrderPaymentTransactionType, string> => ({
    PAYMENT: t`Payment`,
    DONATION: t`Donation`,
    COMP: t`Comp`,
    WRITE_OFF: t`Write-off`,
});

const paymentMethodLabels = (): Record<OrderPaymentMethod, string> => ({
    CASH: t`Cash`,
    CHECK: t`Check`,
    CREDIT_CARD: t`Credit card`,
    BANK_TRANSFER: t`Bank transfer`,
    OTHER: t`Other`,
});

const describePayment = (payment: OrderPayment): string => {
    const txnLabels = transactionTypeLabels();
    const methodLabels = paymentMethodLabels();
    const method = payment.payment_method ? methodLabels[payment.payment_method] : null;
    if (payment.transaction_type === 'PAYMENT') {
        return method ?? txnLabels.PAYMENT;
    }
    const txn = txnLabels[payment.transaction_type];
    return method ? `${txn} · ${method}` : txn;
};

export const OrderPaymentManagement = ({order, timezone, onUpdated}: OrderPaymentManagementProps) => {
    const {eventId} = useParams();
    const recordPayment = useRecordOrderPayment();
    const reversePayment = useReverseOrderPayment();
    const errorHandler = useFormErrorResponseHandler();

    const balance = order.payment_balance;
    const currency = order.currency;
    const outstanding = balance ? Math.max(0, balance.balance) : 0;
    const payments = order.payments ?? [];
    const reversedIds = new Set(payments.filter((p) => p.reverses_payment_id).map((p) => p.reverses_payment_id));
    const [compModalOpen, compModalHandlers] = useDisclosure(false);
    const [compReason, setCompReason] = useState('');
    const [compReasonError, setCompReasonError] = useState<string | null>(null);
    const [reverseTarget, setReverseTarget] = useState<OrderPayment | null>(null);
    const [reverseReason, setReverseReason] = useState('');
    const [reverseReasonError, setReverseReasonError] = useState<string | null>(null);
    const [refundOpen, refundHandlers] = useDisclosure(false);
    const [unlocked, setUnlocked] = useState(false);

    const isSettled = !!balance?.isSettled;
    const formLocked = isSettled && !unlocked;

    const isRefundable = !order.is_free_order
        && order.status !== 'AWAITING_OFFLINE_PAYMENT'
        && order.payment_provider === 'STRIPE'
        && order.refund_status !== 'REFUNDED';

    const form = useForm({
        initialValues: {
            transaction_type: 'PAYMENT',
            payment_method: 'CASH',
            amount: outstanding,
            reference: '',
            note: '',
        },
    });

    const doRecord = (values: typeof form.values, splitExcessAsDonation: boolean) => {
        recordPayment.mutate({
            eventId,
            orderId: order.id,
            payload: {
                transaction_type: values.transaction_type,
                payment_method: values.payment_method,
                amount: Number(values.amount),
                reference: values.reference.trim() === '' ? null : values.reference.trim(),
                note: values.note.trim() === '' ? null : values.note.trim(),
                split_excess_as_donation: splitExcessAsDonation || undefined,
            },
        }, {
            onSuccess: () => {
                showSuccess(t`Payment recorded`);
                form.reset();
                setUnlocked(false);
                onUpdated();
            },
            onError: (error) => errorHandler(form, error),
        });
    };

    const submit = (values: typeof form.values) => {
        const amount = Number(values.amount);
        const excess = amount - outstanding;
        if (values.transaction_type === 'PAYMENT' && outstanding > 0 && excess > 0) {
            modals.openConfirmModal({
                title: t`Record overpayment as donation?`,
                children: (
                    <Text size="sm">
                        <Trans>
                            This is {formatCurrency(excess, currency)} more than the {formatCurrency(outstanding, currency)} owed.
                            Record the extra {formatCurrency(excess, currency)} as a donation?
                        </Trans>
                    </Text>
                ),
                labels: {confirm: t`Yes, record as donation`, cancel: t`No, leave as overpayment`},
                onConfirm: () => doRecord(values, true),
                onCancel: () => doRecord(values, false),
            });
            return;
        }
        doRecord(values, false);
    };

    const confirmUnlock = () => {
        modals.openConfirmModal({
            title: t`Add another transaction?`,
            children: (
                <Text size="sm">{t`This order is fully settled. Add another transaction anyway?`}</Text>
            ),
            labels: {confirm: t`Add transaction`, cancel: t`Cancel`},
            onConfirm: () => setUnlocked(true),
        });
    };

    const openCompModal = () => {
        setCompReason('');
        setCompReasonError(null);
        compModalHandlers.open();
    };

    const openReverseModal = (payment: OrderPayment) => {
        setReverseTarget(payment);
        setReverseReason('');
        setReverseReasonError(null);
    };

    const submitReverse = () => {
        if (!reverseTarget) {
            return;
        }
        if (reverseReason.trim() === '') {
            setReverseReasonError(t`A reason is required to reverse a payment`);
            return;
        }
        reversePayment.mutate({
            eventId,
            orderId: order.id,
            paymentId: reverseTarget.id,
            payload: {note: reverseReason.trim()},
        }, {
            onSuccess: () => {
                showSuccess(t`Payment reversed`);
                setReverseTarget(null);
                onUpdated();
            },
            onError: (error) => errorHandler(form, error),
        });
    };

    const compRemainder = () => {
        if (compReason.trim() === '') {
            setCompReasonError(t`A reason is required to comp a balance`);
            return;
        }
        recordPayment.mutate({
            eventId,
            orderId: order.id,
            payload: {transaction_type: 'COMP', amount: outstanding, note: compReason.trim()},
        }, {
            onSuccess: () => {
                showSuccess(t`Remaining balance comped`);
                compModalHandlers.close();
                onUpdated();
            },
            onError: (error) => errorHandler(form, error),
        });
    };

    return (
        <Box p="md">
            <Stack gap="sm">
                {(balance || isRefundable) && (
                    <Group justify="space-between" align="flex-start" wrap="nowrap">
                        {balance ? (
                            <Group gap="xl" wrap="wrap">
                                <Summary label={t`Owed`} value={formatCurrency(balance.amountOwed, currency)}/>
                                <Summary label={t`Collected`} value={formatCurrency(balance.amountCollected, currency)}/>
                                {balance.totalComps > 0 && (
                                    <Summary label={t`Comped`} value={formatCurrency(balance.totalComps, currency)}/>
                                )}
                                {balance.totalRefunded > 0 && (
                                    <Summary label={t`Refunded`} value={formatCurrency(balance.totalRefunded, currency)}/>
                                )}
                                {balance.isSettled ? (
                                    <Badge color="green" variant="light">
                                        {balance.overpaid > 0
                                            ? <Trans>Settled · overpaid {formatCurrency(balance.overpaid, currency)}</Trans>
                                            : t`Settled`}
                                    </Badge>
                                ) : (
                                    <Summary label={t`Outstanding`} value={formatCurrency(outstanding, currency)} highlight/>
                                )}
                            </Group>
                        ) : <span/>}
                        {isRefundable && (
                            <Button
                                variant="light"
                                color="red"
                                size="compact-sm"
                                leftSection={<IconReceiptRefund size={14}/>}
                                onClick={refundHandlers.open}
                            >
                                {t`Refund`}
                            </Button>
                        )}
                    </Group>
                )}

                {payments.length > 0 && (
                    <Table withRowBorders={false} verticalSpacing="xs">
                        <Table.Tbody>
                            {payments.map((payment) => {
                                const isReversal = !!payment.reverses_payment_id;
                                const isReversed = reversedIds.has(payment.id);
                                const canReverse = !isReversal && !isReversed;
                                return (
                                    <Table.Tr key={payment.id}>
                                        <Table.Td>
                                            <Group gap={6} wrap="nowrap">
                                                {isReversal && <IconArrowBackUp size={14} color="var(--mantine-color-dimmed)"/>}
                                                <Text size="sm" c={isReversal ? 'dimmed' : undefined}>
                                                    {isReversal ? t`Reversal` : describePayment(payment)}
                                                </Text>
                                                {isReversed && (
                                                    <Badge size="xs" color="gray" variant="light">{t`Reversed`}</Badge>
                                                )}
                                            </Group>
                                        </Table.Td>
                                        <Table.Td>
                                            <Text
                                                size="sm"
                                                c={isReversal ? 'red' : undefined}
                                                td={isReversed ? 'line-through' : undefined}
                                            >
                                                {formatCurrency(payment.amount, payment.currency)}
                                            </Text>
                                        </Table.Td>
                                        <Table.Td>
                                            {payment.reference && !isReversal && (
                                                <Text size="xs" c="dimmed">{payment.reference}</Text>
                                            )}
                                            {payment.note && (
                                                <Text size="xs" c="dimmed" fs="italic">{payment.note}</Text>
                                            )}
                                        </Table.Td>
                                        <Table.Td>
                                            <Text size="xs" c="dimmed">{prettyDate(payment.created_at, timezone)}</Text>
                                        </Table.Td>
                                        <Table.Td w={40}>
                                            {canReverse && (
                                                <Tooltip label={t`Reverse this payment`} withArrow>
                                                    <ActionIcon
                                                        variant="subtle"
                                                        color="gray"
                                                        size="sm"
                                                        aria-label={t`Reverse this payment`}
                                                        onClick={() => openReverseModal(payment)}
                                                        disabled={reversePayment.isPending}
                                                    >
                                                        <IconArrowBackUp size={16}/>
                                                    </ActionIcon>
                                                </Tooltip>
                                            )}
                                        </Table.Td>
                                    </Table.Tr>
                                );
                            })}
                        </Table.Tbody>
                    </Table>
                )}

                <Divider label={t`Record a payment`} labelPosition="left"/>

                {formLocked && (
                    <Group justify="space-between" wrap="nowrap" p="sm"
                           style={{border: '1px dashed var(--mantine-color-gray-4)', borderRadius: 'var(--mantine-radius-sm)'}}>
                        <Group gap={8} wrap="nowrap">
                            <IconLock size={16} color="var(--mantine-color-dimmed)"/>
                            <Text size="sm" c="dimmed">{t`Fully settled — add-transaction form is locked.`}</Text>
                        </Group>
                        <Button variant="subtle" color="gray" size="compact-sm" onClick={confirmUnlock}>
                            {t`Add another transaction`}
                        </Button>
                    </Group>
                )}

                {!formLocked && (
                <form onSubmit={form.onSubmit(submit)}>
                    <Stack gap="xs">
                        <Group grow align="flex-start">
                            <Select
                                label={t`Type`}
                                data={[
                                    {value: 'PAYMENT', label: t`Payment`},
                                    {value: 'DONATION', label: t`Donation`},
                                ]}
                                allowDeselect={false}
                                {...form.getInputProps('transaction_type')}
                            />
                            <Select
                                label={t`Method`}
                                data={[
                                    {value: 'CASH', label: t`Cash`},
                                    {value: 'CHECK', label: t`Check`},
                                    {value: 'CREDIT_CARD', label: t`Credit card`},
                                    {value: 'BANK_TRANSFER', label: t`Bank transfer`},
                                    {value: 'OTHER', label: t`Other`},
                                ]}
                                allowDeselect={false}
                                {...form.getInputProps('payment_method')}
                            />
                            <NumberInput
                                label={<Trans>Amount ({currency})</Trans>}
                                min={0}
                                decimalScale={2}
                                fixedDecimalScale
                                step={1}
                                {...form.getInputProps('amount')}
                            />
                        </Group>
                        <TextInput
                            label={t`Reference (optional)`}
                            placeholder={t`e.g. check #1234, last 4 of card`}
                            maxLength={255}
                            {...form.getInputProps('reference')}
                        />
                        <TextInput
                            label={t`Note (optional)`}
                            maxLength={1000}
                            {...form.getInputProps('note')}
                        />
                        <Group justify="space-between" mt="xs">
                            {outstanding > 0 ? (
                                <Button
                                    variant="subtle"
                                    color="gray"
                                    size="compact-sm"
                                    leftSection={<IconGift size={14}/>}
                                    onClick={openCompModal}
                                    disabled={recordPayment.isPending}
                                    styles={{
                                        root: {
                                            fontWeight: 400,
                                            border: '1px dashed var(--mantine-color-gray-4)',
                                        },
                                    }}
                                >
                                    <Trans>Comp remaining {formatCurrency(outstanding, currency)}</Trans>
                                </Button>
                            ) : <span/>}
                            <Button type="submit" loading={recordPayment.isPending}>
                                {t`Record payment`}
                            </Button>
                        </Group>
                    </Stack>
                </form>
                )}
            </Stack>

            <Modal
                opened={compModalOpen}
                onClose={compModalHandlers.close}
                title={<Trans>Comp remaining {formatCurrency(outstanding, currency)}</Trans>}
            >
                <Stack gap="sm">
                    <Text size="sm" c="dimmed">
                        {t`A comp forgives the outstanding balance without collecting money. Record the reason for your audit trail.`}
                    </Text>
                    <Textarea
                        label={t`Reason`}
                        required
                        autosize
                        minRows={2}
                        maxLength={1000}
                        placeholder={t`e.g. board-approved sponsor, fundraising comp`}
                        value={compReason}
                        error={compReasonError}
                        onChange={(e) => {
                            setCompReason(e.currentTarget.value);
                            if (compReasonError) setCompReasonError(null);
                        }}
                    />
                    <Group justify="flex-end">
                        <Button variant="subtle" color="gray" onClick={compModalHandlers.close}>
                            {t`Cancel`}
                        </Button>
                        <Button onClick={compRemainder} loading={recordPayment.isPending}>
                            <Trans>Comp {formatCurrency(outstanding, currency)}</Trans>
                        </Button>
                    </Group>
                </Stack>
            </Modal>

            <Modal
                opened={reverseTarget !== null}
                onClose={() => setReverseTarget(null)}
                title={t`Reverse payment`}
            >
                <Stack gap="sm">
                    {reverseTarget && (
                        <Text size="sm" c="dimmed">
                            <Trans>
                                This books a correcting entry of {formatCurrency(-reverseTarget.amount, reverseTarget.currency)} against
                                the {describePayment(reverseTarget)} payment. The original entry is kept for the
                                audit trail. Reversing offline money assumes the cash or check is returned in person; it does not
                                issue a refund.
                            </Trans>
                        </Text>
                    )}
                    <Textarea
                        label={t`Reason`}
                        required
                        autosize
                        minRows={2}
                        maxLength={1000}
                        placeholder={t`e.g. entered the wrong amount, duplicate entry`}
                        value={reverseReason}
                        error={reverseReasonError}
                        onChange={(e) => {
                            setReverseReason(e.currentTarget.value);
                            if (reverseReasonError) setReverseReasonError(null);
                        }}
                    />
                    <Group justify="flex-end">
                        <Button variant="subtle" color="gray" onClick={() => setReverseTarget(null)}>
                            {t`Cancel`}
                        </Button>
                        <Button color="red" onClick={submitReverse} loading={reversePayment.isPending}>
                            {t`Reverse payment`}
                        </Button>
                    </Group>
                </Stack>
            </Modal>

            {refundOpen && (
                <RefundOrderModal
                    orderId={order.id}
                    onClose={() => {
                        refundHandlers.close();
                        onUpdated();
                    }}
                />
            )}
        </Box>
    );
};

const Summary = ({label, value, highlight}: {label: string; value: string; highlight?: boolean}) => (
    <div>
        <Text size="xs" c="dimmed">{label}</Text>
        <Text fw={600} c={highlight ? 'red' : undefined}>{value}</Text>
    </div>
);
