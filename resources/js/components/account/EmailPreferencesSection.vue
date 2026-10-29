<script setup lang="ts">
/** Email and privacy choices. Loaded on mount, saved as three booleans. */
import { onMounted, reactive, ref } from 'vue';
import { fetchPreferences, savePreferences, type EmailPreferences } from '../../api/account';
import { useSaveState } from '../../composables/account/useSaveState';
import ResponseBanner from '../ResponseBanner.vue';
import SubmitButton from '../SubmitButton.vue';
import BaseAlert from '../ui/BaseAlert.vue';
import BaseSpinner from '../ui/BaseSpinner.vue';

const loadState = ref<'loading' | 'ready' | 'error'>('loading');
const prefs = reactive<EmailPreferences>({
  allowSystemEmail: false,
  allowFutureShowNotifications: false,
  allowPrivateMessage: false,
});
const saveState = useSaveState();

async function load(): Promise<void> {
  loadState.value = 'loading';
  try {
    Object.assign(prefs, await fetchPreferences());
    loadState.value = 'ready';
  } catch {
    loadState.value = 'error';
  }
}

async function onSubmit(): Promise<void> {
  saveState.reset();
  const input: EmailPreferences = { ...prefs };
  await saveState.run(() => savePreferences(input), 'Email preferences saved.');
}

onMounted(() => void load());
</script>

<template>
  <BaseSpinner
    v-if="loadState === 'loading'"
    label="Loading email preferences"
    class="py-4 text-brand"
  />
  <BaseAlert
    v-else-if="loadState === 'error'"
    variant="error"
  >
    <p>Your email preferences could not be loaded.</p>
    <button
      type="button"
      class="underline"
      @click="load()"
    >
      Try again
    </button>
  </BaseAlert>
  <form
    v-else
    class="space-y-4"
    novalidate
    @submit.prevent="onSubmit"
  >
    <fieldset class="space-y-3">
      <legend class="mb-2 text-sm font-semibold text-heading">
        Email preferences
      </legend>
      <label class="flex items-start gap-3 text-sm">
        <input
          v-model="prefs.allowSystemEmail"
          type="checkbox"
          class="mt-1 h-4 w-4"
          :disabled="saveState.busy.value"
        >
        <span>Send me system emails, such as registration and account notices.</span>
      </label>
      <label class="flex items-start gap-3 text-sm">
        <input
          v-model="prefs.allowFutureShowNotifications"
          type="checkbox"
          class="mt-1 h-4 w-4"
          :disabled="saveState.busy.value"
        >
        <span>Notify me about future shows.</span>
      </label>
      <label class="flex items-start gap-3 text-sm">
        <input
          v-model="prefs.allowPrivateMessage"
          type="checkbox"
          class="mt-1 h-4 w-4"
          :disabled="saveState.busy.value"
        >
        <span>Let other members contact me through my profile.</span>
      </label>
    </fieldset>

    <ResponseBanner
      :messages="saveState.messages.value"
      variant="error"
    />
    <BaseAlert
      v-if="saveState.success.value"
      variant="success"
    >
      {{ saveState.success.value }}
    </BaseAlert>
    <SubmitButton
      :busy="saveState.busy.value"
      busy-label="Saving preferences"
    >
      Save preferences
    </SubmitButton>
  </form>
</template>
