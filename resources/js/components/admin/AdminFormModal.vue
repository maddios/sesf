<script setup lang="ts">
/**
 * Create/edit dialog on BaseModal. Give it a field schema (`fields`) or your own controls in the default slot
 * ({ values, errors, fieldId }); a `field-<name>` slot replaces one schema field. `initial` seeds the form each
 * time it opens. `submit(values)` saves (usually resource.create/update); on success it emits `saved` with the
 * result and closes. Server errors are mapped with mapAdminError: field messages appear under their fields (and
 * focus moves to the first invalid one), anything else in a banner.
 *
 * Layout: two columns from 768px (textareas, rich text and `wide` fields span both), `section` starts a titled group, required
 * fields get an asterisk. On phones the dialog is a full-height sheet with the Cancel/Save bar always visible.
 *
 * Dirty guard: closing (Escape, backdrop, x, Cancel) with unsaved changes first asks "Discard changes?" inside the
 * dialog; "Keep editing" returns to the form.
 */
import { computed, nextTick, ref, useId, watch } from 'vue';
import BaseModal from '../ui/BaseModal.vue';
import BaseButton from '../ui/BaseButton.vue';
import BaseAlert from '../ui/BaseAlert.vue';
import BaseCombobox from '../ui/BaseCombobox.vue';
import AdminFormField from './AdminFormField.vue';
import ResponseBanner from '../ResponseBanner.vue';
import MoneyInput from './MoneyInput.vue';
import DateInput from './DateInput.vue';
import DateTimeInput from './DateTimeInput.vue';
import BooleanToggle from './BooleanToggle.vue';
import RichTextEditor from './RichTextEditor.vue';
import { mapAdminError } from '../../composables/admin/useAdminResource';
import type { AdminField, FormValues } from './types';

const props = withDefaults(
  defineProps<{
    open: boolean;
    title: string;
    fields?: AdminField[];
    initial?: FormValues;
    submit: (values: FormValues) => Promise<unknown>;
    submitLabel?: string;
    size?: 'sm' | 'md' | 'lg';
  }>(),
  { fields: () => [], initial: () => ({}), submitLabel: 'Save', size: 'lg' },
);

const emit = defineEmits<{ 'update:open': [open: boolean]; saved: [result: unknown] }>();

const formId = useId();
const values = ref<FormValues>({});
const errors = ref<Record<string, string[]>>({});
const banner = ref<string | null>(null);
const busy = ref(false);
const confirmingDiscard = ref(false);
const form = ref<HTMLFormElement | null>(null);
let snapshot = '';

const fieldId = (name: string): string => `${formId}-${name}`;

function seed(): void {
  const start: FormValues = {};
  for (const field of props.fields) {
    start[field.name] = field.type === 'checkbox' ? false : null;
  }
  values.value = { ...start, ...(JSON.parse(JSON.stringify(props.initial)) as FormValues) };
  snapshot = JSON.stringify(values.value);
  errors.value = {};
  banner.value = null;
  busy.value = false;
  confirmingDiscard.value = false;
}

watch(
  () => props.open,
  (open) => {
    if (open) seed();
  },
  { immediate: true },
);

const dirty = computed(() => JSON.stringify(values.value) !== snapshot);

function close(): void {
  confirmingDiscard.value = false;
  emit('update:open', false);
}

async function requestClose(): Promise<void> {
  if (busy.value) return;
  if (dirty.value && !confirmingDiscard.value) {
    confirmingDiscard.value = true;
    await nextTick();
    form.value?.querySelector<HTMLElement>('[data-keep-editing]')?.focus();
    return;
  }
  close();
}

async function keepEditing(): Promise<void> {
  confirmingDiscard.value = false;
  await nextTick();
  form.value?.querySelector<HTMLElement>('input, textarea, select')?.focus();
}

function textValue(name: string): string {
  const value = values.value[name];
  return value === null || value === undefined ? '' : String(value);
}

function nullableText(name: string): string | null {
  const value = values.value[name];
  return typeof value === 'string' ? value : null;
}

function setValue(name: string, value: unknown): void {
  values.value = { ...values.value, [name]: value };
  if (errors.value[name] !== undefined) {
    const next = { ...errors.value };
    delete next[name];
    errors.value = next;
  }
}

function onText(field: AdminField, event: Event): void {
  const raw = (event.target as HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement).value;
  if (field.type === 'number') {
    setValue(field.name, raw === '' ? null : Number(raw));
  } else {
    setValue(field.name, raw);
  }
}

async function onSubmit(): Promise<void> {
  if (busy.value) return;
  busy.value = true;
  errors.value = {};
  banner.value = null;
  confirmingDiscard.value = false;
  try {
    const result = await props.submit({ ...values.value });
    snapshot = JSON.stringify(values.value);
    busy.value = false;
    emit('saved', result);
    close();
  } catch (error) {
    busy.value = false;
    const mapped = mapAdminError(error, 'The changes could not be saved. Please try again.');
    errors.value = mapped.fieldErrors;
    const known = new Set(props.fields.map((field) => field.name));
    const unknown = Object.entries(mapped.fieldErrors).filter(([name]) => props.fields.length > 0 && !known.has(name));
    banner.value = unknown.length > 0 ? [mapped.message, ...unknown.flatMap(([, list]) => list)].join(' ') : mapped.message;
    await nextTick();
    form.value?.querySelector<HTMLElement>('[aria-invalid="true"]')?.focus();
  }
}

defineExpose({ values, dirty, requestClose });
</script>

<template>
  <BaseModal
    :open="open"
    :title="title"
    :size="size"
    :close-on-backdrop="true"
    sheet
    @update:open="(value) => { if (!value) void requestClose(); }"
  >
    <form
      :id="formId"
      ref="form"
      class="space-y-4"
      novalidate
      @submit.prevent="onSubmit"
    >
      <ResponseBanner :messages="banner" />

      <div class="admin-form-grid">
        <slot
          :values="values"
          :errors="errors"
          :field-id="fieldId"
          :set-value="setValue"
        >
          <template
            v-for="field in fields"
            :key="field.name"
          >
            <h3
              v-if="field.section"
              class="admin-form-section"
            >
              {{ field.section }}
            </h3>
            <slot
              :id="fieldId(field.name)"
              :name="`field-${field.name}`"
              :field="field"
              :value="values[field.name]"
              :errors="errors[field.name] ?? []"
              :set-value="(value: unknown) => setValue(field.name, value)"
            >
              <div
                v-if="field.type === 'checkbox'"
                class="self-end pb-1"
                :class="field.wide ? 'is-wide' : ''"
              >
                <BooleanToggle
                  :id="fieldId(field.name)"
                  :model-value="values[field.name] === true"
                  :label="field.label"
                  @update:model-value="(value) => setValue(field.name, value)"
                />
                <p
                  v-if="field.hint"
                  class="mt-1 text-xs text-muted"
                >
                  {{ field.hint }}
                </p>
                <p
                  v-for="(message, index) in errors[field.name] ?? []"
                  :key="index"
                  class="admin-field-error"
                >
                  {{ message }}
                </p>
              </div>
              <AdminFormField
                v-else
                :id="fieldId(field.name)"
                v-slot="{ inputProps }"
                :label="field.label"
                :required="field.required"
                :hint="field.hint"
                :errors="errors[field.name] ?? []"
                :wide="field.wide || field.type === 'textarea' || field.type === 'richtext'"
              >
                <RichTextEditor
                  v-if="field.type === 'richtext'"
                  v-bind="inputProps"
                  :label="field.label"
                  :rows="field.rows"
                  :maxlength="field.maxlength"
                  :model-value="textValue(field.name)"
                  @update:model-value="(value) => setValue(field.name, value)"
                />
                <textarea
                  v-else-if="field.type === 'textarea'"
                  v-bind="inputProps"
                  class="form-control w-full"
                  :rows="field.rows ?? 4"
                  :maxlength="field.maxlength"
                  :required="field.required"
                  :value="textValue(field.name)"
                  @input="onText(field, $event)"
                />
                <BaseCombobox
                  v-else-if="field.type === 'select'"
                  v-bind="inputProps"
                  :model-value="textValue(field.name) === '' ? null : textValue(field.name)"
                  :options="field.options ?? []"
                  :clearable="!field.required"
                  placeholder="Choose…"
                  @update:model-value="(value) => setValue(field.name, value === null ? '' : String(value))"
                />
                <MoneyInput
                  v-else-if="field.type === 'money'"
                  v-bind="inputProps"
                  :model-value="nullableText(field.name)"
                  @update:model-value="(value) => setValue(field.name, value)"
                />
                <DateInput
                  v-else-if="field.type === 'date'"
                  v-bind="inputProps"
                  :model-value="nullableText(field.name)"
                  @update:model-value="(value) => setValue(field.name, value)"
                />
                <DateTimeInput
                  v-else-if="field.type === 'datetime'"
                  v-bind="inputProps"
                  :model-value="nullableText(field.name)"
                  @update:model-value="(value) => setValue(field.name, value)"
                />
                <input
                  v-else
                  v-bind="inputProps"
                  class="form-control w-full"
                  :type="field.type ?? 'text'"
                  :inputmode="field.type === 'number' ? 'numeric' : undefined"
                  :autocomplete="field.type === 'password' ? 'new-password' : undefined"
                  :maxlength="field.maxlength"
                  :required="field.required"
                  :value="textValue(field.name)"
                  @input="onText(field, $event)"
                >
              </AdminFormField>
            </slot>
          </template>
        </slot>
      </div>

      <BaseAlert
        v-if="confirmingDiscard"
        variant="warning"
        title="Discard your changes?"
      >
        <p>You have unsaved changes in this form.</p>
        <div class="mt-2 flex flex-wrap gap-2">
          <BaseButton
            size="sm"
            data-keep-editing
            @click="keepEditing"
          >
            Keep editing
          </BaseButton>
          <BaseButton
            size="sm"
            variant="danger"
            @click="close"
          >
            Discard changes
          </BaseButton>
        </div>
      </BaseAlert>
    </form>
    <template #footer>
      <BaseButton
        :disabled="busy"
        @click="requestClose"
      >
        Cancel
      </BaseButton>
      <BaseButton
        type="submit"
        variant="primary"
        :form="formId"
        :loading="busy"
        loading-label="Saving"
      >
        {{ submitLabel }}
      </BaseButton>
    </template>
  </BaseModal>
</template>
