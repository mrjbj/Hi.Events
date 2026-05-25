import { CustomSelect, ItemProps } from "../../common/CustomSelect";
import { t, Trans } from "@lingui/macro";
import { ContactAttributeDefinition, ProductCategory, QuestionBelongsToType, QuestionType } from "../../../types.ts";
import { Alert, Anchor, Button, Checkbox, Group, Select, Switch, Text, TextInput, Tooltip } from "@mantine/core";
import { useParams } from "react-router";
import { useGetEventQuestions } from "../../../queries/useGetEventQuestions.ts";
import {
  IconAlignBoxLeftTop,
  IconCalendar,
  IconCircleCheck,
  IconForms,
  IconInfoCircle,
  IconMapPin,
  IconReceipt,
  IconSelector,
  IconSquareCheck,
  IconTicket,
  IconTrash,
  IconUser
} from "@tabler/icons-react";
import { UseFormReturnType } from "@mantine/form";
import { Card } from "../../common/Card";
import classes from "./QuestionForm.module.scss";
import { Editor } from "../../common/Editor";
import { useEffect, useState } from "react";
import { ProductSelector } from "../../common/ProductSelector";
import { useGetContactAttributeDefinitions } from "../../../queries/useGetContactAttributeDefinitions.ts";
import { EditContactAttributeDefinitionModal } from "../../modals/EditContactAttributeDefinitionModal";

const NEW_QUESTION_SENTINEL = '__new__';

export const slugifyToAttributeName = (input: string): string => {
  const base = (input ?? '')
    .toString()
    .toLowerCase()
    .trim()
    .replace(/[^a-z0-9]+/g, '_')
    .replace(/^_+|_+$/g, '');
  return base || 'attribute';
};

export const questionTypeFromDefinitionType = (definitionType: ContactAttributeDefinition['type']): string => {
  switch (definitionType) {
    case 'select':
      return QuestionType.DROPDOWN.toString();
    case 'multi_select':
      return QuestionType.CHECKBOX.toString();
    default:
      return QuestionType.SINGLE_LINE_TEXT.toString();
  }
};

export const definitionTypeFromQuestionType = (questionType: string): ContactAttributeDefinition['type'] => {
  if (questionType === QuestionType.CHECKBOX.toString()) return 'multi_select';
  if ([QuestionType.RADIO.toString(), QuestionType.DROPDOWN.toString()].includes(questionType)) return 'select';
  return 'text';
};

export const isDefinitionCompatibleWithQuestionType = (
  definitionType: ContactAttributeDefinition['type'],
  questionType: string,
): boolean => {
  switch (definitionType) {
    case 'text':
      return ![QuestionType.CHECKBOX.toString(), QuestionType.RADIO.toString(), QuestionType.DROPDOWN.toString()].includes(questionType);
    case 'select':
      return [QuestionType.RADIO.toString(), QuestionType.DROPDOWN.toString()].includes(questionType);
    case 'multi_select':
      return questionType === QuestionType.CHECKBOX.toString();
    default:
      return false;
  }
};

const Options = ({ form, locked }: { form: UseFormReturnType<any>; locked: boolean }) => {
  return (
    <Card>
      <h3 className={classes.optionsHeading}><Trans>Options</Trans></h3>
      {form.values.options.length === 0 && (
        <div className={classes.noOptionsMessage}>
          <Trans>Please add at least one option</Trans>
        </div>
      )}

      {form.values.options.map((_: any, index: number) => {
        const i = index + 1;
        return (
          <Group wrap={'nowrap'} justify={'space-between'} className={classes.optionRow} key={index}>
            <div className={classes.optionInputWrap}>
              <TextInput
                mt={0}
                mb={0}
                {...form.getInputProps(`options.${index}`)}
                placeholder={t`Option ${i}`}
                required
                disabled={locked}
                className={classes.optionInput}
              />
            </div>
            <div className={classes.optionButton}>
              <Button
                size='xs'
                variant="outline"
                disabled={locked}
                onClick={() => form.setFieldValue('options', form.values.options.filter((_: any, i: number) => i !== index))}
              >
                <IconTrash size={16} />
              </Button>
            </div>
          </Group>
        );
      })}

      {!locked && (
        <Button
          variant="outline"
          onClick={() => form.setFieldValue('options', [...form.values.options, ''])}
          size="xs"
        >
          {t`Add Option`}
        </Button>
      )}
    </Card>
  )
};

interface QuestionFormProps {
  form: UseFormReturnType<any>;
  productCategories?: ProductCategory[];
  isEditMode?: boolean;
}

export const QuestionForm = ({ form, productCategories, isEditMode = false }: QuestionFormProps) => {
  const [showDescription, setShowDescription] = useState(false);
  const [manageDefinitionOpen, setManageDefinitionOpen] = useState(false);
  const { eventId } = useParams();
  const definitionsQuery = useGetContactAttributeDefinitions();
  const definitions = (definitionsQuery.data?.data ?? []).filter((d) => d.is_active);
  const eventQuestionsQuery = useGetEventQuestions(eventId);
  const eventQuestions = eventQuestionsQuery.data ?? [];

  const reusableSelection: string = form.values.__reusable_selection ?? '';
  const isNewQuestion = reusableSelection === NEW_QUESTION_SENTINEL;
  const isReuseUnselected = reusableSelection === '';
  const linkedDefinitionId: number | null = (!isNewQuestion && !isReuseUnselected)
    ? Number(reusableSelection)
    : null;
  const linkedDefinition = linkedDefinitionId != null ? definitions.find((d) => d.id === linkedDefinitionId) : undefined;
  const isLinked = !!linkedDefinition;

  const trimmedTitle = (form.values.title ?? '').trim().toLowerCase();
  const editingId = (form.values as any).__editing_question_id ?? null;
  const duplicateTitleQuestion = trimmedTitle && !isLinked
    ? eventQuestions.find((q) => q.id !== editingId && (q.title ?? '').trim().toLowerCase() === trimmedTitle)
    : undefined;

  const candidateAttributeName = trimmedTitle ? slugifyToAttributeName(form.values.title ?? '') : '';
  const collidingDefinition = (!isEditMode && form.values.__make_reusable && candidateAttributeName)
    ? definitions.find((d) => d.name === candidateAttributeName && d.id !== linkedDefinitionId)
    : undefined;

  useEffect(() => {
    if (reusableSelection === NEW_QUESTION_SENTINEL) {
      form.setFieldValue('contact_attribute_definition_id', null);
      return;
    }
    if (!linkedDefinition) return;

    const derivedType = questionTypeFromDefinitionType(linkedDefinition.type);
    form.setFieldValue('title', linkedDefinition.label);
    form.setFieldValue('type', derivedType);
    form.setFieldValue('contact_attribute_definition_id', linkedDefinition.id ?? null);
    if (linkedDefinition.options && linkedDefinition.options.length > 0) {
      form.setFieldValue('options', [...linkedDefinition.options]);
    }
  }, [reusableSelection]);

  const newQuestionLabel = form.values.__make_reusable
    ? t`New Question (reusable on future events)`
    : t`New Question (this event only)`;
  const hasDefinitions = definitions.length > 0;
  const reusableOptions = hasDefinitions
    ? [
        {
          group: t`Reuse a contact attribute (recommended)`,
          items: definitions.map((d) => ({ value: String(d.id), label: d.label })),
        },
        {
          group: t`Or create a new question`,
          items: [{ value: NEW_QUESTION_SENTINEL, label: newQuestionLabel }],
        },
      ]
    : [{ value: NEW_QUESTION_SENTINEL, label: newQuestionLabel }];

  const belongToOptions: ItemProps[] = [
    {
      icon: <IconReceipt />,
      label: t`Ask once per order`,
      value: QuestionBelongsToType.ORDER,
      description: t`A single question per order. E.g, What is your shipping address?`,
    },
    {
      icon: <IconUser />,
      label: t`Ask once per product`,
      value: QuestionBelongsToType.PRODUCT,
      description: t`A single question per product. E.g, What is your t-shirt size?`,
    },
  ];

  const questionTypeOptions: ItemProps[] = [
    {
      icon: <IconForms />,
      label: t`Single line text box`,
      value: QuestionType.SINGLE_LINE_TEXT,
      description: t`A single line text input`,
    },
    {
      icon: <IconAlignBoxLeftTop />,
      label: t`Multi line text box`,
      value: QuestionType.MULTI_LINE_TEXT,
      description: t`A multi line text input`,
    },
    {
      icon: <IconSquareCheck />,
      label: t`Checkboxes`,
      value: QuestionType.CHECKBOX,
      description: t`Checkbox options allow multiple selections`,
    },
    {
      icon: <IconCircleCheck />,
      label: t`Radio Option`,
      value: QuestionType.RADIO,
      description: t`A Radio option has multiple options but only one can be selected.`,
    },
    {
      icon: <IconSelector />,
      label: t`Dropdown selection`,
      value: QuestionType.DROPDOWN,
      description: t`A Dropdown input allows only one selection`,
    },
    {
      icon: <IconMapPin />,
      label: t`Address`,
      value: QuestionType.ADDRESS,
      description: t`Shows common address fields, including country`,
    },
    {
      icon: <IconCalendar />,
      label: t`Date`,
      value: QuestionType.DATE,
      description: t`A date input. Perfect for asking for a date of birth etc.`,
    }
  ];
  const multiAnswerQuestionTypes = [
    QuestionType.CHECKBOX.toString(),
    QuestionType.RADIO.toString(),
    QuestionType.DROPDOWN.toString(),
  ];

  // When linked to a contact attribute, only widgets compatible with the attribute's data shape are allowed.
  const filteredQuestionTypeOptions = linkedDefinition
    ? questionTypeOptions.filter((opt) => isDefinitionCompatibleWithQuestionType(linkedDefinition.type, String(opt.value)))
    : questionTypeOptions;

  return (
    <>
      {!isEditMode && (
        <Select
          label={t`Re-use a saved question?`}
          description={hasDefinitions
            ? t`Linking to a saved question keeps answers consistent across events and updates the attendee's contact profile.`
            : t`No saved questions yet — create one below and tick "make reusable" to save it for future events.`}
          data={reusableOptions as any}
          value={form.values.__reusable_selection || null}
          allowDeselect={false}
          placeholder={hasDefinitions ? t`Pick a saved question or create new` : t`Create a new question`}
          error={form.errors.__reusable_selection as string | undefined}
          onChange={(value) => {
            form.setFieldValue('__reusable_selection', value ?? '');
          }}
          renderOption={({option}) => {
            const isNew = option.value === NEW_QUESTION_SENTINEL;
            return (
              <Group gap="xs" align="center" wrap="nowrap" style={{flex: 1}}>
                <Text size="sm" fw={isNew ? 400 : 600} c={isNew ? 'dimmed' : undefined}>
                  {option.label}
                </Text>
                {!isNew && (
                  <Text size="xs" c="teal" fw={500}>
                    <Trans>Reusable</Trans>
                  </Text>
                )}
              </Group>
            );
          }}
        />
      )}

      {isNewQuestion && (
        <Checkbox
          mt="sm"
          mb="lg"
          label={(
            <Group gap={6} align="center">
              <span>{t`Save to library for re-use on future events`}</span>
              <Tooltip label={t`Question will be added to the account library as a reusable contact attribute. Answers sync to the attendee's contact profile.`} withArrow multiline w={260}>
                <IconInfoCircle size={14} style={{ opacity: 0.6, cursor: 'help' }} />
              </Tooltip>
            </Group>
          )}
          {...form.getInputProps('__make_reusable', { type: 'checkbox' })}
        />
      )}

      <CustomSelect
        optionList={belongToOptions}
        label={t`Who should be asked this question?`}
        required
        form={form}
        name="belongs_to"
      />

      {form.values.belongs_to === QuestionBelongsToType.PRODUCT && (
        <ProductSelector
          label={t`What products does this code apply to?`}
          placeholder="Select products"
          icon={<IconTicket size="1rem" />}
          productCategories={productCategories ?? []}
          form={form}
          productFieldName="product_ids"
        />
      )}

      <CustomSelect
        optionList={filteredQuestionTypeOptions}
        label={t`What type of question is this?`}
        required
        form={form}
        name="type"
      />
      {isLinked && (
        <Tooltip label={t`Title and options come from the linked contact attribute and can't be changed for this event.`} withArrow multiline w={300}>
          <span className={classes.linkedHint}>
            <IconInfoCircle size={12} style={{ opacity: 0.6 }} />
            <Trans>Linked to a reusable contact attribute</Trans>
          </span>
        </Tooltip>
      )}

      <TextInput
        label={t`Question Title`}
        {...form.getInputProps('title')}
        placeholder={t`What time will you be arriving?`}
        required
        disabled={isLinked}
      />
      {duplicateTitleQuestion && (
        <Alert color="yellow" variant="light" mt="xs">
          <Trans>
            Another {duplicateTitleQuestion.belongs_to === QuestionBelongsToType.ORDER ? 'order-level' : 'attendee-level'} question on this event already uses this title.
            Having two questions with the same name can confuse buyers — consider renaming or removing the duplicate.
          </Trans>
        </Alert>
      )}
      {collidingDefinition && (
        <Alert color="yellow" variant="light" mt="xs">
          <Trans>
            A reusable contact attribute named <strong>{collidingDefinition.label}</strong> already exists.
            Linking to it keeps answers consistent across events.
          </Trans>{' '}
          <Anchor
            component="button"
            type="button"
            onClick={() => {
              form.setFieldValue('__reusable_selection', String(collidingDefinition.id));
              form.setFieldValue('__make_reusable', false);
            }}
          >
            <Trans>Link to existing</Trans>
          </Anchor>
        </Alert>
      )}

      {(showDescription || form.values.description) ? (
        <Editor
          maxLength={10000}
          editorType={'simple'}
          error={form.errors.description as string}
          label={t`Question Description`}
          description={t`Provide additional context or instructions for this question. Use this field to add terms
                                and conditions, guidelines, or any important information that attendees need to know before answering.`}
          value={form.values.description}
          onChange={(value: string) => form.setFieldValue('description', value)}
        />
      ) : (
        <Button
          variant="transparent"
          ml={0}
          pl={0}
          mb={10}
          onClick={() => setShowDescription(true)}
        >
          {t`Add description`}
        </Button>
      )}

      {multiAnswerQuestionTypes.includes(form.values.type) && (
        <>
          <Options form={form} locked={isLinked} />
          {isLinked && linkedDefinition && (
            <Anchor
              component="button"
              type="button"
              size="xs"
              mt={4}
              onClick={() => setManageDefinitionOpen(true)}
            >
              <Trans>Manage options on "{linkedDefinition.label}"</Trans>
            </Anchor>
          )}
        </>
      )}

      <Switch
        mt={20}
        {...form.getInputProps('required', { type: 'checkbox' })}
        description={t`Mandatory questions must be answered before the customer can checkout.`}
        label={t`Make this question mandatory`}
      />

      <Switch
        mt={20}
        {...form.getInputProps('is_hidden', { type: 'checkbox' })}
        description={t`Hidden questions are only visible to the event organizer and not to the customer.`}
        label={t`Hide this question`}
      />

      {manageDefinitionOpen && linkedDefinition && (
        <EditContactAttributeDefinitionModal
          definition={linkedDefinition}
          onClose={() => setManageDefinitionOpen(false)}
        />
      )}
    </>
  )
}
