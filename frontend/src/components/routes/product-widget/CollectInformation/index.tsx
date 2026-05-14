import {useMutation} from "@tanstack/react-query";
import {FinaliseOrderPayload, orderClientPublic} from "../../../../api/order.client.ts";
import {useNavigate, useParams, useSearchParams} from "react-router";
import {
    Button,
    Checkbox,
    Collapse,
    NativeSelect,
    SegmentedControl,
    Skeleton,
    Text,
    TextInput,
    Tooltip,
    UnstyledButton
} from "@mantine/core";
import {IconArrowRight, IconCheck, IconChevronDown, IconCircleCheck, IconClock, IconInfoCircle, IconTicket} from "@tabler/icons-react";
import {t, Trans} from "@lingui/macro";
import {useForm} from "@mantine/form";
import {notifications} from "@mantine/notifications";
import {useGetOrderPublic} from "../../../../queries/useGetOrderPublic.ts";
import {useGetEventPublic} from "../../../../queries/useGetEventPublic.ts";
import {useGetEventQuestionsPublic} from "../../../../queries/useGetEventQuestionsPublic.ts";
import {CheckoutOrderQuestions, CheckoutProductQuestions} from "../../../common/CheckoutQuestion";
import {Event, IdParam, Question} from "../../../../types.ts";
import {contactClientPublic} from "../../../../api/contact-public.client.ts";
import {useTurnstile, isLocalTurnstileFresh, markLocalTurnstileFresh} from "../../../../hooks/useTurnstile.ts";
import {useEffect, useRef, useState} from "react";
import {InputGroup} from "../../../common/InputGroup";
import {Card} from "../../../common/Card";
import {CheckoutContent} from "../../../layouts/Checkout/CheckoutContent";
import {getConfig} from "../../../../utilites/config.ts";
import {HomepageInfoMessage} from "../../../common/HomepageInfoMessage";
import {InlineOrderSummary} from "../../../common/InlineOrderSummary";
import {eventCheckoutPath, eventHomepagePath} from "../../../../utilites/urlHelper.ts";
import {showInfo} from "../../../../utilites/notifications.tsx";
import countries from "../../../../../data/countries.json";
import classes from "./CollectInformation.module.scss";
import {trackEvent, AnalyticsEvents} from "../../../../utilites/analytics.ts";
import {clearWaitlistJoinedForEvent} from "../../../../hooks/useWaitlistJoined.ts";

const LoadingSkeleton = () =>
    (
        <CheckoutContent>
            <Skeleton mb={20} height={200}/>
            <Skeleton mb={20} height={200}/>
            <Skeleton mb={20} height={200}/>
        </CheckoutContent>
    );

export const CollectInformation = () => {
    const {eventId, orderShortId} = useParams();
    const navigate = useNavigate();
    const [searchParams] = useSearchParams();
    const isFromWaitlist = searchParams.get('waitlist') === 'true';
    const {
        isFetched: isOrderFetched,
        data: order,
        data: {order_items: orderItems} = {},
        isError: isOrderError,
        error: orderError,
    } = useGetOrderPublic(eventId, orderShortId, ['event']);
    const {
        data: event,
        data: {product_categories: productCategories} = {},
        isFetched: isEventFetched,
        isError: isEventError,
    } = useGetEventPublic(eventId, isOrderFetched, !!order?.promo_code, order?.promo_code ?? null);
    const {
        data: questions,
        isFetched: isQuestionsFetched,
        isError: isQuestionsError
    } = useGetEventQuestionsPublic(eventId);
    const productQuestions = questions?.filter(question => question.belongs_to === "PRODUCT");
    const orderQuestions = questions?.filter(question => question.belongs_to === "ORDER");
    const products = productCategories?.flatMap(category => category.products);
    const requireBillingAddress = event?.settings?.require_billing_address;
    const isPerOrderCollection = event?.settings?.attendee_details_collection_method === 'PER_ORDER';
    const [copyOption, setCopyOption] = useState<'none' | 'first' | 'all'>('none');
    const hasAutoAppliedBundleDefault = useRef(false);
    const [expandedBundles, setExpandedBundles] = useState<Record<string, boolean>>({});

    const isEmailValid = (email: string) => {
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return emailRegex.test(email);
    };

    const EmailCheckIcon = () => (
        <IconCircleCheck size={18} style={{color: 'var(--primary-color, #10B981)'}}/>
    );

    let productIndex = 0;

    const form = useForm({
        initialValues: {
            order: {
                first_name: "",
                last_name: "",
                email: "",
                email_confirmation: "",
                address: {},
                questions: {},
                opted_into_marketing: false,
            },
            products: [{
                first_name: "",
                last_name: "",
                email: "",
                email_confirmation: "",
                product_price_id: "",
                product_id: "",
                questions: {},
            }],
        },
        validate: {
            order: {
                email_confirmation: (value, values) =>
                    (value ?? '').trim().toLowerCase() !== (values.order.email ?? '').trim().toLowerCase()
                        ? t`Email addresses do not match`
                        : null,
            },
            products: {
                email_confirmation: (value, values, path) => {
                    const index = parseInt(path.split('.')[1]);
                    const product = values.products[index];
                    if (product && (product.email ?? '').trim().toLowerCase() !== (value ?? '').trim().toLowerCase()) {
                        return t`Email addresses do not match`;
                    }
                    return null;
                },
            },
        },
        validateInputOnBlur: true,
    });

    const getTicketAttendeeIndices = (): number[] => {
        if (!products || !form.values.products) return [];

        const attendeeProductIds = new Set<IdParam>(
            products
                .filter((product): product is NonNullable<typeof product> => !!product && product.product_type === 'TICKET')
                .map(product => product.id)
        );

        return form.values.products
            .map((product, index) => attendeeProductIds.has(product.product_id) ? index : -1)
            .filter(index => index !== -1);
    };

    const getFirstTicketAttendeeIndex = (): number => {
        const indices = getTicketAttendeeIndices();
        return indices.length > 0 ? indices[0] : -1;
    };

    const totalTicketAttendees = getTicketAttendeeIndices().length;

    const areOrderDetailsComplete = () => {
        const {first_name, last_name, email} = form.values.order;
        return first_name.trim() !== '' && last_name.trim() !== '' && isEmailValid(email);
    };

    const copyDetailsToAttendees = (option: 'none' | 'first' | 'all') => {
        if (!products) return;

        const ticketIndices = getTicketAttendeeIndices();
        if (ticketIndices.length === 0) return;

        const copiedEmail = form.values.order.email;

        // For bundle products (min_per_order > 1), attendees beyond the first slot of
        // the same product_id get distinguishable "Guest N" placeholders instead of
        // duplicate buyer names. Buyer's email still copies to all so the buyer
        // (or admin) can resend/forward later from the "Your Order" page.
        const firstIndexByProductId = new Map<any, number>();
        form.values.products.forEach((p, i) => {
            if (!firstIndexByProductId.has(p.product_id)) {
                firstIndexByProductId.set(p.product_id, i);
            }
        });
        const isBundleProduct = (productId: any) => {
            const catalogProduct = products.find(cp => !!cp && String(cp.id) === String(productId));
            return (catalogProduct?.min_per_order ?? 1) > 1;
        };

        const updatedProducts = form.values.products.map((product, index) => {
            const isTicketAttendee = ticketIndices.includes(index);
            const isFirst = index === ticketIndices[0];
            const shouldCopy = option === 'all' ? isTicketAttendee : (option === 'first' && isFirst);

            if (isTicketAttendee) {
                if (shouldCopy) {
                    const firstIndexOfThisProduct = firstIndexByProductId.get(product.product_id);
                    const isBundleSibling = option === 'all'
                        && isBundleProduct(product.product_id)
                        && firstIndexOfThisProduct !== undefined
                        && index !== firstIndexOfThisProduct;

                    if (isBundleSibling) {
                        const guestNumber = index - (firstIndexOfThisProduct ?? 0) + 1;
                        return {
                            ...product,
                            first_name: `Guest ${guestNumber}`,
                            last_name: "",
                            email: form.values.order.email,
                            email_confirmation: form.values.order.email,
                        };
                    }

                    return {
                        ...product,
                        first_name: form.values.order.first_name,
                        last_name: form.values.order.last_name,
                        email: form.values.order.email,
                        email_confirmation: form.values.order.email,
                    };
                } else {
                    return {
                        ...product,
                        first_name: "",
                        last_name: "",
                        email: "",
                        email_confirmation: "",
                    };
                }
            }
            return product;
        });

        form.setValues({
            ...form.values,
            products: updatedProducts,
        });

        // Programmatic setValues doesn't fire onBlur. We trigger the contact
        // lookup for the copied email to compute answered_question_ids, but
        // only apply the *hidden question IDs* — the name/email values are
        // already in place from the copy above. Calling applyContactToProduct
        // here would race with the setValues state commit above and clobber
        // the copied email back to blank.
        const copiedIndices =
            option === 'all' ? ticketIndices :
            option === 'first' && ticketIndices.length > 0 ? [ticketIndices[0]] :
            [];

        if (option !== 'none' && isEmailValid(copiedEmail)) {
            void fetchLookup(copiedEmail).then((result) => {
                if (!result) return;
                setProductHiddenQuestionIds(prev => {
                    const next = {...prev};
                    copiedIndices.forEach((idx) => {
                        next[idx] = result.answered_question_ids ?? [];
                    });
                    return next;
                });
            });
        } else if (option === 'none') {
            // Uncopying: clear hidden-question state so questions re-render if
            // the attendee types a different email.
            setProductHiddenQuestionIds(prev => {
                const next = {...prev};
                ticketIndices.forEach((idx) => { delete next[idx]; });
                return next;
            });
        }
    };

    const handleCopyOptionChange = (value: string) => {
        const option = value as 'none' | 'first' | 'all';

        // Only allow copying if order details are complete
        if (option !== 'none' && !areOrderDetailsComplete()) {
            return;
        }

        setCopyOption(option);
        copyDetailsToAttendees(option);
    };

    // Reset copy option if order details become incomplete
    useEffect(() => {
        if (copyOption !== 'none' && !areOrderDetailsComplete()) {
            setCopyOption('none');
            copyDetailsToAttendees('none');
            hasAutoAppliedBundleDefault.current = false;
        }
    }, [form.values.order.first_name, form.values.order.last_name, form.values.order.email]);

    // When the cart contains a bundle product (min_per_order > 1) and the buyer
    // has filled in their own details, auto-default copyOption to "all" so each
    // bundled seat gets a "Guest N" placeholder name + the buyer's email. The
    // buyer can override individual fields or pick a different copy option.
    // Fires once per session to avoid overriding a buyer's manual choice.
    useEffect(() => {
        if (hasAutoAppliedBundleDefault.current) return;
        if (copyOption !== 'none') return;
        if (!products || !areOrderDetailsComplete()) return;

        const hasBundle = form.values.products.some(p => {
            const catalog = products.find(cp => !!cp && String(cp.id) === String(p.product_id));
            return (catalog?.min_per_order ?? 1) > 1;
        });

        if (!hasBundle) return;

        setCopyOption('all');
        copyDetailsToAttendees('all');
        hasAutoAppliedBundleDefault.current = true;
    }, [
        form.values.order.first_name,
        form.values.order.last_name,
        form.values.order.email,
        products,
    ]);

    const applyContactToOrder = (result: {first_name: string | null; last_name: string | null}) => {
        const current = form.values.order;
        const updates: any = {};
        if (!current.first_name?.trim() && result.first_name) updates.first_name = result.first_name;
        if (!current.last_name?.trim() && result.last_name) updates.last_name = result.last_name;
        if (!current.email_confirmation?.trim() && current.email) updates.email_confirmation = current.email;

        if (Object.keys(updates).length === 0) return;

        form.setValues({
            ...form.values,
            order: {
                ...current,
                ...updates,
            },
        });
    };

    const applyContactToProduct = (productIndex: number, result: {first_name: string | null; last_name: string | null}) => {
        const current = form.values.products[productIndex];
        if (!current) return;
        const updates: any = {};
        if (!current.first_name?.trim() && result.first_name) updates.first_name = result.first_name;
        if (!current.last_name?.trim() && result.last_name) updates.last_name = result.last_name;
        if (!current.email_confirmation?.trim() && current.email) updates.email_confirmation = current.email;

        if (Object.keys(updates).length === 0) return;

        const updatedProducts = form.values.products.map((p, i) =>
            i === productIndex ? {...p, ...updates} : p
        );

        form.setValues({
            ...form.values,
            products: updatedProducts,
        });
    };

    const {getToken: getTurnstileToken} = useTurnstile();
    // Questions the returning contact has already answered — rendered hidden
    // on the form; the backend fills them from the contact's stored attributes.
    // Scoped separately for the order and each attendee so one lookup can't
    // clobber another's hidden set (different contacts may have answered
    // different questions).
    const [orderHiddenQuestionIds, setOrderHiddenQuestionIds] = useState<number[]>([]);
    const [productHiddenQuestionIds, setProductHiddenQuestionIds] = useState<Record<number, number[]>>({});
    const lookupCacheRef = useRef<Map<string, any | null>>(new Map());

    const prewarmTurnstile = () => {
        if (isLocalTurnstileFresh()) return;
        void getTurnstileToken();
    };

    // Fetch-only helper: returns the lookup result (or null) and populates
    // the cache. Does NOT mutate form state — caller decides what to apply.
    const fetchLookup = async (email: string): Promise<any | null> => {
        const key = email.trim().toLowerCase();
        if (!key || !isEmailValid(key) || !eventId) return null;
        if (lookupCacheRef.current.has(key)) {
            return lookupCacheRef.current.get(key) ?? null;
        }
        try {
            const turnstileToken = isLocalTurnstileFresh() ? null : await getTurnstileToken();
            const result = await contactClientPublic.lookupByEmail(Number(eventId), key, turnstileToken);
            markLocalTurnstileFresh();
            lookupCacheRef.current.set(key, result.found ? result : null);
            return result.found ? result : null;
        } catch {
            // swallow — autofill failures should not block checkout
            return null;
        }
    };

    const runLookup = async (email: string, apply: (r: any) => void) => {
        const result = await fetchLookup(email);
        if (result) apply(result);
    };

    const handleOrderEmailBlur = () => {
        void runLookup(form.values.order.email ?? '', (r) => {
            applyContactToOrder(r);
            setOrderHiddenQuestionIds(r.answered_question_ids ?? []);
        });
    };

    const handleProductEmailBlur = (idx: number) => {
        const email = form.values.products[idx]?.email ?? '';
        void runLookup(email, (r) => {
            applyContactToProduct(idx, r);
            setProductHiddenQuestionIds(prev => ({...prev, [idx]: r.answered_question_ids ?? []}));
        });
    };

    // Signed-token prefill: if ?c=<token> is in the URL (from an email link),
    // fetch the full contact profile including question answers and fill the
    // form. Token proves email ownership so returning values is safe.
    useEffect(() => {
        const token = searchParams.get('c');
        if (!token || !eventId) return;
        let cancelled = false;
        (async () => {
            try {
                const result = await contactClientPublic.prefillFromToken(Number(eventId), token);
                if (cancelled || !result.found) return;

                // Fill name fields on the order and first attendee.
                applyContactToOrder({first_name: result.first_name, last_name: result.last_name});
                if (form.values.products.length > 0) {
                    applyContactToProduct(0, {first_name: result.first_name, last_name: result.last_name});
                }

                // Fill question_answers into form state (only where blank).
                if (result.question_answers && Object.keys(result.question_answers).length > 0) {
                    const currentOrder = form.values.order;
                    const updatedOrderQuestions = (currentOrder.questions as any[] || []).map((q: any) => {
                        const value = result.question_answers?.[String(q.question_id)];
                        if (value === undefined) return q;
                        // keep whatever the user already typed
                        if (q.response && Object.keys(q.response).length > 0) return q;
                        return {...q, response: {answer: value}};
                    });
                    const updatedProducts = (form.values.products as any[]).map((p: any) => ({
                        ...p,
                        questions: (p.questions as any[] || []).map((q: any) => {
                            const value = result.question_answers?.[String(q.question_id)];
                            if (value === undefined) return q;
                            if (q.response && Object.keys(q.response).length > 0) return q;
                            return {...q, response: {answer: value}};
                        }),
                    }));
                    form.setValues({
                        ...form.values,
                        order: {...currentOrder, questions: updatedOrderQuestions},
                        products: updatedProducts,
                    });
                }
                const answered = result.answered_question_ids ?? [];
                setOrderHiddenQuestionIds(answered);
                if (form.values.products.length > 0) {
                    setProductHiddenQuestionIds(prev => ({...prev, 0: answered}));
                }
            } catch {
                // ignore — token invalid, expired, or network failure. User
                // falls back to the normal email-entry flow silently.
            }
        })();
        return () => { cancelled = true; };
    }, [eventId]);

    const mutation = useMutation({
        mutationFn: (orderData: FinaliseOrderPayload) => orderClientPublic.finaliseOrder(Number(eventId), String(orderShortId), orderData),

        onSuccess: (data) => {
            const nextPage = order?.is_payment_required ? 'payment' : 'summary';
            if (nextPage === 'summary') {
                trackEvent(AnalyticsEvents.PURCHASE_COMPLETED_FREE);
            }
            navigate(eventCheckoutPath(eventId, data.data.short_id, nextPage));
        },

        onError: (error: any) => {
            if (error?.response?.data?.errors && Object.keys(error?.response?.data?.errors).length > 0) {
                form.setErrors(error.response.data.errors);
                handleSubmitErrors(error.response.data.errors);
            } else if (error?.response?.data?.message) {
                notifications.show({
                    message: error?.response?.data?.message,
                });

                // if it's a 409, we need to redirect to the event page as the order is no longer valid
                if (error.response.status === 409) {
                    navigate(eventHomepagePath(event as Event));
                }
            }
        }
    });

    const createProductIdToQuestionMap = () => {
        const productIdToQuestionMap = new Map();

        productQuestions?.forEach(question => {
            question.product_ids?.forEach(id => {
                const existingQuestions = productIdToQuestionMap.get(id);
                productIdToQuestionMap.set(
                    id,
                    existingQuestions ? [...existingQuestions, question] : [question]
                );
            });
        });

        return productIdToQuestionMap;
    }

    const createProductsAndQuestions = (productIdToQuestionMap: Map<number, Question[]>) => {
        const products: any = [];

        orderItems?.forEach(orderItem => {
            Array.from(Array(orderItem?.quantity)).map(() => {
                products.push({
                    product_price_id: orderItem?.product_price_id,
                    product_id: orderItem?.product_id,
                    first_name: "",
                    last_name: "",
                    email: "",
                    email_confirmation: "",
                    questions: productIdToQuestionMap.get(orderItem?.product_id)?.map((question: Question) => {
                        return {
                            question_id: question.id,
                            response: {},
                        }
                    })
                });
            });
        });

        return products;
    }

    const createFormOrderQuestions = () => {
        const formOrderQuestions: any = [];

        orderQuestions?.forEach(orderQuestion => {
            formOrderQuestions.push({
                question_id: orderQuestion.id,
                response: {},
            });
        });

        return formOrderQuestions;
    }

    const handleSubmit = (values: any) => {
        mutation.mutate(values);
    };

    const expandAllBundles = () => {
        if (!orderItems || !products) return;
        const next: Record<string, boolean> = {};
        orderItems.forEach((orderItem) => {
            const product = products.find(p => p && p.id === orderItem.product_id);
            const quantity = orderItem.quantity ?? 0;
            const isBundleItem = ((product?.min_per_order ?? 1) > 1) && quantity > 1;
            if (isBundleItem) {
                next[String(orderItem.id)] = true;
            }
        });
        if (Object.keys(next).length > 0) {
            setExpandedBundles(prev => ({...prev, ...next}));
        }
    };

    const handleSubmitErrors = (errors: Record<string, unknown>) => {
        const hasProductError = Object.keys(errors).some(key => key.startsWith('products.'));
        if (hasProductError) {
            expandAllBundles();
        }
    };

    useEffect(() => {
        if (isEventFetched && isOrderFetched && isQuestionsFetched && productQuestions && orderQuestions) {
            const products = createProductsAndQuestions(createProductIdToQuestionMap());
            const formOrderQuestions = createFormOrderQuestions();

            form.setValues({
                ...form.values,
                products: products,
                order: {
                    ...form.values.order,
                    questions: formOrderQuestions,
                },
            });
        }
    }, [isEventFetched, isOrderFetched, isQuestionsFetched]);

    useEffect(() => {
        if ((order && event) && order?.is_expired) {
            showInfo(t`This order has expired. Please start again.`);
            navigate(`/event/${eventId}/${event.slug}`);
        }
    }, [order, event]);

    if (!isEventFetched || !isOrderFetched) {
        return <LoadingSkeleton/>
    }

    if (order?.status === 'ABANDONED') {
        return <HomepageInfoMessage
            status="cancelled"
            message={t`Order was cancelled`}
            subtitle={t`This order was abandoned. You can start a new order anytime.`}
            link={eventHomepagePath(event as Event)}
            linkText={t`Back to Event`}
        />;
    }

    if (order?.payment_status === 'AWAITING_PAYMENT') {
        return <HomepageInfoMessage
            status="awaiting_payment"
            message={t`Waiting for payment`}
            subtitle={t`Complete your payment to secure your tickets.`}
            link={eventCheckoutPath(eventId, orderShortId, 'payment')}
            linkText={t`Complete Payment`}
        />;
    }

    if (order?.status === 'COMPLETED') {
        return <HomepageInfoMessage
            status="success"
            message={t`Order complete`}
            subtitle={t`Your tickets have been confirmed.`}
            link={eventCheckoutPath(eventId, orderShortId, 'summary')}
            linkText={t`View Order Details`}
        />;
    }

    if (order?.status === 'CANCELLED') {
        return <HomepageInfoMessage
            status="cancelled"
            message={t`Order cancelled`}
            subtitle={t`This order was cancelled. You can start a new order anytime.`}
            link={eventHomepagePath(event as Event)}
            linkText={t`Back to Event`}
        />;
    }

    if (isOrderError && orderError?.response?.status === 404) {
        if (isFromWaitlist && eventId) {
            clearWaitlistJoinedForEvent(eventId);
        }

        return (
            <HomepageInfoMessage
                status="not_found"
                message={isFromWaitlist ? t`Waitlist offer expired` : t`Order not found`}
                subtitle={isFromWaitlist
                    ? t`Your waitlist offer has expired and we were unable to complete your order. Please rejoin the waitlist to be notified when more spots become available.`
                    : t`We couldn't find this order. It may have been removed.`}
                link={eventHomepagePath(event as Event)}
                linkText={t`Go to Event Page`}
            />
        );
    }

    if (isOrderError || isEventError || isQuestionsError) {
        return (
            <HomepageInfoMessage
                status="error"
                message={t`Something went wrong`}
                subtitle={t`We hit a snag loading this page. Please try again.`}
                link={eventHomepagePath(event as Event)}
                linkText={t`Back to Event`}
            />
        );
    }

    const orderRequiresAttendeeDetails = orderItems?.some(orderItem => {
        const product = products?.find(product => product!.id === orderItem.product_id);
        return product?.product_type === 'TICKET';
    });

    return (
        <form onSubmit={form.onSubmit(handleSubmit, handleSubmitErrors)}>

            <CheckoutContent>
                {isFromWaitlist && (
                    <div className={classes.waitlistBanner}>
                        <div className={classes.waitlistBannerIcon}>
                            <IconClock size={22}/>
                        </div>
                        <div>
                            <p className={classes.waitlistBannerTitle}>
                                {t`You've been offered a spot!`}
                            </p>
                            <p className={classes.waitlistBannerText}>
                                {t`Complete your order to secure your tickets. This offer is time-limited, so don't wait too long.`}
                            </p>
                        </div>
                    </div>
                )}

                {(event && order) && (
                    <InlineOrderSummary event={event} order={order} defaultExpanded={true}/>
                )}

                <h2 className={classes.sectionHeading}>
                    {t`Your Details`}
                </h2>
                <p className={classes.sectionHelper}>
                    {t`We'll send your tickets to this email`}
                </p>

                <Card>
                    <InputGroup>
                        {(() => {
                            const emailProps = form.getInputProps("order.email");
                            return (
                                <TextInput
                                    withAsterisk
                                    autoFocus
                                    type={"email"}
                                    label={t`Email Address`}
                                    placeholder={t`Email Address`}
                                    rightSection={isEmailValid(form.values.order.email) ? <EmailCheckIcon/> : null}
                                    {...emailProps}
                                    onFocus={(e) => {
                                        emailProps.onFocus?.(e);
                                        prewarmTurnstile();
                                    }}
                                    onBlur={(e) => {
                                        emailProps.onBlur?.(e);
                                        handleOrderEmailBlur();
                                    }}
                                />
                            );
                        })()}
                        <TextInput
                            withAsterisk
                            type={"email"}
                            label={t`Confirm Email Address`}
                            placeholder={t`Confirm Email Address`}
                            rightSection={isEmailValid(form.values.order.email_confirmation) ? <EmailCheckIcon/> : null}
                            {...form.getInputProps("order.email_confirmation")}
                        />
                    </InputGroup>

                    <InputGroup>
                        <TextInput
                            withAsterisk
                            label={t`First Name`}
                            placeholder={t`First name`}
                            {...form.getInputProps("order.first_name")}
                        />
                        <TextInput
                            withAsterisk
                            label={t`Last Name`}
                            placeholder={t`Last Name`}
                            {...form.getInputProps("order.last_name")}
                        />
                    </InputGroup>

                    {orderRequiresAttendeeDetails && !isPerOrderCollection && totalTicketAttendees > 0 && (
                        <div className={classes.copyDetailsSection}>
                            {totalTicketAttendees === 1 ? (
                                <Tooltip
                                    label={t`Fill in your details above first`}
                                    disabled={areOrderDetailsComplete()}
                                    position="right"
                                    withArrow
                                >
                                    <div style={{display: 'inline-block'}}>
                                        <Checkbox
                                            size="sm"
                                            label={t`Copy details to first attendee`}
                                            checked={copyOption === 'first'}
                                            disabled={!areOrderDetailsComplete()}
                                            onChange={(e) => handleCopyOptionChange(e.currentTarget.checked ? 'first' : 'none')}
                                        />
                                    </div>
                                </Tooltip>
                            ) : (
                                <div className={classes.copyDetailsMultiple}>
                                    <Text size="sm" c="dimmed"
                                          className={classes.copyLabel}>{t`Copy my details to:`}</Text>
                                    <Tooltip
                                        label={t`Fill in your details above first`}
                                        disabled={areOrderDetailsComplete()}
                                        withArrow
                                    >
                                        <SegmentedControl
                                            size="xs"
                                            value={copyOption}
                                            onChange={handleCopyOptionChange}
                                            disabled={!areOrderDetailsComplete()}
                                            data={[
                                                {label: t`None`, value: 'none'},
                                                {label: t`First attendee`, value: 'first'},
                                                {label: t`All attendees`, value: 'all'},
                                            ]}
                                        />
                                    </Tooltip>
                                </div>
                            )}
                        </div>
                    )}

                    {event?.settings?.show_marketing_opt_in && (
                        <Checkbox
                            mt="md"
                            label={t`Keep me updated on news and events from ${event?.organizer?.name || t`this organizer`}`}
                            {...form.getInputProps('order.opted_into_marketing', {type: 'checkbox'})}
                        />
                    )}

                    {requireBillingAddress && (
                        <>
                            <h3 style={{marginBottom: 5}}>
                                {t`Billing Address`}
                            </h3>

                            <InputGroup>
                                <TextInput
                                    withAsterisk
                                    label={t`Address Line 1`}
                                    placeholder={t`Address Line 1`}
                                    {...form.getInputProps("order.address.address_line_1")}
                                />
                                <TextInput
                                    label={t`Address Line 2`}
                                    placeholder={t`Address Line 2`}
                                    {...form.getInputProps("order.address.address_line_2")}
                                />
                            </InputGroup>

                            <InputGroup>
                                <TextInput
                                    withAsterisk
                                    label={t`City`}
                                    placeholder={t`City`}
                                    {...form.getInputProps("order.address.city")}
                                />
                                <TextInput
                                    withAsterisk
                                    label={t`State or Region`}
                                    placeholder={t`State or Region`}
                                    {...form.getInputProps("order.address.state_or_region")}
                                />
                            </InputGroup>

                            <InputGroup>
                                {/* Postal Code and Country */}
                                <TextInput
                                    label={t`ZIP / Postal Code`}
                                    placeholder={t`ZIP or Postal Code`}
                                    {...form.getInputProps("order.address.zip_or_postal_code")}
                                />
                                <NativeSelect
                                    withAsterisk
                                    label={t`Country`}
                                    data={countries}
                                    {...form.getInputProps("order.address.country")}
                                />
                            </InputGroup>
                        </>
                    )}

                    {orderQuestions && <CheckoutOrderQuestions form={form} questions={orderQuestions} hiddenQuestionIds={orderHiddenQuestionIds}/>}
                </Card>

                {orderItems?.map(orderItem => {
                    const product = products?.find(product => product!.id === orderItem.product_id);
                    const productRequiresDetails = product?.product_type === 'TICKET' && !isPerOrderCollection;
                    const productHasQuestions = productQuestions?.some(question => question.product_ids?.includes(orderItem.product_id));

                    if (!product) {
                        return null;
                    }

                    if (!productRequiresDetails && !productHasQuestions) {
                        // Still increment productIndex for each item in the quantity
                        // to maintain correct form field indices
                        productIndex += orderItem.quantity ?? 0;
                        return null;
                    }

                    const quantity = orderItem.quantity ?? 0;
                    const isBundleItem = (product.min_per_order ?? 1) > 1 && quantity > 1;
                    const bundleKey = String(orderItem.id);
                    const isExpanded = !!expandedBundles[bundleKey];
                    const guestCount = quantity;

                    const attendeeCards = Array.from(Array(quantity)).map((_, index) => {
                        const currentProductIndex = productIndex;
                        const ticketIndices = getTicketAttendeeIndices();
                        const isTicketAttendee = ticketIndices.includes(currentProductIndex);
                        const isFirstTicketAttendee = currentProductIndex === getFirstTicketAttendeeIndex();
                        const isCopied = isTicketAttendee && (
                            copyOption === 'all' || (copyOption === 'first' && isFirstTicketAttendee)
                        );

                        // Check if current values still match the order details
                        const currentProduct = form.values.products[currentProductIndex];
                        const valuesMatchOrder = currentProduct &&
                            currentProduct.first_name === form.values.order.first_name &&
                            currentProduct.last_name === form.values.order.last_name &&
                            currentProduct.email === form.values.order.email;

                        // Only show badge if copied AND values still match
                        const showCopiedBadge = isCopied && productRequiresDetails && valuesMatchOrder;

                        const productInputs = (
                            <Card key={`${orderItem.id} ${index}`} className={classes.attendeeCard}>
                                <div className={classes.attendeeCardHeader}>
                                    <div className={classes.attendeeHeaderLeft}>
                                        <div className={classes.attendeeNumber}>
                                            {index + 1}
                                        </div>
                                        <div className={classes.attendeeInfo}>
                                            <h4>
                                                {product.product_type === 'TICKET' ? t`Attendee` : t`Item`} {index + 1}
                                            </h4>
                                            <span className={classes.attendeeTicketType}>
                                                {orderItem?.item_name}
                                            </span>
                                        </div>
                                    </div>
                                    {showCopiedBadge && (
                                        <span className={classes.copiedBadge}>
                                            {t`Copied from above`}
                                        </span>
                                    )}
                                </div>

                                {productRequiresDetails && (
                                    <>
                                        <InputGroup>
                                            {(() => {
                                                const emailProps = form.getInputProps(`products.${currentProductIndex}.email`);
                                                return (
                                                    <TextInput
                                                        withAsterisk
                                                        type={"email"}
                                                        label={t`Email Address`}
                                                        placeholder={t`Email Address`}
                                                        rightSection={isEmailValid(form.values.products[currentProductIndex]?.email || '') ?
                                                            <EmailCheckIcon/> : null}
                                                        {...emailProps}
                                                        onFocus={(e) => {
                                                            emailProps.onFocus?.(e);
                                                            prewarmTurnstile();
                                                        }}
                                                        onBlur={(e) => {
                                                            emailProps.onBlur?.(e);
                                                            handleProductEmailBlur(currentProductIndex);
                                                        }}
                                                    />
                                                );
                                            })()}
                                            <TextInput
                                                withAsterisk
                                                type={"email"}
                                                label={t`Confirm Email Address`}
                                                placeholder={t`Confirm Email Address`}
                                                rightSection={isEmailValid(form.values.products[currentProductIndex]?.email_confirmation || '') ?
                                                    <EmailCheckIcon/> : null}
                                                {...form.getInputProps(`products.${currentProductIndex}.email_confirmation`)}
                                            />
                                        </InputGroup>

                                        <InputGroup>
                                            <TextInput
                                                withAsterisk
                                                label={t`First Name`}
                                                placeholder={t`First name`}
                                                {...form.getInputProps(`products.${currentProductIndex}.first_name`)}
                                            />
                                            <TextInput
                                                withAsterisk
                                                label={t`Last Name`}
                                                placeholder={t`Last Name`}
                                                {...form.getInputProps(`products.${currentProductIndex}.last_name`)}
                                            />
                                        </InputGroup>
                                    </>
                                )}

                                {productQuestions &&
                                    <CheckoutProductQuestions
                                        index={currentProductIndex}
                                        product={product}
                                        form={form}
                                        questions={productQuestions}
                                        hiddenQuestionIds={productHiddenQuestionIds[currentProductIndex] ?? []}/>}
                            </Card>
                        );

                        productIndex++;

                        return productInputs;
                    });

                    if (!isBundleItem) {
                        return (
                            <div key={orderItem.product_id + orderItem.id} className={classes.ticketSection}>
                                <div className={classes.ticketTypeHeader}>
                                    <h3>{orderItem?.item_name}</h3>
                                    <span className={classes.ticketCountBadge}>
                                        {quantity === 1
                                            ? t`1 ticket`
                                            : t`${quantity} tickets`}
                                    </span>
                                </div>
                                {attendeeCards}
                            </div>
                        );
                    }

                    return (
                        <div key={orderItem.product_id + orderItem.id} className={classes.ticketSection}>
                            <UnstyledButton
                                type="button"
                                className={classes.bundleHeaderToggle}
                                aria-expanded={isExpanded}
                                onClick={() => setExpandedBundles(prev => ({
                                    ...prev,
                                    [bundleKey]: !prev[bundleKey],
                                }))}
                            >
                                <span className={classes.bundleHeaderTitle}>
                                    <IconTicket size={18} className={classes.bundleTicketIcon}/>
                                    <strong>{t`Tickets`}</strong>{' '}({orderItem?.item_name})
                                </span>
                                <span className={classes.ticketCountBadge}>
                                    {t`${quantity} tickets, assign now or later`}
                                </span>
                                <Tooltip
                                    multiline
                                    w={260}
                                    withArrow
                                    label={t`You can enter your guests' details now, or assign their tickets later from the order confirmation page you'll receive via email once your order is complete.`}
                                >
                                    <span
                                        className={classes.bundleGuestsInfo}
                                        onClick={(e) => e.stopPropagation()}
                                    >
                                        <IconInfoCircle size={16}/>
                                    </span>
                                </Tooltip>
                                <IconChevronDown
                                    size={20}
                                    className={isExpanded ? classes.bundleChevronOpen : classes.bundleChevron}
                                />
                            </UnstyledButton>
                            <Collapse in={isExpanded}>
                                <div className={classes.bundleGuestsBody}>
                                    {attendeeCards}
                                </div>
                            </Collapse>
                        </div>
                    );
                })}

                {!!event?.settings?.pre_checkout_message && (
                    <Card>
                        <div dangerouslySetInnerHTML={{__html: event?.settings?.pre_checkout_message}}/>
                    </Card>
                )}

                <div className={classes.checkoutActions}>
                    <Button
                        className={classes.continueButton}
                        loading={mutation.isPending}
                        type="submit"
                        rightSection={order?.is_payment_required ? <IconArrowRight size={18}/> : undefined}
                        leftSection={!order?.is_payment_required ? <IconCheck size={18}/> : undefined}
                    >
                        {order?.is_payment_required ? t`Continue to Payment` : t`Complete Order`}
                    </Button>
                    {!!getConfig('VITE_TOS_URL') && (
                        <p className={classes.tosNotice}>
                            <Trans>
                                By continuing, you agree to the{' '}
                                <a
                                    href={getConfig('VITE_TOS_URL', 'https://hi.events/terms-of-service') as string}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                >
                                    {getConfig('VITE_APP_NAME', 'Hi.Events')} Terms of Service
                                </a>
                            </Trans>
                        </p>
                    )}
                </div>

            </CheckoutContent>
        </form>
    );
}

export default CollectInformation;
