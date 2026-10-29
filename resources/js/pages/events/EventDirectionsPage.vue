<script setup lang="ts">
/** Directions tab: each location with its address, notes and the map (or a plain directions link). */
import { computed } from 'vue';
import AsyncBlock from '../../components/events/AsyncBlock.vue';
import EventMap from '../../components/events/EventMap.vue';
import SafeHtml from '../../components/events/SafeHtml.vue';
import { fetchLocations } from '../../api/events';
import { useEventContext, useEventData } from '../../composables/events/useEventData';

const context = useEventContext();
const locations = useEventData(() => context.eventId.value, fetchLocations);
const items = computed(() => locations.data.value ?? []);
</script>

<template>
  <AsyncBlock
    :status="locations.status.value"
    loading-label="Loading locations"
    error-message="The locations could not be loaded right now."
    @retry="locations.reload()"
  >
    <p
      v-if="items.length === 0"
      class="py-4 text-muted"
    >
      No locations have been added yet.
    </p>
    <div
      v-else
      class="space-y-10"
    >
      <section
        v-for="(location, index) in items"
        :key="index"
        :aria-labelledby="`location-${index}`"
      >
        <h2
          :id="`location-${index}`"
          class="section-heading"
        >
          {{ location.name }}
        </h2>
        <div class="mt-4 space-y-4">
          <EventMap
            :title="location.name"
            :address="location.address"
          />
          <div v-if="location.description">
            <h3 class="text-sm font-semibold text-title">
              Notes
            </h3>
            <SafeHtml :html="location.description" />
          </div>
        </div>
      </section>
    </div>
  </AsyncBlock>
</template>
