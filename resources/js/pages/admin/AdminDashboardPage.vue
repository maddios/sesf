<script setup lang="ts">
/**
 * Admin home (/#/admin): the current event with its registration counts, site totals, and shortcuts to every
 * admin page the user may open. Data: GET /admin-api/dashboard.
 */
import { computed, onMounted, ref } from 'vue';
import { RouterLink } from 'vue-router';
import BaseAlert from '../../components/ui/BaseAlert.vue';
import BaseButton from '../../components/ui/BaseButton.vue';
import BaseSpinner from '../../components/ui/BaseSpinner.vue';
import StatusBadge from '../../components/admin/StatusBadge.vue';
import { api } from '../../api/client';
import { ADMIN_API_BASE, mapAdminError } from '../../composables/admin/useAdminResource';
import { navGroups } from '../../admin/registry';
import { useAuthStore } from '../../stores/auth';

export interface DashboardData {
  event: { id: number; name: string; short_name: string; date_start: string | null; date_end: string | null; is_active: boolean; reg_open: boolean } | null;
  event_counts: { registrations: number; unpaid: number; checked_in: number; cars: number } | null;
  totals: { members: number; events: number; sponsors_enabled: number };
}

const auth = useAuthStore();
const data = ref<DashboardData | null>(null);
const loading = ref(true);
const error = ref<string | null>(null);

const shortcuts = computed(() => navGroups(auth.userLevel).flatMap((block) => block.entries));

const eventStats = computed(() => {
  const counts = data.value?.event_counts;
  return counts === null || counts === undefined
    ? []
    : [
        { label: 'Registrations', value: counts.registrations },
        { label: 'Unpaid', value: counts.unpaid },
        { label: 'Checked in', value: counts.checked_in },
        { label: 'Cars entered', value: counts.cars },
      ];
});

async function load(): Promise<void> {
  loading.value = true;
  error.value = null;
  try {
    data.value = await api.get<DashboardData>(`${ADMIN_API_BASE}/dashboard`);
  } catch (caught) {
    error.value = mapAdminError(caught, 'The dashboard could not be loaded.').message;
  } finally {
    loading.value = false;
  }
}

onMounted(load);
</script>

<template>
  <div class="space-y-6">
    <h1 class="page-title">
      Dashboard
    </h1>

    <BaseSpinner
      v-if="loading && data === null"
      label="Loading dashboard"
    />
    <BaseAlert
      v-else-if="error"
      variant="error"
    >
      <p>{{ error }}</p>
      <BaseButton
        size="sm"
        class="mt-2"
        @click="load"
      >
        Try again
      </BaseButton>
    </BaseAlert>

    <template v-if="data">
      <section aria-labelledby="dash-event">
        <h2
          id="dash-event"
          class="section-heading"
        >
          Current event
        </h2>
        <p
          v-if="data.event === null"
          class="mt-3 text-sm text-muted"
        >
          No events yet.
        </p>
        <div
          v-else
          class="mt-3 space-y-3"
        >
          <p class="flex flex-wrap items-center gap-2">
            <span class="font-semibold text-heading">{{ data.event.name }}</span>
            <span class="text-sm text-muted">{{ data.event.date_start }}<template v-if="data.event.date_end && data.event.date_end !== data.event.date_start"> – {{ data.event.date_end }}</template></span>
            <StatusBadge
              :label="data.event.reg_open ? 'Registration open' : 'Registration closed'"
              :variant="data.event.reg_open ? 'success' : 'neutral'"
            />
          </p>
          <dl class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <div
              v-for="stat in eventStats"
              :key="stat.label"
              class="rounded-sm border border-border p-3"
            >
              <dt class="text-xs font-semibold uppercase text-muted">
                {{ stat.label }}
              </dt>
              <dd class="text-2xl font-semibold text-heading">
                {{ stat.value }}
              </dd>
            </div>
          </dl>
        </div>
      </section>

      <section aria-labelledby="dash-totals">
        <h2
          id="dash-totals"
          class="section-heading"
        >
          Totals
        </h2>
        <dl class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-3">
          <div class="rounded-sm border border-border p-3">
            <dt class="text-xs font-semibold uppercase text-muted">
              Members
            </dt>
            <dd class="text-2xl font-semibold text-heading">
              {{ data.totals.members }}
            </dd>
          </div>
          <div class="rounded-sm border border-border p-3">
            <dt class="text-xs font-semibold uppercase text-muted">
              Events
            </dt>
            <dd class="text-2xl font-semibold text-heading">
              {{ data.totals.events }}
            </dd>
          </div>
          <div class="rounded-sm border border-border p-3">
            <dt class="text-xs font-semibold uppercase text-muted">
              Enabled sponsors
            </dt>
            <dd class="text-2xl font-semibold text-heading">
              {{ data.totals.sponsors_enabled }}
            </dd>
          </div>
        </dl>
      </section>
    </template>

    <section
      v-if="shortcuts.length > 0"
      aria-labelledby="dash-shortcuts"
    >
      <h2
        id="dash-shortcuts"
        class="section-heading"
      >
        Shortcuts
      </h2>
      <ul class="mt-3 flex flex-wrap gap-2">
        <li
          v-for="entry in shortcuts"
          :key="entry.key"
        >
          <RouterLink
            :to="`/admin/${entry.path}`"
            class="inline-flex rounded-sm border border-default-border bg-default px-3 py-1.5 text-sm text-text no-underline hover:bg-default-hover"
          >
            {{ entry.label }}
          </RouterLink>
        </li>
      </ul>
    </section>
  </div>
</template>
