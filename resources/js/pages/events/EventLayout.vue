<script setup lang="ts">
/**
 * Shell for one event (/{name}/e{id}...): event name, tab bar and the tab page. Tabs follow the legacy nav:
 * Judging only when judging agenda items exist, Schedule only when the event has one, Register only while
 * registration is open. Loads the event info once and shares it with the tab pages.
 */
import { computed, watch } from 'vue';
import { RouterLink, RouterView, useRoute } from 'vue-router';
import AsyncBlock from '../../components/events/AsyncBlock.vue';
import TabNav, { type TabNavItem } from '../../components/ui/TabNav.vue';
import { ApiError } from '../../api/client';
import type { EventInfo } from '../../api/events';
import { provideEventContext, useEventInfo } from '../../composables/events/useEventData';
import { setPageTitle } from '../../composables/usePageTitle';

const route = useRoute();
const eventId = computed(() => Number(route.params.eventId));
const eventName = computed(() => String(route.params.eventName));

const info = useEventInfo(() => eventId.value);
const infoValue = computed<EventInfo | null>(() => info.data.value);
provideEventContext({ eventId, info: infoValue });

const notFound = computed(() => info.status.value === 'error' && info.error.value instanceof ApiError && info.error.value.status === 404);

const tabs = computed<TabNavItem[]>(() => {
  const current = infoValue.value;
  if (current === null) {
    return [];
  }
  const params = { eventName: eventName.value, eventId: eventId.value };
  const items: TabNavItem[] = [{ label: 'Event Info', to: { name: 'event-info', params } }];
  if (current.agendaCounts.judging > 0) {
    items.push({ label: 'Judging', to: { name: 'event-judging', params } });
  }
  if (current.hasSchedule) {
    items.push({ label: 'Schedule', to: { name: 'event-schedule', params } });
  }
  items.push({ label: 'Directions', to: { name: 'event-directions', params } });
  items.push({ label: "Who's Coming", to: { name: 'event-members', params } });
  if (current.registrationOpen) {
    items.push({ label: 'Register now', to: `${current.path}/register`, danger: true });
  }
  items.push({ label: 'Sponsors', to: { name: 'event-sponsors', params }, emphasis: true });
  return items;
});

// Child routes carry no title of their own, so the page title follows the event name (or "Event not found").
const pageTitle = computed<string | null>(() => {
  if (notFound.value) {
    return 'Event not found';
  }
  return infoValue.value?.name ?? null;
});

watch(
  [pageTitle, () => route.name],
  ([title]) => {
    if (title !== null) {
      setPageTitle(title);
    }
  },
  { immediate: true, flush: 'post' },
);
</script>

<template>
  <div class="space-y-6">
    <section
      v-if="notFound"
      aria-labelledby="event-not-found"
    >
      <h1
        id="event-not-found"
        class="page-title"
      >
        Event not found
      </h1>
      <p class="mt-4">
        We could not find that event. Browse
        <RouterLink to="/events">
          all events
        </RouterLink>
        instead.
      </p>
    </section>

    <AsyncBlock
      v-else
      :status="info.status.value"
      loading-label="Loading event"
      error-message="This event could not be loaded right now."
      @retry="info.reload()"
    >
      <div
        v-if="infoValue !== null"
        class="space-y-6"
      >
        <h1 class="page-title">
          {{ infoValue.name }}
        </h1>
        <TabNav
          :tabs="tabs"
          label="Event sections"
        />
        <RouterView />
      </div>
    </AsyncBlock>
  </div>
</template>
