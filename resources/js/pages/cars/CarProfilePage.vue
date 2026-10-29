<script setup lang="ts">
/**
 * Car profile (legacy /car/:id). Public view for everyone; the owner also gets an Edit link. Judging links
 * appear only when the server sends one.
 */
import { computed, ref, shallowRef, watch } from 'vue';
import { RouterLink } from 'vue-router';
import { fetchCarProfile, type CarProfile } from '../../api/cars';
import { ApiError } from '../../api/client';
import BaseAlert from '../../components/ui/BaseAlert.vue';
import BaseButton from '../../components/ui/BaseButton.vue';
import BaseSpinner from '../../components/ui/BaseSpinner.vue';
import SafeImage from '../../components/ui/SafeImage.vue';
import { setPageTitle } from '../../composables/usePageTitle';

const props = defineProps<{ carId: number }>();

type Status = 'loading' | 'ready' | 'notFound' | 'error';

const car = shallowRef<CarProfile | null>(null);
const status = ref<Status>('loading');
let run = 0;

async function load(): Promise<void> {
  run += 1;
  const current = run;
  status.value = 'loading';
  try {
    const next = await fetchCarProfile(props.carId);
    if (current !== run) {
      return;
    }
    car.value = next;
    status.value = 'ready';
    setPageTitle(title(next));
  } catch (failure) {
    if (current !== run) {
      return;
    }
    car.value = null;
    const notFound = failure instanceof ApiError && failure.status === 404;
    status.value = notFound ? 'notFound' : 'error';
    setPageTitle(notFound ? 'Car not found' : 'Car');
  }
}

watch(() => props.carId, () => void load(), { immediate: true });

function title(value: CarProfile): string {
  return value.nickname || [value.year, value.model].filter(Boolean).join(' ') || value.name || `Car ${props.carId}`;
}

const heading = computed(() => (car.value === null ? '' : title(car.value)));
const specs = computed(() => {
  const value = car.value;
  if (value === null) {
    return '';
  }
  return [value.year, value.model].filter(Boolean).join(' ');
});

interface DetailRow {
  label: string;
  value: string;
  to?: string;
}

/** Legacy "Details" labels (Owner, Model, Year, Body) plus the type; rows without a value are left out. */
const details = computed<DetailRow[]>(() => {
  const value = car.value;
  if (value === null) {
    return [];
  }
  const rows: DetailRow[] = [];
  if (value.owner) {
    rows.push({ label: 'Owner', value: value.owner.fullName, to: value.owner.profilePath || undefined });
  }
  rows.push({ label: 'Model', value: value.model }, { label: 'Year', value: value.year });
  rows.push({ label: 'Body', value: value.bodyName }, { label: 'Type', value: value.typeName });
  return rows.filter((row) => row.value !== '');
});

/** Judging links come from the server: only in-app hash paths and https links are followed. */
function safeHref(value: string): string | undefined {
  return /^#\/(?!\/)[^\s]*$/.test(value) || /^https:\/\/[^\s]+$/i.test(value) ? value : undefined;
}
</script>

<template>
  <section class="space-y-6">
    <BaseSpinner
      v-if="status === 'loading'"
      label="Loading car profile"
      class="py-6 text-brand"
    />

    <div
      v-else-if="status === 'notFound'"
      class="mx-auto max-w-xl"
    >
      <h1 class="page-title">
        Car not found
      </h1>
      <p class="mt-3">
        This car does not exist or is not available.
      </p>
      <p class="mt-4">
        <RouterLink to="/cars">
          Browse all cars
        </RouterLink>
      </p>
    </div>

    <BaseAlert
      v-else-if="status === 'error'"
      variant="error"
    >
      <p>The car profile could not be loaded.</p>
      <BaseButton
        variant="link"
        @click="load()"
      >
        Try again
      </BaseButton>
    </BaseAlert>

    <template v-else-if="car !== null">
      <div class="space-y-1">
        <p v-if="car.owner?.profilePath">
          <RouterLink
            :to="car.owner.profilePath"
            class="text-xs no-underline hover:underline"
          >
            Back to Owner Profile
          </RouterLink>
        </p>
        <h1 class="text-2xl font-bold text-title">
          {{ heading }}
        </h1>
        <p
          v-if="specs && specs !== heading"
          class="text-muted"
        >
          {{ specs }}
        </p>
      </div>

      <div class="grid gap-8 md:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
        <!-- Grid wrapper: the placeholder tile fills it, so a car with no photo keeps a sized block (E2E, docs/e2e.md). -->
        <div class="grid min-h-48 self-start">
          <SafeImage
            :src="car.imageUrl"
            resize="carProfile"
            :alt="`Photo of ${heading}`"
            :placeholder="heading"
            width="652"
            height="407"
            img-class="h-auto w-full rounded-sm"
            loading="eager"
          />
        </div>

        <div class="space-y-6">
          <section aria-labelledby="car-details-heading">
            <h2
              id="car-details-heading"
              class="text-xl font-semibold text-title"
            >
              Details
            </h2>
            <dl class="mt-3 space-y-2 text-[0.8125rem]">
              <div
                v-for="row in details"
                :key="row.label"
                class="flex items-center gap-2"
              >
                <dt class="rounded-sm bg-muted px-1.5 text-[0.6875rem] font-bold leading-4 text-white">
                  {{ row.label }}
                </dt>
                <dd>
                  <RouterLink
                    v-if="row.to"
                    :to="row.to"
                    class="no-underline hover:underline"
                  >
                    {{ row.value }}
                  </RouterLink>
                  <template v-else>
                    {{ row.value }}
                  </template>
                </dd>
              </div>
            </dl>
            <BaseButton
              v-if="car.isOwner"
              class="mt-4"
              :to="`/car/${car.id}/settings`"
              size="sm"
            >
              Edit car
            </BaseButton>
          </section>

          <section aria-labelledby="car-shows-heading">
            <h2
              id="car-shows-heading"
              class="text-xl font-semibold text-title"
            >
              Visited Shows
            </h2>
            <p
              v-if="car.shows.length === 0"
              class="mt-3 text-muted"
            >
              This car has not attended a show yet.
            </p>
            <ul
              v-else
              class="mt-3 space-y-2 text-[0.8125rem]"
            >
              <li
                v-for="show in car.shows"
                :key="`${show.name}-${show.shortDate}`"
              >
                <RouterLink
                  v-if="show.profilePath"
                  :to="show.profilePath"
                  class="font-bold no-underline hover:underline"
                >
                  {{ show.name }}
                </RouterLink>
                <span
                  v-else
                  class="font-bold"
                >{{ show.name }}</span>
                <span v-if="show.shortDate"> ({{ show.shortDate }})</span>
                <BaseButton
                  v-if="show.judgingLink && safeHref(show.judgingLink)"
                  :href="safeHref(show.judgingLink)"
                  variant="default"
                  size="sm"
                  class="ml-2"
                >
                  Judging results <span class="sr-only">for {{ show.name }}</span>
                </BaseButton>
              </li>
            </ul>
          </section>
        </div>
      </div>
    </template>
  </section>
</template>
