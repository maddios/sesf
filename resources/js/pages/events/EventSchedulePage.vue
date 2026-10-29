<script setup lang="ts">
/**
 * Schedule tab. A PDF schedule is rendered page by page with pdf.js (with a download link kept for failures); an HTML
 * schedule is rendered through SafeHtml.
 */
import { computed } from 'vue';
import AsyncBlock from '../../components/events/AsyncBlock.vue';
import PdfViewer from '../../components/events/PdfViewer.vue';
import SafeHtml from '../../components/events/SafeHtml.vue';
import { fetchSchedule } from '../../api/events';
import { useEventContext, useEventData } from '../../composables/events/useEventData';

const context = useEventContext();
const schedule = useEventData(() => context.eventId.value, fetchSchedule);
const data = computed(() => schedule.data.value);
const hasPdf = computed(() => data.value?.isPdf === true && data.value.pdfUrl !== '');
const hasHtml = computed(() => data.value !== null && data.value !== undefined && !data.value.isPdf && data.value.html !== '');
</script>

<template>
  <AsyncBlock
    :status="schedule.status.value"
    loading-label="Loading schedule"
    error-message="The schedule could not be loaded right now."
    @retry="schedule.reload()"
  >
    <section
      v-if="hasPdf && data"
      aria-labelledby="schedule-pdf-heading"
      class="space-y-4"
    >
      <h2
        id="schedule-pdf-heading"
        class="sr-only"
      >
        Schedule
      </h2>
      <PdfViewer
        :key="data.pdfUrl"
        :url="data.pdfUrl"
        title="Event schedule"
      />
      <p>
        <a
          :href="data.pdfUrl"
          target="_blank"
          rel="noopener noreferrer"
        >Download the schedule (PDF) <span class="sr-only">(opens in a new tab)</span></a>
      </p>
    </section>
    <SafeHtml
      v-else-if="hasHtml && data"
      :html="data.html"
    />
    <p
      v-else
      class="py-4 text-muted"
    >
      The schedule has not been posted yet.
    </p>
  </AsyncBlock>
</template>
