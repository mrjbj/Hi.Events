import {Button, Group, LoadingOverlay} from "@mantine/core";
import {modals} from "@mantine/modals";
import {ContactAttributeDefinition, GenericModalProps, IdParam, QuestionRequestData, QuestionType} from "../../../types.ts";
import {useForm} from "@mantine/form";
import {useMutation, useQueryClient} from "@tanstack/react-query";
import {notifications} from "@mantine/notifications";
import {useParams} from "react-router";
import {questionClient} from "../../../api/question.client.ts";
import {contactAttributeDefinitionClient} from "../../../api/contact-attribute-definition.client.ts";
import {useGetEvent} from "../../../queries/useGetEvent.ts";
import {GET_EVENT_QUESTIONS_QUERY_KEY} from "../../../queries/useGetEventQuestions.ts";
import {GET_CONTACT_ATTRIBUTE_DEFINITIONS_QUERY_KEY} from "../../../queries/useGetContactAttributeDefinitions.ts";
import {useGetMe} from "../../../queries/useGetMe.ts";
import {Modal} from "../../common/Modal";
import {t} from "@lingui/macro";
import {
    QuestionForm,
    definitionTypeFromQuestionType,
    slugifyToAttributeName,
} from "../../forms/QuestionForm";
import {GET_QUESTION_QUERY_KEY, useGetQuestion} from "../../../queries/useGetQuestion.ts";
import {useEffect} from "react";

interface EditQuestionModalProps extends GenericModalProps {
    questionId: IdParam;
}

interface EditQuestionFormValues extends QuestionRequestData {
    __reusable_selection: string;
    __make_reusable: boolean;
}

export const EditQuestionModal = ({onClose, questionId}: EditQuestionModalProps) => {
    const {eventId} = useParams();
    const queryClient = useQueryClient();
    const {data: me} = useGetMe();

    const eventQuery = useGetEvent(eventId);
    const questionQuery = useGetQuestion(eventId, questionId);
    const productsCategories = eventQuery?.data?.product_categories;

    const form = useForm<EditQuestionFormValues>({
        initialValues: {
            title: "",
            description: "",
            type: QuestionType.SINGLE_LINE_TEXT.toString(),
            required: false,
            options: [],
            product_ids: [],
            belongs_to: "ORDER",
            is_hidden: false,
            contact_attribute_definition_id: null,
            __reusable_selection: '__new__',
            __make_reusable: false,
        },
    });

    useEffect(() => {
            const {data} = questionQuery;

            if (!data) {
                return;
            }

            const definitionId = data.contact_attribute_definition_id ?? null;
            form.setValues({
                title: data.title,
                description: data.description,
                type: data.type,
                required: data.required,
                options: data.options,
                product_ids: data.product_ids?.map(id => String(id)),
                belongs_to: data.belongs_to,
                is_hidden: data.is_hidden,
                contact_attribute_definition_id: definitionId,
                __reusable_selection: definitionId != null ? String(definitionId) : '__new__',
                __make_reusable: false,
                __editing_question_id: data.id,
            } as any);
        }
        , [questionQuery.isFetched]);

    const submitUpdate = async (values: EditQuestionFormValues, forceTypeChange = false) => {
        let contact_attribute_definition_id = values.contact_attribute_definition_id;

        if (values.__reusable_selection === '__new__' && values.__make_reusable) {
            const definitionType = definitionTypeFromQuestionType(values.type);
            const payload: Partial<ContactAttributeDefinition> = {
                name: slugifyToAttributeName(values.title),
                label: values.title,
                type: definitionType,
                is_active: true,
                sort_order: 0,
            };
            if (definitionType !== 'text' && values.options && values.options.length > 0) {
                payload.options = values.options;
            }
            const response = await contactAttributeDefinitionClient.create(me?.account_id, payload);
            contact_attribute_definition_id = response.data.id ?? null;
            queryClient.invalidateQueries({queryKey: [GET_CONTACT_ATTRIBUTE_DEFINITIONS_QUERY_KEY]});
        }

        const {
            __reusable_selection: _a,
            __make_reusable: _b,
            __editing_question_id: _c,
            ...rest
        } = values as any;

        return questionClient.update(eventId, questionId, {
            ...rest,
            contact_attribute_definition_id,
            ...(forceTypeChange ? {force_type_change: true} : {}),
        } as QuestionRequestData);
    };

    const mutation = useMutation({
        mutationFn: async (values: EditQuestionFormValues) => submitUpdate(values),

        onSuccess: () => {
            notifications.show({
                message: t`Successfully Updated Question`,
                color: 'green',
                position: 'top-center',
            });
            queryClient.invalidateQueries({queryKey: [GET_EVENT_QUESTIONS_QUERY_KEY, eventId]}).then(() => {
                form.reset();
                onClose();
            }).then(() => {
                    queryClient.invalidateQueries({queryKey: [GET_QUESTION_QUERY_KEY, eventId, questionId]});
                }
            )
        },

        onError: (error: any, variables: EditQuestionFormValues) => {
            const errors = error?.response?.data?.errors;
            const typeError = errors?.type;
            const typeErrorMessage = Array.isArray(typeError) ? typeError[0] : typeError;

            if (typeErrorMessage && /existing answers/i.test(String(typeErrorMessage))) {
                modals.openConfirmModal({
                    title: t`Change question type with existing answers?`,
                    children: (
                        <div style={{fontSize: 14}}>
                            {typeErrorMessage}
                            <br /><br />
                            {t`Saved answers are preserved as-is but may render incorrectly under the new type (e.g. free-text answers under a fixed-options dropdown).`}
                        </div>
                    ),
                    labels: {confirm: t`Change type anyway`, cancel: t`Keep current type`},
                    confirmProps: {color: 'red'},
                    onConfirm: async () => {
                        try {
                            await submitUpdate(variables, true);
                            mutation.reset();
                            notifications.show({
                                message: t`Successfully Updated Question`,
                                color: 'green',
                                position: 'top-center',
                            });
                            await queryClient.invalidateQueries({queryKey: [GET_EVENT_QUESTIONS_QUERY_KEY, eventId]});
                            await queryClient.invalidateQueries({queryKey: [GET_QUESTION_QUERY_KEY, eventId, questionId]});
                            form.reset();
                            onClose();
                        } catch (e: any) {
                            notifications.show({
                                message: e?.response?.data?.message || t`Unable to update question.`,
                                color: 'red',
                                position: 'top-center',
                            });
                        }
                    },
                });
                return;
            }

            if (errors) {
                form.setErrors(errors);
            }
            const firstError = errors ? Object.values(errors)[0] : null;
            const detail = Array.isArray(firstError) ? firstError[0] : firstError;
            notifications.show({
                message: detail || error?.response?.data?.message || t`Unable to update question. Please check your details`,
                color: 'red',
                position: 'top-center',
            });
        }
    });

    return (
        <Modal
            opened
            onClose={onClose}
            heading={t`Edit Question`}
        >
            <form onSubmit={form.onSubmit((values) => mutation.mutate(values))}>
                <QuestionForm
                    form={form}
                    productCategories={productsCategories}
                    isEditMode
                />
                {!questionQuery.isFetched && <LoadingOverlay visible/>}
                <Group justify="flex-end" mt="xl" mb="md">
                    <Button variant="default" onClick={onClose} disabled={mutation.isPending}>
                        {t`Cancel`}
                    </Button>
                    <Button loading={mutation.isPending} type="submit">
                        {mutation.isPending ? t`Working...` : t`Save`}
                    </Button>
                </Group>
            </form>
        </Modal>
    )
};
