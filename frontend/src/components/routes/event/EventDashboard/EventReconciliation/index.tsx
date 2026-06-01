import {useEffect, useState} from "react";
import {t, Trans} from "@lingui/macro";
import {Button, NumberInput, Skeleton} from "@mantine/core";
import {IconDownload} from "@tabler/icons-react";
import {Card} from "../../../../common/Card";
import classes from "./EventReconciliation.module.scss";
import {useGetEventReconciliation} from "../../../../../queries/useGetEventReconciliation.ts";
import {useUpdateEventChannelFees} from "../../../../../mutations/useUpdateEventChannelFees.ts";
import {formatCurrency, getCurrencySymbol} from "../../../../../utilites/currency.ts";
import {showError, showSuccess} from "../../../../../utilites/notifications.tsx";
import {ChannelFeeInput, EventReconciliationChannel, IdParam, PaymentChannel} from "../../../../../types.ts";
import {formatDateWithLocale} from "../../../../../utilites/dates.ts";
import {orderClient} from "../../../../../api/order.client.ts";
import {downloadBinary} from "../../../../../utilites/download.ts";
import {withLoadingNotification} from "../../../../../utilites/withLoadingNotification.tsx";

interface EventReconciliationProps {
    eventId: IdParam;
    timezone?: string;
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

export const EventReconciliation = ({eventId, timezone}: EventReconciliationProps) => {
    const {data: reconciliation, isLoading} = useGetEventReconciliation(eventId);
    const updateFees = useUpdateEventChannelFees();

    const [feeDrafts, setFeeDrafts] = useState<Record<string, number | string>>({});
    const [exportPending, setExportPending] = useState(false);

    const handleExport = async () => {
        await withLoadingNotification(async () => {
                setExportPending(true);
                const blob = await orderClient.exportOrders(eventId);
                downloadBinary(blob, 'orders.xlsx');
            },
            {
                loading: {
                    title: t`Exporting orders`,
                    message: t`Please wait while we prepare your orders for export...`,
                },
                success: {
                    title: t`Orders exported`,
                    message: t`Your orders have been exported successfully.`,
                    onRun: () => setExportPending(false),
                },
                error: {
                    title: t`Failed to export orders`,
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
        }
    }, [reconciliation]);

    if (isLoading || !reconciliation) {
        return <Skeleton height={420} radius="l" mb="20px"/>;
    }

    const currency = reconciliation.currency;
    const money = (value: number) => formatCurrency(value, currency);
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

    const waterfall = [
        {label: t`Gross sales`, value: reconciliation.gross_sales, sign: '+' as const, base: true},
        {label: t`Refunds`, value: reconciliation.refunds, sign: '−' as const},
        {label: t`Comps (forgiven)`, value: reconciliation.comps + reconciliation.write_offs, sign: '−' as const},
        {label: t`Donations`, value: reconciliation.donations, sign: '+' as const},
    ];
    const maxWaterfall = Math.max(reconciliation.gross_sales, 1);

    return (
        <div className={classes.cardRow}>
            <Card className={classes.card}>
                <div className={classes.cardTitle}>
                    <h2><Trans>Event Reconciliation</Trans></h2>
                    <Button
                        size="xs"
                        variant="light"
                        radius="md"
                        loading={exportPending}
                        onClick={handleExport}
                        rightSection={<IconDownload size={14}/>}
                    >
                        <Trans>Export orders</Trans>
                    </Button>
                </div>

                <div className={classes.tiles}>
                    <div className={classes.tile}>
                        <div className={classes.tileLabel}><Trans>Net expected funds</Trans></div>
                        <div className={classes.tileNumber}>{money(reconciliation.net_expected_funds)}</div>
                        <div className={classes.tileSub}><Trans>what we "sold"</Trans></div>
                    </div>
                    <div className={classes.tile}>
                        <div className={classes.tileLabel}><Trans>Collected to date</Trans></div>
                        <div className={classes.tileNumber}>{money(reconciliation.collected)}</div>
                        <div className={classes.progressTrack}>
                            <div className={classes.progressFill} style={{width: `${collectedPercent}%`}}/>
                        </div>
                        <div className={classes.tileSub}>
                            <Trans>{collectedPercent}% received</Trans>
                        </div>
                    </div>
                    <div className={classes.tile}>
                        <div className={classes.tileLabel}><Trans>Net to bank</Trans></div>
                        <div className={classes.tileNumber}>{money(reconciliation.net_to_bank)}</div>
                        <div className={classes.tileSub}><Trans>est. after fees</Trans></div>
                    </div>
                </div>

                <div className={classes.waterfall}>
                    <div className={classes.waterfallHeading}><Trans>How we get there</Trans></div>
                    {waterfall.map((row) => (
                        <div className={classes.waterfallRow} key={row.label}>
                            <div className={classes.waterfallLabel}>{row.label}</div>
                            <div className={classes.waterfallBarTrack}>
                                <div
                                    className={`${classes.waterfallBar} ${row.sign === '−' ? classes.barNegative : ''}`}
                                    style={{width: `${Math.min(100, (row.value / maxWaterfall) * 100)}%`}}
                                />
                            </div>
                            <div className={classes.waterfallValue}>
                                {row.sign}&nbsp;{money(row.value)}
                            </div>
                        </div>
                    ))}
                    <div className={`${classes.waterfallRow} ${classes.waterfallTotal}`}>
                        <div className={classes.waterfallLabel}><Trans>Net expected funds</Trans></div>
                        <div className={classes.waterfallBarTrack}/>
                        <div className={classes.waterfallValue}>= {money(reconciliation.net_expected_funds)}</div>
                    </div>
                    <div className={classes.waterfallRow}>
                        <div className={classes.waterfallLabel}><Trans>Processing fees</Trans></div>
                        <div className={classes.waterfallBarTrack}/>
                        <div className={classes.waterfallValue}>− {money(reconciliation.total_fees)}</div>
                    </div>
                    <div className={`${classes.waterfallRow} ${classes.waterfallTotal}`}>
                        <div className={classes.waterfallLabel}><Trans>Net to bank (est.)</Trans></div>
                        <div className={classes.waterfallBarTrack}/>
                        <div className={classes.waterfallValue}>= {money(reconciliation.net_to_bank)}</div>
                    </div>
                </div>
            </Card>

            <Card className={classes.card}>
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
                                        styles={{input: {textAlign: 'right'}}}
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
                            <div className={classes.colFee}>{money(reconciliation.total_fees)}</div>
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
    );
};
