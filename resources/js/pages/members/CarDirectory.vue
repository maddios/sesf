<script setup lang="ts">
/**
 * Car directory (legacy /cars), filterable by attended show and body style. Embedded in an event tab
 * (showId, embedded) it has no heading, compact spacing, 5 cards per row and 25 per page.
 */
import { computed, useId } from 'vue';
import BaseAlert from '../../components/ui/BaseAlert.vue';
import BaseButton from '../../components/ui/BaseButton.vue';
import BasePagination from '../../components/ui/BasePagination.vue';
import BaseSpinner from '../../components/ui/BaseSpinner.vue';
import CarCard from '../../components/directory/CarCard.vue';
import DirectoryFilters, { type FilterField } from '../../components/directory/DirectoryFilters.vue';
import { useAsyncData } from '../../composables/useAsyncData';
import { useDirectory } from '../../composables/directory/useDirectory';
import { fetchCarFilters, fetchCarList, type CarRow } from '../../api/cars';

const props = withDefaults(defineProps<{ showId?: number; embedded?: boolean }>(), {
  showId: undefined,
  embedded: false,
});

const headingId = useId();
const filters = useAsyncData(fetchCarFilters);

const directory = useDirectory<CarRow>({
  load: fetchCarList,
  limit: props.embedded ? 25 : 20,
  syncQuery: !props.embedded,
  showId: props.showId,
});

const fields = computed<FilterField[]>(() => {
  const list: FilterField[] = [];
  if (props.showId === undefined) {
    const show = filters.data.value?.find((f) => f.name === 'show_id');
    list.push({ kind: 'select', name: 'show_id', label: 'Attended show', options: show?.data ?? [] });
  }
  const body = filters.data.value?.find((f) => f.name === 'body_id');
  list.push({ kind: 'select', name: 'body_id', label: 'Body style', options: body?.data ?? [] });
  return list;
});

const gridClass = computed(() =>
  props.embedded
    ? 'grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5'
    : 'grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4',
);
</script>

<template>
  <section
    :class="embedded ? 'space-y-4' : 'space-y-6'"
    :aria-labelledby="embedded ? undefined : headingId"
  >
    <h1
      v-if="!embedded"
      :id="headingId"
      class="page-title"
    >
      Cars
    </h1>

    <div class="grid gap-6 md:grid-cols-[14rem_minmax(0,1fr)]">
      <aside
        class="space-y-4"
        aria-label="Car filters"
      >
        <DirectoryFilters
          :fields="fields"
          :values="directory.params.value"
          label="Filter cars"
          @change="directory.write($event)"
        />
        <BaseAlert
          v-if="filters.status.value === 'error'"
          variant="warning"
        >
          The filter options could not be loaded.
        </BaseAlert>
      </aside>

      <div class="min-w-0">
        <BaseSpinner
          v-if="directory.status.value === 'loading'"
          label="Loading cars"
          class="py-6 text-brand"
        />
        <BaseAlert
          v-else-if="directory.status.value === 'error'"
          variant="error"
        >
          <p>The car list could not be loaded.</p>
          <BaseButton
            variant="link"
            @click="directory.reload()"
          >
            Try again
          </BaseButton>
        </BaseAlert>
        <template v-else>
          <p
            v-if="directory.rows.value.length === 0"
            class="py-4 text-muted"
          >
            No cars match these filters.
          </p>
          <template v-else>
            <p class="mb-3 text-sm text-muted">
              {{ directory.total.value }} {{ directory.total.value === 1 ? 'car' : 'cars' }}
            </p>
            <ul :class="gridClass">
              <CarCard
                v-for="car in directory.rows.value"
                :key="car.id"
                :car="car"
              />
            </ul>
          </template>
          <div
            v-if="directory.totalPages.value > 1"
            class="mt-6 flex justify-center"
          >
            <BasePagination
              :page="directory.params.value.page"
              :total-pages="directory.totalPages.value"
              :sync-query="false"
              label="Car list pages"
              @update:page="directory.write({ page: $event })"
            />
          </div>
        </template>
      </div>
    </div>
  </section>
</template>
