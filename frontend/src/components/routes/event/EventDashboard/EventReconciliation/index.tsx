import {ReactNode, useEffect, useState} from "react";
import {t, Trans} from "@lingui/macro";
import {Button, Menu, NumberInput, Skeleton} from "@mantine/core";
import {IconChevronDown, IconDownload} from "@tabler/icons-react";
import {Card} from "../../../../common/Card";
import classes from "./EventReconciliation.module.scss";
import {useGetEventReconciliation} from "../../../../../queries/useGetEventReconciliation.ts";
import {useUpdateEventChannelFees} from "../../../../../mutations/useUpdateEventChannelFees.ts";
import {useUpdateEventExpenses} from "../../../../../mutations/useUpdateEventExpenses.ts";
import {formatCurrency, getCurrencySymbol} from "../../../../../utilites/currency.ts";
import {showError, showSuccess} from "../../../../../utilites/notifications.tsx";
import {ChannelFeeInput, EventReconciliationChannel, IdParam, PaymentChannel} from "../../../../../types.ts";
import {formatDateWithLocale} from "../../../../../utilites/dates.ts";
import {orderClient} from "../../../../../api/order.client.ts";
import {attendeesClient} from "../../../../../api/attendee.client.ts";
import {downloadBinary} from "../../../../../utilites/download.ts";
import {withLoadingNotification} from "../../../../../utilites/withLoadingNotification.tsx";

interface EventReconciliationProps {
    eventId: IdParam;
    timezone?: string;
    // Extra cards (Product Sales, Revenue) stacked beneath Funds by Channel in the right column.
    children?: ReactNode;
}

const CHANNEL_COLORS: Record<PaymentChannel, string> = {
    STRIPE: '#635bff',
    SQUARE: '#3e4348',
    CASH: '#2f9e44',
    CHECK: '#1971c2',
    BANK_TRANSFER: '#9c36b5',
    OTHER: '#868e96',
};

const channelLabel = (channel: PaymentChannel): string => ({
    STRIPE: t`Stripe`,
    SQUARE: t`Square`,
    CASH: t`Cash`,
    CHECK: t`Check`,
    BANK_TRANSFER: t`Bank transfer`,
    OTHER: t`Other`,
}[channel]);

// Channels that carry a processing fee worth entering; others default to none.
const FEE_BEARING: PaymentChannel[] = ['STRIPE', 'SQUARE', 'BANK_TRANSFER', 'OTHER'];

export const EventReconciliation = ({eventId, timezone, children}: EventReconciliationProps) => {
    const {data: reconciliation, isLoading} = useGetEventReconciliation(eventId);
    const updateFees = useUpdateEventChannelFees();
    const updateExpenses = useUpdateEventExpenses();

    const [feeDrafts, setFeeDrafts] = useState<Record<string, number | string>>({});
    const [expensesDraft, setExpensesDraft] = useState<number | string>(0);
    const [exportPending, setExportPending] = useState(false);

    const handleExport = async (type: 'orders' | 'attendees') => {
        await withLoadingNotification(async () => {
                setExportPending(true);
                const blob = type === 'orders'
                    ? await orderClient.exportOrders(eventId)
                    : await attendeesClient.export(eventId);
                downloadBinary(blob, `${type}.xlsx`);
            },
            {
                loading: {
                    title: t`Exporting`,
                    message: t`Please wait while we prepare your export...`,
                },
                success: {
                    title: t`Export ready`,
                    message: t`Your download should begin shortly.`,
                    onRun: () => setExportPending(false),
                },
                error: {
                    title: t`Export failed`,
                    message: t`Please try again.`,
                    onRun: () => setExportPending(false),
                },
            });
    };

    useEffect(() => {
        if (reconciliation) {
            const drafts: Record<string, number | string> = {};
            reconciliation.channels.forEach((channel) => {
                drafts[channel.channel] = channel.fee;
            });
            setFeeDrafts(drafts);
            setExpensesDraft(reconciliation.expenses);
        }
    }, [reconciliation]);

    if (isLoading || !reconciliation) {
        return <Skeleton height={420} radius="l" mb="20px"/>;
    }

    const currency = reconciliation.currency;
    const money = (value: number) => formatCurrency(value, currency);
    // Zero figures render as a dash placeholder across the reconciliation card.
    const moneyOrDash = (value: number) => (value === 0 ? '—' : money(value));
    const collectedPercent = reconciliation.net_expected_funds > 0
        ? Math.min(100, Math.round((reconciliation.collected / reconciliation.net_expected_funds) * 100))
        : 0;

    const handleSave = () => {
        const fees: ChannelFeeInput[] = reconciliation.channels.map((channel) => ({
            channel: channel.channel,
            fee_amount: Number(feeDrafts[channel.channel] ?? 0) || 0,
        }));

        updateFees.mutate({eventId, fees}, {
            onSuccess: () => showSuccess(t`Processing fees saved`),
            onError: () => showError(t`Could not save processing fees. Please try again.`),
        });
    };

    const isDirty = reconciliation.channels.some(
        (channel) => Number(feeDrafts[channel.channel] ?? 0) !== channel.fee,
    );

    const expensesValue = Number(expensesDraft ?? 0) || 0;
    const expensesDirty = expensesValue !== reconciliation.expenses;
    // Live gain/(loss) reflects the draft so it updates as the operator types.
    const gainLoss = Math.round((reconciliation.net_to_bank - expensesValue) * 100) / 100;

    const handleSaveExpenses = () => {
        updateExpenses.mutate({eventId, expenses: expensesValue}, {
            onSuccess: () => showSuccess(t`Expenses saved`),
            onError: () => showError(t`Could not save expenses. Please try again.`),
        });
    };

    const waterfall = [
        {label: t`Gross sales`, value: reconciliation.gross_sales, sign: '+' as const, base: true},
        {label: t`Refunds`, value: reconciliation.refunds, sign: '−' as const},
        {label: t`Comps (forgiven)`, value: reconciliation.comps + reconciliation.write_offs, sign: '−' as const, danger: true},
        {label: t`Donations`, value: reconciliation.donations, sign: '+' as const},
    ];
    const maxWaterfall = Math.max(reconciliation.gross_sales, 1);

    return (
        <div className={classes.dashGrid}>
            <div className={classes.mainColumn}>
            <Card className={`${classes.card} ${classes.reconCard}`}>
                <div className={classes.cardTitle}>
                    <h2><Trans>Event Reconciliation</Trans></h2>
                    <Menu shadow="md" position="bottom-end" withinPortal>
                        <Menu.Target>
                            <Button
                                size="xs"
                                variant="light"
                                radius="md"
                                loading={exportPending}
                                leftSection={<IconDownload size={14}/>}
                                rightSection={<IconChevronDown size={14}/>}
                            >
                                <Trans>Export</Trans>
                            </Button>
                        </Menu.Target>
                        <Menu.Dropdown>
                            <Menu.Item onClick={() => handleExport('orders')}>
                                <Trans>Orders</Trans>
                            </Menu.Item>
                            <Menu.Item onClick={() => handleExport('attendees')}>
                                <Trans>Attendees</Trans>
                            </Menu.Item>
                        </Menu.Dropdown>
                    </Menu>
                </div>

                <div className={classes.tiles}>
                    <div className={classes.tile}>
                        <div className={classes.tileLabel}><Trans>Net expected funds</Trans></div>
                        <div className={classes.tileNumber}>{moneyOrDash(reconciliation.net_expected_funds)}</div>
                        <div className={classes.tileSub}><Trans>what we "sold"</Trans></div>
                    </div>
                    <div className={classes.tile}>
                        <div className={classes.tileLabel}><Trans>Collected to date</Trans></div>
                        <div className={classes.tileNumber}>{moneyOrDash(reconciliation.collected)}</div>
                        <div className={classes.progressTrack}>
                            <div className={classes.progressFill} style={{width: `${collectedPercent}%`}}/>
                        </div>
                        <div className={classes.tileSub}>
                            <Trans>{collectedPercent}% received</Trans>
                        </div>
                    </div>
                    <div className={classes.tile}>
                        <div className={classes.tileLabel}><Trans>Net to bank</Trans></div>
                        <div className={classes.tileNumber}>{moneyOrDash(reconciliation.net_to_bank)}</div>
                        <div className={classes.tileSub}><Trans>est. after fees</Trans></div>
                    </div>
                </div>

                <div className={classes.waterfall}>
                    <div className={classes.waterfallHeading}><Trans>How we get there</Trans></div>
                    {waterfall.map((row) => {
                        const dangerColor = row.danger && row.value !== 0 ? 'var(--mantine-color-red-7)' : undefined;
                        return (
                            <div className={classes.waterfallRow} key={row.label}>
                                <div className={classes.waterfallLabel} style={{color: dangerColor}}>{row.label}</div>
                                <div className={classes.waterfallBarTrack}>
                                    <div
                                        className={`${classes.waterfallBar} ${row.sign === '−' ? classes.barNegative : ''}`}
                                        style={{width: `${Math.min(100, (row.value / maxWaterfall) * 100)}%`}}
                                    />
                                </div>
                                <div className={classes.waterfallValue} style={{color: dangerColor}}>
                                    {row.value === 0 ? '—' : <>{row.sign}&nbsp;{money(row.value)}</>}
                                </div>
                            </div>
                        );
                    })}
                    <div className={`${classes.waterfallRow} ${classes.waterfallTotal}`}>
                        <div className={classes.waterfallLabel}><Trans>Net expected funds</Trans></div>
                        <div className={classes.waterfallBarTrack}/>
                        <div className={classes.waterfallValue}>
                            {reconciliation.net_expected_funds === 0 ? '—' : <>= {money(reconciliation.net_expected_funds)}</>}
                        </div>
                    </div>
                    <div className={classes.waterfallRow}>
                        <div
                            className={classes.waterfallLabel}
                            style={{color: reconciliation.total_fees !== 0 ? 'var(--mantine-color-red-7)' : undefined}}
                        ><Trans>Processing fees</Trans></div>
                        <div className={classes.waterfallBarTrack}/>
                        <div
                            className={classes.waterfallValue}
                            style={{color: reconciliation.total_fees !== 0 ? 'var(--mantine-color-red-7)' : undefined}}
                        >
                            {reconciliation.total_fees === 0 ? '—' : <>− {money(reconciliation.total_fees)}</>}
                        </div>
                    </div>
                    <div className={`${classes.waterfallRow} ${classes.waterfallTotal}`}>
                        <div className={classes.waterfallLabel}><Trans>Net to bank (est.)</Trans></div>
                        <div className={classes.waterfallBarTrack}/>
                        <div className={classes.waterfallValue}>
                            {reconciliation.net_to_bank === 0 ? '—' : <>= {money(reconciliation.net_to_bank)}</>}
                        </div>
                    </div>
                    <div className={classes.waterfallRow}>
                        <div className={classes.waterfallLabel}><Trans>Expenses</Trans></div>
                        <div className={classes.waterfallBarTrack}/>
                        <div className={classes.waterfallValue}>
                            <span style={{
                                display: 'inline-flex',
                                alignItems: 'center',
                                gap: 4,
                                color: expensesValue !== 0 ? 'var(--mantine-color-red-7)' : undefined,
                            }}>
                                −
                                <NumberInput
                                    size="xs"
                                    value={expensesDraft}
                                    onChange={(value) => setExpensesDraft(value)}
                                    min={0}
                                    decimalScale={2}
                                    fixedDecimalScale
                                    prefix={getCurrencySymbol(currency)}
                                    hideControls
                                    classNames={{input: classes.moneyInput}}
                                    styles={{
                                        wrapper: {margin: 0},
                                        root: {maxWidth: 110, marginBottom: 0},
                                        input: {color: expensesValue !== 0 ? 'var(--mantine-color-red-7)' : undefined},
                                    }}
                                />
                            </span>
                        </div>
                    </div>
                    <div className={`${classes.waterfallRow} ${classes.waterfallTotal}`}>
                        <div className={classes.waterfallLabel}><Trans>Gain / (Loss)</Trans></div>
                        <div className={classes.waterfallBarTrack}/>
                        <div
                            className={classes.waterfallValue}
                            style={{color: gainLoss < 0 ? 'var(--mantine-color-red-7)' : undefined}}
                        >
                            {gainLoss === 0 ? '—' : <>= {gainLoss < 0 ? `(${money(Math.abs(gainLoss))})` : money(gainLoss)}</>}
                        </div>
                    </div>
                </div>

                <div className={classes.footer}>
                    <div className={classes.credits}>
                        <Trans>Enter expenses from your own records to see the bottom line.</Trans>
                    </div>
                    <div className={classes.saveBar}>
                        {reconciliation.expenses_updated_at && (
                            <span className={classes.stamp}>
                                <Trans>Expenses updated {formatDateWithLocale(reconciliation.expenses_updated_at, 'shortDate', timezone ?? 'UTC')}</Trans>
                            </span>
                        )}
                        <Button
                            size="sm"
                            variant="light"
                            radius="md"
                            disabled={!expensesDirty}
                            loading={updateExpenses.isPending}
                            onClick={handleSaveExpenses}
                        >
                            <Trans>Save expenses</Trans>
                        </Button>
                    </div>
                </div>
            </Card>

            <Card className={`${classes.card} ${classes.fundsCard}`}>
                <div className={classes.cardTitle}>
                    <h2><Trans>Funds by Channel</Trans></h2>
                    <span className={classes.hint}><Trans>fees are editable</Trans></span>
                </div>

                {reconciliation.channels.length === 0 && (
                    <div className={classes.empty}><Trans>No funds recorded for this event yet.</Trans></div>
                )}

                {reconciliation.channels.length > 0 && (
                    <div className={classes.tableWrap}>
                        <div className={`${classes.row} ${classes.headerRow}`}>
                            <div className={classes.colChannel}><Trans>Channel</Trans></div>
                            <div className={classes.colNum}><Trans>Sales</Trans></div>
                            <div className={classes.colNum}><Trans>Donations</Trans></div>
                            <div className={classes.colNum}><Trans>Refunds</Trans></div>
                            <div className={classes.colFee}><Trans>Fees</Trans></div>
                            <div className={classes.colNum}><Trans>Net deposit</Trans></div>
                            <div className={classes.colShare}><Trans>Share</Trans></div>
                        </div>

                        {reconciliation.channels.map((channel: EventReconciliationChannel) => (
                            <div className={classes.row} key={channel.channel}>
                                <div className={classes.colChannel}>
                                    <span className={classes.dot} style={{backgroundColor: CHANNEL_COLORS[channel.channel]}}/>
                                    {channelLabel(channel.channel)}
                                </div>
                                <div className={classes.colNum}>{money(channel.sales)}</div>
                                <div className={classes.colNum}>{channel.donations ? money(channel.donations) : '—'}</div>
                                <div className={classes.colNum}>{channel.refunds ? `− ${money(channel.refunds)}` : '—'}</div>
                                <div className={classes.colFee}>
                                    <NumberInput
                                        size="xs"
                                        value={feeDrafts[channel.channel] ?? 0}
                                        onChange={(value) => setFeeDrafts((prev) => ({...prev, [channel.channel]: value}))}
                                        min={0}
                                        decimalScale={2}
                                        fixedDecimalScale
                                        prefix={getCurrencySymbol(currency)}
                                        disabled={!FEE_BEARING.includes(channel.channel)}
                                        hideControls
                                        classNames={{input: classes.moneyInput}}
                                        styles={{
                                            wrapper: {margin: 0},
                                            root: {maxWidth: 96, marginInlineStart: 'auto', marginBottom: 0, transform: 'translateX(12px)'},
                                            input: {color: Number(feeDrafts[channel.channel] ?? 0) !== 0 ? 'var(--mantine-color-red-7)' : undefined},
                                        }}
                                    />
                                </div>
                                <div className={`${classes.colNum} ${classes.net}`}>{money(channel.net)}</div>
                                <div className={classes.colShare}>{Math.round(channel.share * 100)}%</div>
                            </div>
                        ))}

                        <div className={`${classes.row} ${classes.totalRow}`}>
                            <div className={classes.colChannel}><Trans>Totals</Trans></div>
                            <div className={classes.colNum}>{money(reconciliation.total_received - reconciliation.donations)}</div>
                            <div className={classes.colNum}>{money(reconciliation.donations)}</div>
                            <div className={classes.colNum}>− {money(reconciliation.refunds)}</div>
                            <div
                                className={classes.colFee}
                                style={{color: reconciliation.total_fees !== 0 ? 'var(--mantine-color-red-7)' : undefined}}
                            >
                                {reconciliation.total_fees === 0 ? '—' : money(reconciliation.total_fees)}
                            </div>
                            <div className={`${classes.colNum} ${classes.net}`}>{money(reconciliation.net_to_bank)}</div>
                            <div className={classes.colShare}>100%</div>
                        </div>
                    </div>
                )}

                <div className={classes.footer}>
                    <div className={classes.credits}>
                        <Trans>Non-cash credits (no money in the door):</Trans>{' '}
                        <strong>{money(reconciliation.comps)}</strong> <Trans>comps</Trans>
                        {reconciliation.write_offs > 0 && (
                            <> · <strong>{money(reconciliation.write_offs)}</strong> <Trans>write-offs</Trans></>
                        )}
                    </div>
                    <div className={classes.saveBar}>
                        {reconciliation.fees_updated_at && (
                            <span className={classes.stamp}>
                                <Trans>Fees updated {formatDateWithLocale(reconciliation.fees_updated_at, 'shortDate', timezone ?? 'UTC')}</Trans>
                            </span>
                        )}
                        <Button
                            size="sm"
                            variant="light"
                            radius="md"
                            disabled={!isDirty}
                            loading={updateFees.isPending}
                            onClick={handleSave}
                        >
                            <Trans>Save fees</Trans>
                        </Button>
                    </div>
                </div>
            </Card>
            </div>

            <div className={classes.sideColumn}>
            {children}
            </div>
        </div>
    );
};
