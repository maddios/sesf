<script setup lang="ts">
/**
 * Edit the signed-in member's quantity for one event extra (legacy /:event/e:id/extra/:authKey). Uses the same
 * bounds and checkbox rule as the registration form.
 */
import { computed, ref, watch } from 'vue';
import { RouterLink } from 'vue-router';
import AsyncBlock from '../../components/events/AsyncBlock.vue';
import FormField from '../../components/FormField.vue';
import ResponseBanner from '../../components/ResponseBanner.vue';
import SubmitButton from '../../components/SubmitButton.vue';
import BaseAlert from '../../components/ui/BaseAlert.vue';
import { messagesFromError } from '../../api/account';
import { fetchExtra, saveExtra } from '../../api/registration';
import { useAsyncData } from '../../composables/useAsyncData';
import { extraBounds } from '../../composables/registration/form';
import { messageList } from '../../components/registration/fieldErrors';

const props = defineProps<{ eventName: string; eventId: number; authKey: string }>();

const detail = useAsyncData(() => fetchExtra(props.eventId, props.authKey));
const extra = computed(() => detail.data.value?.extra ?? null);
const bounds = computed(() => (extra.value === null ? null : extraBounds(extra.value)));
const quantity = ref(0);
const touched = ref(false);
const saving = ref(false);
const saved = ref(false);
const serverMessages = ref<string[]>([]);

watch(
  () => detail.data.value,
  (value) => {
    if (value !== null) {
      quantity.value = value.numExtras;
    }
  },
  { flush: 'sync' },
);

const clientError = computed<string | null>(() => {
  const b = bounds.value;
  if (b === null) {
    return null;
  }
  const max = b.max ?? Number.POSITIVE_INFINITY;
  if (!Number.isInteger(quantity.value) || quantity.value < b.min || quantity.value > max) {
    return b.max === null ? `Choose at least ${b.min}.` : `Choose a number from ${b.min} to ${b.max}.`;
  }
  return null;
});

const errors = computed(() => messageList(touched.value ? clientError.value ?? undefined : undefined));

async function submit(): Promise<void> {
  touched.value = true;
  saved.value = false;
  serverMessages.value = [];
  if (clientError.value !== null || extra.value === null || saving.value) {
    return;
  }
  saving.value = true;
  try {
    await saveExtra(props.eventId, props.authKey, quantity.value);
    saved.value = true;
  } catch (error) {
    serverMessages.value = messagesFromError(error);
    if (serverMessages.value.length === 0) {
      serverMessages.value = ['Your extra could not be saved. Please try again.'];
    }
  } finally {
    saving.value = false;
  }
}

const backLink = computed(() => ({ name: 'event-register', params: { eventName: props.eventName, eventId: props.eventId } }));
</script>

<template>
  <div class="space-y-6">
    <header class="space-y-2">
      <h1 class="page-title">
        {{ extra?.name ?? 'Extra' }}
      </h1>
      <p class="text-sm">
        <RouterLink :to="backLink">
          Back to registration
        </RouterLink>
      </p>
    </header>

    <AsyncBlock
      :status="detail.status.value"
      loading-label="Loading extra"
      error-message="This extra could not be loaded right now."
      @retry="detail.reload()"
    >
      <div
        v-if="extra !== null"
        class="space-y-6"
      >
        <BaseAlert
          v-if="!extra.isEnabled"
          variant="info"
        >
          <p>This extra is no longer available.</p>
        </BaseAlert>
        <BaseAlert
          v-if="saved"
          variant="success"
        >
          <p>Your extra has been saved.</p>
        </BaseAlert>
        <p
          v-if="extra.description"
          class="text-sm"
        >
          {{ extra.description }}
        </p>
        <p class="text-sm font-medium">
          Price: ${{ extra.price }}
        </p>
        <form
          class="max-w-sm space-y-4"
          novalidate
          :aria-label="`Edit ${extra.name}`"
          @submit.prevent="submit"
        >
          <ResponseBanner :messages="serverMessages" />
          <div
            v-if="bounds?.checkbox"
            class="flex items-center gap-2"
          >
            <input
              id="extra-checkbox"
              v-model="quantity"
              type="checkbox"
              class="h-4 w-4"
              :true-value="1"
              :false-value="0"
              :disabled="!extra.isEnabled"
              :aria-invalid="errors.length > 0 ? 'true' : undefined"
            >
            <label for="extra-checkbox">Add {{ extra.name }}</label>
          </div>
          <FormField
            v-else
            id="extra-quantity"
            v-slot="{ inputProps }"
            label="Quantity"
            :hint="bounds?.max === null ? undefined : `From ${bounds?.min} to ${bounds?.max}.`"
            :errors="errors"
          >
            <input
              v-bind="inputProps"
              v-model.number="quantity"
              type="number"
              inputmode="numeric"
              step="1"
              class="form-control"
              :min="bounds?.min"
              :max="bounds?.max ?? undefined"
              :disabled="!extra.isEnabled"
            >
          </FormField>
          <p
            v-if="bounds?.checkbox && errors.length > 0"
            class="text-sm text-danger-fg"
          >
            {{ errors[0] }}
          </p>
          <SubmitButton
            v-if="extra.isEnabled"
            :busy="saving"
            busy-label="Saving extra"
          >
            Save
          </SubmitButton>
        </form>
      </div>
    </AsyncBlock>
  </div>
</template>
