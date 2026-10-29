<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, shallowRef } from 'vue';
import type { SurveyModel } from 'survey-core';
import { SurveyComponent } from 'survey-vue3-ui';
import { errorMessages, type JudgingApi } from './api';
import { createJudgingSurvey, type StatusKind } from './judgingSurvey';
import TransferStatus from './TransferStatus.vue';
import { UploadTracker } from './uploads';

const props = defineProps<{ api: JudgingApi }>();

const survey = shallowRef<SurveyModel>();
const loadError = ref('');
const status = ref<{ kind: StatusKind; message: string }>();

const tracker = new UploadTracker(() => refreshTransfer());
const transfer = shallowRef(tracker.state());
let ticker: ReturnType<typeof setInterval> | undefined;

/** Re-reads the tracker; while uploads run, re-reads every second so ETA and the stall flag move. */
function refreshTransfer(): void {
  transfer.value = tracker.state();
  if (tracker.active > 0 && ticker === undefined) {
    ticker = setInterval(() => (transfer.value = tracker.state()), 1000);
  } else if (tracker.active === 0 && ticker !== undefined) {
    clearInterval(ticker);
    ticker = undefined;
  }
}

onBeforeUnmount(() => clearInterval(ticker));

onMounted(async () => {
  try {
    const sheet = await props.api.load();
    if (sheet.form.length === 0) {
      loadError.value = 'There is no scoresheet for this car’s judging type.';
      return;
    }
    survey.value = createJudgingSurvey(
      sheet,
      props.api,
      (kind, message) => {
        status.value = { kind, message };
      },
      tracker,
    );
  } catch (error) {
    loadError.value = errorMessages(error).join(' ');
  }
});
</script>

<template>
  <div>
    <p
      v-if="loadError"
      role="alert"
      class="rounded border border-danger-border bg-danger-bg p-3 text-danger-fg"
    >
      {{ loadError }}
    </p>
    <p
      v-else-if="!survey"
      role="status"
    >
      Loading scoresheet…
    </p>
    <template v-else>
      <p
        role="status"
        aria-live="polite"
        class="sticky top-0 z-10 min-h-[2.5rem] rounded px-3 py-2 text-sm"
        :class="{
          'bg-danger-bg text-danger-fg': status?.kind === 'error',
          'bg-warning-bg text-warning-fg': status?.kind === 'info',
          'bg-success-bg text-success-fg': status?.kind === 'saved',
          'bg-subtle text-text': status?.kind === 'saving',
        }"
      >
        {{ status?.message ?? 'Changes are saved automatically.' }}
      </p>
      <TransferStatus
        :state="transfer"
        @stop="tracker.stopAll()"
        @dismiss="tracker.dismissFailed()"
      />
      <SurveyComponent :model="survey" />
    </template>
  </div>
</template>
