<script setup lang="ts">
/**
 * Events list: Current shows (is_active=1) and Past shows (is_active=0), each with its own loading, empty and
 * error states.
 */
import { computed } from 'vue';
import AsyncBlock from '../../components/events/AsyncBlock.vue';
import EventCard from '../../components/events/EventCard.vue';
import { useCarShows } from '../../composables/events/useEventData';

const current = useCarShows(true);
const past = useCarShows(false);
const currentShows = computed(() => current.data.value ?? []);
const pastShows = computed(() => past.data.value ?? []);
</script>

<template>
  <div class="space-y-10">
    <h1 class="sr-only">
      Events
    </h1>

    <section aria-labelledby="current-shows-heading">
      <h2
        id="current-shows-heading"
        class="section-heading"
      >
        Current Shows
      </h2>
      <AsyncBlock
        :status="current.status.value"
        loading-label="Loading current shows"
        error-message="The current shows could not be loaded right now."
        @retry="current.reload()"
      >
        <p
          v-if="currentShows.length === 0"
          class="py-4 text-muted"
        >
          No current shows right now. Check back soon.
        </p>
        <ul v-else>
          <li
            v-for="show in currentShows"
            :key="show.id"
          >
            <EventCard :show="show" />
          </li>
        </ul>
      </AsyncBlock>
    </section>

    <section aria-labelledby="past-shows-heading">
      <h2
        id="past-shows-heading"
        class="section-heading"
      >
        Past Shows
      </h2>
      <AsyncBlock
        :status="past.status.value"
        loading-label="Loading past shows"
        error-message="The past shows could not be loaded right now."
        @retry="past.reload()"
      >
        <p
          v-if="pastShows.length === 0"
          class="py-4 text-muted"
        >
          No past shows to show yet.
        </p>
        <ul v-else>
          <li
            v-for="show in pastShows"
            :key="show.id"
          >
            <EventCard :show="show" />
          </li>
        </ul>
      </AsyncBlock>
    </section>
  </div>
</template>
