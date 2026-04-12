import {PageTitle} from "../../../common/PageTitle";
import {t} from "@lingui/macro";
import {PageBody} from "../../../common/PageBody";
import {Alert, Badge, Table, Text} from "@mantine/core";
import {Card} from "../../../common/Card";
import {useParams} from "react-router";
import {useGetTransactionMessageFailures} from "../../../../queries/useGetTransactionMessageFailures.ts";
import {relativeDate} from "../../../../utilites/dates.ts";
import {Pagination} from "../../../common/Pagination";
import {useState} from "react";
import {TableSkeleton} from "../../../common/TableSkeleton";
import classes from "./DeliveryIssues.module.scss";

const statusColor = (status: string) => {
    switch (status?.toUpperCase()) {
        case 'BOUNCED':
            return 'red';
        case 'FAILED':
            return 'orange';
        case 'SUPPRESSED':
            return 'gray';
        default:
            return 'blue';
    }
};

const emailTypeLabel = (emailType: string) => {
    switch (emailType) {
        case 'order_summary':
            return t`Order Confirmation`;
        case 'order_failed':
            return t`Order Failed`;
        case 'attendee_ticket':
            return t`Attendee Ticket`;
        case 'waitlist_offer':
            return t`Waitlist Offer`;
        case 'waitlist_confirmation':
            return t`Waitlist Confirmation`;
        case 'waitlist_offer_expired':
            return t`Waitlist Offer Expired`;
        default:
            return emailType;
    }
};

const DeliveryIssues = () => {
    const {eventId} = useParams();
    const [page, setPage] = useState(1);
    const failuresQuery = useGetTransactionMessageFailures(eventId, {pageNumber: page, perPage: 20});
    const failures = failuresQuery.data?.data;
    const pagination = failuresQuery.data?.meta;

    return (
        <PageBody>
            <PageTitle
                subheading={t`Transactional emails that failed to deliver, bounced, or were suppressed.`}
            >
                {t`Delivery Issues`}
            </PageTitle>

            {failuresQuery.isLoading && (
                <Card>
                    <TableSkeleton isVisible/>
                </Card>
            )}

            {!!failuresQuery.error && (
                <Alert color="red" radius="md">
                    {t`Failed to load delivery issues`}
                </Alert>
            )}

            {!failuresQuery.isLoading && !failuresQuery.error && failures && failures.length === 0 && (
                <Card>
                    <div className={classes.emptyState}>
                        <Text c="dimmed">{t`No delivery issues found. All transactional emails have been delivered successfully.`}</Text>
                    </div>
                </Card>
            )}

            {!failuresQuery.isLoading && !failuresQuery.error && failures && failures.length > 0 && (
                <Card>
                    <Table striped highlightOnHover>
                        <Table.Thead>
                            <Table.Tr>
                                <Table.Th>{t`Recipient`}</Table.Th>
                                <Table.Th>{t`Email Type`}</Table.Th>
                                <Table.Th>{t`Subject`}</Table.Th>
                                <Table.Th>{t`Status`}</Table.Th>
                                <Table.Th>{t`Date`}</Table.Th>
                            </Table.Tr>
                        </Table.Thead>
                        <Table.Tbody>
                            {failures.map((failure) => (
                                <Table.Tr key={String(failure.id)}>
                                    <Table.Td>{failure.recipient}</Table.Td>
                                    <Table.Td>{emailTypeLabel(failure.email_type)}</Table.Td>
                                    <Table.Td>{failure.subject}</Table.Td>
                                    <Table.Td>
                                        <Badge size="sm" color={statusColor(failure.status)} variant="filled">
                                            {failure.status}
                                        </Badge>
                                    </Table.Td>
                                    <Table.Td>{relativeDate(failure.created_at)}</Table.Td>
                                </Table.Tr>
                            ))}
                        </Table.Tbody>
                    </Table>

                    {pagination && Number(pagination.last_page) > 1 && (
                        <Pagination
                            value={page}
                            onChange={setPage}
                            total={Number(pagination.last_page)}
                            size="sm"
                        />
                    )}
                </Card>
            )}
        </PageBody>
    );
};

export default DeliveryIssues;
