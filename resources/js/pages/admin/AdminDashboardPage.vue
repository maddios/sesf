<script setup lang="ts">
/**
 * Admin home (/#/admin): the current event with its registration counts, site totals, and shortcuts to every
 * admin page the user may open. Data: GET /admin-api/dashboard.
 */
import { computed, onMounted, ref } from 'vue';
import { RouterLink } from 'vue-router';
import AdminPageHeader from '../../components/admin/AdminPageHeader.vue';
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

const groups = computed(() => navGroups(auth.userLevel));

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

const totalStats = computed(() => {
  const totals = data.value?.totals;
  return totals === undefined
    ? []
    : [
        { label: 'Members', value: totals.members },
        { label: 'Events', value: totals.events },
        { label: 'Enabled sponsors', value: totals.sponsors_enabled },
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
  <div class="a2-page space-y-4">
    <AdminPageHeader title="Dashboard" />

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
          class="a2-legend a2-legend--sub"
        >
          Current event
        </h2>
        <p
          v-if="data.event === null"
          class="a2-muted"
        >
          No events yet.
        </p>
        <div
          v-else
          class="space-y-2"
        >
          <p class="flex flex-wrap items-center gap-2">
            <span class="font-semibold">{{ data.event.name }}</span>
            <span class="a2-small">{{ data.event.date_start }}<template v-if="data.event.date_end && data.event.date_end !== data.event.date_start"> – {{ data.event.date_end }}</template></span>
            <StatusBadge
              :label="data.event.reg_open ? 'Registration open' : 'Registration closed'"
              :variant="data.event.reg_open ? 'success' : 'neutral'"
            />
          </p>
          <dl class="grid grid-cols-2 gap-2 sm:flex sm:flex-wrap">
            <div
              v-for="stat in eventStats"
              :key="stat.label"
              class="min-w-36 border border-[#d4d4d4] bg-white"
            >
              <dt class="border-b border-[#d4d4d4] bg-[linear-gradient(#f3f4f4,#e2e4e5)] px-2 py-1 text-xs font-semibold text-[#444]">
                {{ stat.label }}
              </dt>
              <dd class="px-2 py-1 text-2xl font-semibold leading-tight text-[#333]">
                {{ stat.value }}
              </dd>
            </div>
          </dl>
        </div>
      </section>

      <section aria-labelledby="dash-totals">
        <h2
          id="dash-totals"
          class="a2-legend a2-legend--sub"
        >
          Totals
        </h2>
        <dl class="grid grid-cols-2 gap-2 sm:flex sm:flex-wrap">
          <div
            v-for="stat in totalStats"
            :key="stat.label"
            class="min-w-36 border border-[#d4d4d4] bg-white"
          >
            <dt class="border-b border-[#d4d4d4] bg-[linear-gradient(#f3f4f4,#e2e4e5)] px-2 py-1 text-xs font-semibold text-[#444]">
              {{ stat.label }}
            </dt>
            <dd class="px-2 py-1 text-2xl font-semibold leading-tight text-[#333]">
              {{ stat.value }}
            </dd>
          </div>
        </dl>
      </section>
    </template>

    <section
      v-if="groups.length > 0"
      aria-labelledby="dash-shortcuts"
    >
      <h2
        id="dash-shortcuts"
        class="a2-legend a2-legend--sub"
      >
        Shortcuts
      </h2>
      <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <section
          v-for="block in groups"
          :key="block.group"
          class="border border-[#d4d4d4] bg-white"
          :aria-label="block.group"
        >
          <h3 class="border-b border-[#d4d4d4] bg-[linear-gradient(#f3f4f4,#e2e4e5)] px-2 py-1 text-xs font-semibold uppercase text-[#444]">
            {{ block.group }}
          </h3>
          <ul class="divide-y divide-[#e5e5e5]">
            <li
              v-for="entry in block.entries"
              :key="entry.key"
            >
              <RouterLink
                :to="`/admin/${entry.path}`"
                class="block px-2 py-1 text-sm text-[#005580] no-underline hover:bg-[#f5f5f5] hover:underline"
              >
                {{ entry.label }}
              </RouterLink>
            </li>
          </ul>
        </section>
      </div>
    </section>
  </div>
</template>
