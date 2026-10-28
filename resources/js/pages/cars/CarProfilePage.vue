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
import BaseCard from '../../components/ui/BaseCard.vue';
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
      <header class="flex flex-col gap-5 sm:flex-row sm:items-start">
        <!-- Sized wrapper: the placeholder tile is w-full, so without it a car with no photo pushed the title to zero
             width (found by E2E, docs/e2e.md). -->
        <div class="w-full max-w-md shrink-0 sm:w-1/2">
          <SafeImage
            :src="car.imageUrl"
            resize="carProfile"
            :alt="`Photo of ${heading}`"
            :placeholder="heading"
            img-class="aspect-[4/3] w-full rounded-md border border-border object-cover"
            loading="eager"
          />
        </div>
        <div class="min-w-0 flex-1 space-y-2">
          <h1 class="page-title">
            {{ heading }}
          </h1>
          <p
            v-if="specs && specs !== heading"
            class="text-muted"
          >
            {{ specs }}
          </p>
          <p
            v-if="car.bodyName || car.typeName"
            class="text-sm text-text"
          >
            <template v-if="car.bodyName">
              Body: {{ car.bodyName }}
            </template>
            <template v-if="car.bodyName && car.typeName">
              &middot;
            </template>
            <template v-if="car.typeName">
              Type: {{ car.typeName }}
            </template>
          </p>
          <p
            v-if="car.owner"
            class="text-sm"
          >
            Owner:
            <RouterLink
              v-if="car.owner.profilePath"
              :to="car.owner.profilePath"
            >
              {{ car.owner.fullName }}
            </RouterLink>
            <span v-else>{{ car.owner.fullName }}</span>
          </p>
          <div
            v-if="car.isOwner"
            class="pt-2"
          >
            <BaseButton
              :to="`/car/${car.id}/settings`"
              variant="default"
            >
              Edit car
            </BaseButton>
          </div>
        </div>
      </header>

      <BaseCard title="Shows">
        <p
          v-if="car.shows.length === 0"
          class="text-muted"
        >
          This car has not attended a show yet.
        </p>
        <ul
          v-else
          class="space-y-3"
        >
          <li
            v-for="show in car.shows"
            :key="`${show.name}-${show.shortDate}`"
            class="flex flex-wrap items-center justify-between gap-2"
          >
            <span>
              <RouterLink
                v-if="show.profilePath"
                :to="show.profilePath"
              >
                {{ show.name }}
              </RouterLink>
              <span v-else>{{ show.name }}</span>
              <span
                v-if="show.shortDate"
                class="text-sm text-muted"
              > ({{ show.shortDate }})</span>
            </span>
            <BaseButton
              v-if="show.judgingLink && safeHref(show.judgingLink)"
              :href="safeHref(show.judgingLink)"
              variant="default"
              size="sm"
            >
              Judging results <span class="sr-only">for {{ show.name }}</span>
            </BaseButton>
          </li>
        </ul>
      </BaseCard>
    </template>
  </section>
</template>
