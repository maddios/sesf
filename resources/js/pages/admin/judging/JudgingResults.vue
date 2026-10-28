<script setup lang="ts">
/** Release state of one event's judging results and the results-email send (admin only). */
import { ref, watch } from 'vue';
import { mapAdminError } from '../../../composables/admin/useAdminResource';
import { useConfirm } from '../../../composables/admin/useConfirm';
import { useToast } from '../../../composables/admin/useToast';
import { sendResultsMail, setRelease, type JudgingShow } from './judging';

const props = defineProps<{
  show: JudgingShow;
}>();

const emit = defineEmits<{
  changed: [];
}>();

const toast = useToast();
const { confirm } = useConfirm();
const released = ref(props.show.is_judging_released);
const releaseError = ref('');
const mailError = ref('');
const sending = ref(false);

watch(
  () => props.show,
  (show) => {
    released.value = show.is_judging_released;
  },
);

async function toggleRelease(event: Event): Promise<void> {
  const input = event.target as HTMLInputElement;
  releaseError.value = '';
  try {
    await setRelease(props.show.id, input.checked);
    released.value = input.checked;
    toast.success(input.checked ? 'Results released.' : 'Results withdrawn.');
    emit('changed');
  } catch (caught) {
    input.checked = released.value;
    releaseError.value = mapAdminError(caught, 'The release state could not be saved.').message;
  }
}

async function sendMail(): Promise<void> {
  const ok = await confirm({
    title: 'Send results emails',
    message: 'Send the judging results email to every entrant in this event?',
    confirmLabel: 'Send emails',
  });
  if (!ok) return;
  mailError.value = '';
  sending.value = true;
  try {
    const result = await sendResultsMail(props.show.id);
    toast.success(`Sent ${result.sent} emails.`);
  } catch (caught) {
    mailError.value = mapAdminError(caught, 'The results emails could not be sent.').message;
  } finally {
    sending.value = false;
  }
}
</script>

<template>
  <section aria-labelledby="judging-results-heading">
    <h2
      id="judging-results-heading"
      class="sr-only"
    >
      Results
    </h2>

    <div class="mt-3 flex flex-wrap items-center gap-4">
      <label class="flex items-center gap-2 text-sm font-medium text-text">
        <input
          type="checkbox"
          class="h-4 w-4"
          :checked="released"
          @change="toggleRelease"
        >
        Results released
      </label>
      <button
        type="button"
        class="a2-btn"
        :disabled="sending"
        @click="sendMail"
      >
        Send results emails
      </button>
    </div>

    <p
      v-if="releaseError"
      role="alert"
      class="a2-alert a2-alert-error mt-2"
    >
      {{ releaseError }}
    </p>
    <p
      v-if="mailError"
      role="alert"
      class="a2-alert a2-alert-error mt-2"
    >
      {{ mailError }}
    </p>
    <p
      aria-live="polite"
      class="sr-only"
    >
      {{ sending ? 'Sending results emails.' : '' }}
    </p>
  </section>
</template>
