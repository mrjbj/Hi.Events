import {Badge, Box, Divider, Group, NumberInput, Select, Stack, Table, Text, TextInput} from "@mantine/core";
import {useForm} from "@mantine/form";
import {t, Trans} from "@lingui/macro";
import {useParams} from "react-router";
import {Order, OrderPaymentType} from "../../../types.ts";
import {formatCurrency} from "../../../utilites/currency.ts";
import {useRecordOrderPayment} from "../../../mutations/useRecordOrderPayment.ts";
import {useFormErrorResponseHandler} from "../../../hooks/useFormErrorResponseHandler.tsx";
import {showSuccess} from "../../../utilites/notifications.tsx";
import {Button} from "../Button";
import {prettyDate} from "../../../utilites/dates.ts";

interface OrderPaymentManagementProps {
    order: Order;
    timezone: string;
    onUpdated: () => void;
}

const paymentTypeLabels = (): Record<OrderPaymentType, string> => ({
    CASH: t`Cash`,
    CHECK: t`Check`,
    CARD: t`Card`,
    BANK_TRANSFER: t`Bank transfer`,
    OTHER: t`Other`,
    DONATION: t`Donation`,
    COMP: t`Comp`,
    WRITE_OFF: t`Write-off`,
});

export const OrderPaymentManagement = ({order, timezone, onUpdated}: OrderPaymentManagementProps) => {
    const {eventId} = useParams();
    const recordPayment = useRecordOrderPayment();
    const errorHandler = useFormErrorResponseHandler();
    const labels = paymentTypeLabels();

    const balance = order.payment_balance;
    const currency = order.currency;
    const outstanding = balance ? Math.max(0, balance.balance) : 0;

    const form = useForm({
        initialValues: {
            type: 'CASH',
            amount: outstanding,
            reference: '',
            note: '',
        },
    });

    const submit = (values: typeof form.values) => {
        recordPayment.mutate({
            eventId,
            orderId: order.id,
            payload: {
                type: values.type,
                amount: Number(values.amount),
                reference: values.reference.trim() === '' ? null : values.reference.trim(),
                note: values.note.trim() === '' ? null : values.note.trim(),
            },
        }, {
            onSuccess: () => {
                showSuccess(t`Payment recorded`);
                form.reset();
                onUpdated();
            },
            onError: (error) => errorHandler(form, error),
        });
    };

    const compRemainder = () => {
        recordPayment.mutate({
            eventId,
            orderId: order.id,
            payload: {type: 'COMP', amount: outstanding, note: t`Comp remainder`},
        }, {
            onSuccess: () => {
                showSuccess(t`Remaining balance comped`);
                onUpdated();
            },
            onError: (error) => errorHandler(form, error),
        });
    };

    return (
        <Box p="md">
            <Stack gap="sm">
                {balance && (
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
                )}

                {order.payments && order.payments.length > 0 && (
                    <Table withRowBorders={false} verticalSpacing="xs">
                        <Table.Tbody>
                            {order.payments.map((payment) => (
                                <Table.Tr key={payment.id}>
                                    <Table.Td>{labels[payment.type] ?? payment.type}</Table.Td>
                                    <Table.Td>{formatCurrency(payment.amount, payment.currency)}</Table.Td>
                                    <Table.Td>
                                        <Text size="xs" c="dimmed">{payment.reference ?? ''}</Text>
                                    </Table.Td>
                                    <Table.Td>
                                        <Text size="xs" c="dimmed">{prettyDate(payment.created_at, timezone)}</Text>
                                    </Table.Td>
                                </Table.Tr>
                            ))}
                        </Table.Tbody>
                    </Table>
                )}

                <Divider label={t`Record a payment`} labelPosition="left"/>

                <form onSubmit={form.onSubmit(submit)}>
                    <Stack gap="xs">
                        <Group grow align="flex-start">
                            <Select
                                label={t`Method`}
                                data={[
                                    {value: 'CASH', label: t`Cash`},
                                    {value: 'CHECK', label: t`Check`},
                                    {value: 'CARD', label: t`Card`},
                                    {value: 'BANK_TRANSFER', label: t`Bank transfer`},
                                    {value: 'OTHER', label: t`Other`},
                                    {value: 'DONATION', label: t`Donation`},
                                ]}
                                allowDeselect={false}
                                {...form.getInputProps('type')}
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
                                    onClick={compRemainder}
                                    disabled={recordPayment.isPending}
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
            </Stack>
        </Box>
    );
};

const Summary = ({label, value, highlight}: {label: string; value: string; highlight?: boolean}) => (
    <div>
        <Text size="xs" c="dimmed">{label}</Text>
        <Text fw={600} c={highlight ? 'red' : undefined}>{value}</Text>
    </div>
);
