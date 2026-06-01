import React, {useState} from "react";
import {useParams} from "react-router";
import {Button} from "@mantine/core";
import {IconDownload} from "@tabler/icons-react";
import {t} from "@lingui/macro";
import {useGetEvent} from "../../../queries/useGetEvent";
import {useGetEventOrders} from "../../../queries/useGetEventOrders";
import {useGetEventProductCategories} from "../../../queries/useGetProductCategories";
import {PageTitle} from "../../common/PageTitle";
import {PageBody} from "../../common/PageBody";
import {OrdersTable} from "../../common/OrdersTable";
import {SearchBarWrapper} from "../../common/SearchBar";
import {Pagination} from "../../common/Pagination";
import {ToolBar} from "../../common/ToolBar";
import {useFilterQueryParamSync} from "../../../hooks/useFilterQueryParamSync";
import {useEscapeClearsFilters} from "../../../hooks/useEscapeClearsFilters";
import {IdParam, QueryFilterCondition, QueryFilterOperator, QueryFilters} from "../../../types";
import {TableSkeleton} from "../../common/TableSkeleton";
import {orderClient} from "../../../api/order.client";
import {downloadBinary} from "../../../utilites/download";
import {FilterModal, FilterOption} from "../../common/FilterModal";
import {withLoadingNotification} from "../../../utilites/withLoadingNotification.tsx";

const orderStatuses = [
    {label: t`Completed`, value: 'COMPLETED'},
    {label: t`Cancelled`, value: 'CANCELLED'},
    {label: t`Awaiting Offline Payment`, value: 'AWAITING_OFFLINE_PAYMENT'},
];

const refundStatuses = [
    {label: t`Refunded`, value: 'REFUNDED'},
    {label: t`Partially Refunded`, value: 'PARTIALLY_REFUNDED'},
];

const paymentTypes = [
    {label: t`Cash`, value: 'CASH'},
    {label: t`Check`, value: 'CHECK'},
    {label: t`Card`, value: 'CREDIT_CARD'},
    {label: t`Bank transfer`, value: 'BANK_TRANSFER'},
    {label: t`Other`, value: 'OTHER'},
    {label: t`Comp`, value: 'COMP'},
    {label: t`Donation`, value: 'DONATION'},
    {label: t`Stripe`, value: 'STRIPE'},
];

export const Orders: React.FC = () => {
    const {eventId} = useParams<{ eventId: string }>();
    const {data: event} = useGetEvent(eventId);
    const [searchParams, setSearchParams] = useFilterQueryParamSync();
    const ordersQuery = useGetEventOrders(eventId, searchParams as QueryFilters);
    const orders = ordersQuery?.data?.data;
    const pagination = ordersQuery?.data?.meta;
    const productCategoriesQuery = useGetEventProductCategories(eventId);
    const productCategories = productCategoriesQuery.data?.data ?? [];
    const productOptions = productCategories
        .flatMap(category => category.products ?? [])
        .filter(product => product.id !== undefined)
        .map(product => ({label: product.title, value: String(product.id)}));
    const [downloadPending, setDownloadPending] = useState(false);

    const filterOptions: FilterOption[] = [
        {
            field: 'status',
            label: t`Order Status`,
            type: 'multi-select',
            options: orderStatuses
        },
        {
            field: 'refund_status',
            label: t`Refund Status`,
            type: 'multi-select',
            options: refundStatuses
        },
        {
            field: 'product_id',
            label: t`Tickets / Products`,
            type: 'multi-select',
            options: productOptions
        },
        {
            field: 'payment_type',
            label: t`Payment Type`,
            type: 'multi-select',
            options: paymentTypes
        }
    ];

    const handleFilterChange = (values: Record<string, string[]>) => {
        const newFilters = {
            ...searchParams,
            filterFields: {
                ...(searchParams.filterFields || {}),
                status: values.status?.length > 0
                    ? {operator: QueryFilterOperator.In, value: values.status}
                    : undefined,
                refund_status: values.refund_status?.length > 0
                    ? {operator: QueryFilterOperator.In, value: values.refund_status}
                    : undefined,
                product_id: values.product_id?.length > 0
                    ? {operator: QueryFilterOperator.In, value: values.product_id}
                    : undefined,
                payment_type: values.payment_type?.length > 0
                    ? {operator: QueryFilterOperator.In, value: values.payment_type}
                    : undefined
            }
        };

        setSearchParams(newFilters as QueryFilters, true); // Added true to replace instead of merge
    };

    const handleResetFilters = () => {
        const clearedFilters = {
            ...searchParams,
            filterFields: {}
        };
        setSearchParams(clearedFilters as QueryFilters, true); // Added true to replace instead of merge
    };

    useEscapeClearsFilters({
        steps: [
            {
                isActive: () => !!searchParams.query,
                clear: () => setSearchParams({query: '', pageNumber: 1}),
            },
            {
                isActive: () => Object.keys(searchParams.filterFields || {}).length > 0,
                clear: handleResetFilters,
            },
        ],
    });

    const handleExport = async (eventId: IdParam) => {
        await withLoadingNotification(async () => {
                setDownloadPending(true);
                const blob = await orderClient.exportOrders(eventId);
                downloadBinary(blob, 'orders.xlsx');
            },
            {
                loading: {
                    title: t`Exporting Orders`,
                    message: t`Please wait while we prepare your orders for export...`
                },
                success: {
                    title: t`Orders Exported`,
                    message: t`Your orders have been exported successfully.`,
                    onRun: () => setDownloadPending(false)
                },
                error: {
                    title: t`Failed to export orders`,
                    message: t`Please try again.`,
                    onRun: () => setDownloadPending(false)
                }
            });
    };

    // filterFields entries are always set as single conditions here (not arrays),
    // but the type allows both — cast through the single-condition shape to keep
    // .value access type-safe.
    const filterFields = searchParams.filterFields as Record<string, QueryFilterCondition | undefined> | undefined;
    const currentFilters = {
        status: filterFields?.status?.value || [],
        refund_status: filterFields?.refund_status?.value || [],
        product_id: filterFields?.product_id?.value || [],
        payment_type: filterFields?.payment_type?.value || []
    };

    return (
        <PageBody>
            <PageTitle
                subheading={t`View order details, issue refunds, and resend confirmations.`}
            >{t`Orders`}</PageTitle>
            <ToolBar
                filterComponent={
                    <FilterModal
                        filters={filterOptions}
                        activeFilters={currentFilters}
                        onChange={handleFilterChange}
                        onReset={handleResetFilters}
                        title={t`Filter Orders`}
                    />
                }
                searchComponent={() => (
                    <SearchBarWrapper
                        placeholder={t`Search by name, email, or order #...`}
                        setSearchParams={setSearchParams}
                        searchParams={searchParams}
                        pagination={pagination}
                    />
                )}
            >
                <Button
                    onClick={() => handleExport(eventId)}
                    rightSection={<IconDownload size={14}/>}
                    color="green"
                    loading={downloadPending}
                    size="sm"
                >
                    {t`Export`}
                </Button>
            </ToolBar>

            <TableSkeleton isVisible={!orders || ordersQuery.isFetching}/>

            {orders && event && (
                <OrdersTable event={event} orders={orders}/>
            )}

            {!!orders?.length && (
                <Pagination
                    value={searchParams.pageNumber}
                    onChange={(value) => setSearchParams({pageNumber: value})}
                    total={Number(pagination?.last_page)}
                />
            )}
        </PageBody>
    );
};

export default Orders;
