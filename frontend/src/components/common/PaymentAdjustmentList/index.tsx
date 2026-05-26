import {Stack, Table, Text} from "@mantine/core";
import {t} from "@lingui/macro";
import {OrderPaymentAdjustment} from "../../../types.ts";
import {Currency} from "../Currency";
import {prettyDate} from "../../../utilites/dates.ts";

const formatMethod = (method: string): string => {
    switch (method) {
        case 'CASH': return t`Cash`;
        case 'CHECK': return t`Check`;
        case 'CREDIT_CARD': return t`Credit card`;
        case 'BANK_TRANSFER': return t`Bank transfer`;
        case 'OTHER': return t`Other`;
        default: return method;
    }
};

interface PaymentAdjustmentListProps {
    adjustments: OrderPaymentAdjustment[];
    currency: string;
    timezone?: string;
}

export const PaymentAdjustmentList = ({adjustments, currency, timezone}: PaymentAdjustmentListProps) => {
    if (!adjustments.length) {
        return null;
    }

    return (
        <Stack p="md" gap="sm">
            <Text size="sm" c="dimmed">
                {t`Each row records a moment where the order total was adjusted at mark-as-paid time.`}
            </Text>
            <Table striped withTableBorder withColumnBorders verticalSpacing="xs">
                <Table.Thead>
                    <Table.Tr>
                        <Table.Th>{t`When`}</Table.Th>
                        <Table.Th>{t`Method`}</Table.Th>
                        <Table.Th>{t`Reference`}</Table.Th>
                        <Table.Th>{t`Original total`}</Table.Th>
                        <Table.Th>{t`Adjusted to`}</Table.Th>
                        <Table.Th>{t`Source IP`}</Table.Th>
                    </Table.Tr>
                </Table.Thead>
                <Table.Tbody>
                    {adjustments.map((adj) => (
                        <Table.Tr key={adj.id}>
                            <Table.Td>{prettyDate(adj.created_at, timezone ?? 'UTC')}</Table.Td>
                            <Table.Td>{formatMethod(adj.payment_method)}</Table.Td>
                            <Table.Td>{adj.payment_reference || '—'}</Table.Td>
                            <Table.Td>
                                <Currency currency={currency} price={adj.original_total_gross}/>
                            </Table.Td>
                            <Table.Td>
                                <Currency currency={currency} price={adj.adjusted_total_gross}/>
                            </Table.Td>
                            <Table.Td>
                                <Text size="xs" c="dimmed">{adj.adjusted_by_ip || '—'}</Text>
                            </Table.Td>
                        </Table.Tr>
                    ))}
                </Table.Tbody>
            </Table>
        </Stack>
    );
};
