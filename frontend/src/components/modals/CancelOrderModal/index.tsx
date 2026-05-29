import {GenericModalProps, IdParam,} from "../../../types.ts";
import {useParams} from "react-router";
import {useGetEvent} from "../../../queries/useGetEvent.ts";
import {useGetOrder} from "../../../queries/useGetOrder.ts";
import {Modal} from "../../common/Modal";
import {Alert, Button, Checkbox, LoadingOverlay} from "@mantine/core";
import {IconInfoCircle} from "@tabler/icons-react";
import classes from './CancelOrderModal.module.scss';
import {OrderDetails} from "../../common/OrderDetails";
import {AttendeeList} from "../../common/AttendeeList";
import {t} from "@lingui/macro";
import {useCancelOrder} from "../../../mutations/useCancelOrder.ts";
import {showError, showSuccess} from "../../../utilites/notifications.tsx";
import {useState} from "react";

interface RefundOrderModalProps extends GenericModalProps {
    orderId: IdParam,
}

export const CancelOrderModal = ({onClose, orderId}: RefundOrderModalProps) => {
    const {eventId} = useParams();
    const {data: order} = useGetOrder(eventId, orderId);
    const {data: event, data: {products} = {}} = useGetEvent(eventId);
    const cancelOrderMutation = useCancelOrder();
    const [shouldRefund, setShouldRefund] = useState(true);
    const [notifyBuyer, setNotifyBuyer] = useState(false);
    const [notifyRefund, setNotifyRefund] = useState(false);

    const isRefundable = order && !order.is_free_order
        && order.status !== 'AWAITING_OFFLINE_PAYMENT'
        && order.payment_provider === 'STRIPE'
        && order.refund_status !== 'REFUNDED';

    const willRefund = shouldRefund && isRefundable;

    const handleCancelOrder = () => {
        cancelOrderMutation.mutate({
            eventId,
            orderId,
            refund: willRefund,
            notifyBuyer,
            notifyRefund: willRefund && notifyRefund,
        }, {
            onSuccess: () => {
                const message = willRefund
                    ? t`Order has been canceled and refunded.`
                    : t`Order has been canceled.`;
                showSuccess(message);
                onClose();
            },
            onError: (error: any) => {
                showError(error?.response?.data?.message || t`Failed to cancel order`);
            }
        });
    }

    if (!order || !event) {
        return <LoadingOverlay visible/>;
    }

    return (
        <Modal
            heading={t`Cancel Order ${order.public_id}`}
            opened
            onClose={onClose}
        >
            <OrderDetails order={order} event={event}/>

            {products && <AttendeeList order={order} products={products}/>}

            <Alert className={classes.alert} variant="light" color="blue" title={t`Please Note`}
                   icon={<IconInfoCircle/>}>
                {t`Canceling will cancel all attendees associated with this order, and release the tickets back into the available pool.`}
            </Alert>

            {isRefundable && (
                <Checkbox
                    mt={20}
                    checked={shouldRefund}
                    onChange={(event) => setShouldRefund(event.currentTarget.checked)}
                    label={t`Also refund this order`}
                    description={t`The full order amount will be refunded to the customer's original payment method.`}
                />
            )}

            <Checkbox
                mt={20}
                checked={notifyBuyer}
                onChange={(event) => setNotifyBuyer(event.currentTarget.checked)}
                label={t`Send cancellation email`}
                description={t`Email the order owner to let them know their order was cancelled. Off by default to avoid surprise emails.`}
            />

            {willRefund && (
                <Checkbox
                    mt={20}
                    mb={20}
                    checked={notifyRefund}
                    onChange={(event) => setNotifyRefund(event.currentTarget.checked)}
                    label={t`Send refund email`}
                    description={t`Email the order owner a refund notification. Off by default to avoid surprise emails.`}
                />
            )}

            <Button loading={cancelOrderMutation.isPending} className={'mb20 mt20'} color={'red'} fullWidth
                    onClick={handleCancelOrder}>
                {t`Cancel Order`}
            </Button>
        </Modal>
    )
};
