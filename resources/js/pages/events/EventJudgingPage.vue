<script setup lang="ts">
/** Judging tab: agenda items of type 2. The tab is only shown when such items exist. */
import { computed } from 'vue';
import AsyncBlock from '../../components/events/AsyncBlock.vue';
import SafeHtml from '../../components/events/SafeHtml.vue';
import { fetchAgenda } from '../../api/events';
import { useEventContext, useEventData } from '../../composables/events/useEventData';

const context = useEventContext();
const judging = useEventData(() => context.eventId.value, (id) => fetchAgenda(id, 2));
const items = computed(() => judging.data.value ?? []);
</script>

<template>
  <AsyncBlock
    :status="judging.status.value"
    loading-label="Loading judging information"
    error-message="The judging information could not be loaded right now."
    @retry="judging.reload()"
  >
    <p
      v-if="items.length === 0"
      class="py-4 text-muted"
    >
      No judging information has been posted yet.
    </p>
    <div
      v-else
      class="space-y-8"
    >
      <section
        v-for="item in items"
        :key="item.id"
        :aria-labelledby="`judging-${item.id}`"
      >
        <h2
          :id="`judging-${item.id}`"
          class="section-heading"
        >
          {{ item.name }}
        </h2>
        <SafeHtml
          class="mt-4"
          :html="item.text"
        />
      </section>
    </div>
  </AsyncBlock>
</template>
