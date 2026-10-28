<script setup lang="ts">
/**
 * Member directory (legacy /members). Standalone it is a page with its own heading; embedded in an event tab
 * (showId, embedded) it has no heading, compact spacing, 5 cards per row and 25 per page.
 */
import { computed, useId } from 'vue';
import BaseAlert from '../../components/ui/BaseAlert.vue';
import BaseButton from '../../components/ui/BaseButton.vue';
import BasePagination from '../../components/ui/BasePagination.vue';
import BaseSpinner from '../../components/ui/BaseSpinner.vue';
import DirectoryTabs from '../../components/directory/DirectoryTabs.vue';
import DirectoryFilters, { type FilterField } from '../../components/directory/DirectoryFilters.vue';
import MemberCard from '../../components/directory/MemberCard.vue';
import { useAsyncData } from '../../composables/useAsyncData';
import { useDirectory } from '../../composables/directory/useDirectory';
import { fetchMemberFilters, fetchMemberList, type MemberRow } from '../../api/members';

const props = withDefaults(defineProps<{ showId?: number; embedded?: boolean }>(), {
  showId: undefined,
  embedded: false,
});

const headingId = useId();
const filters = useAsyncData(fetchMemberFilters);

const directory = useDirectory<MemberRow>({
  load: fetchMemberList,
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
  list.push({ kind: 'text', name: 'name', label: 'Member name' });
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
      class="sr-only"
    >
      Members
    </h1>

    <DirectoryTabs
      v-if="embedded"
      embedded
    />

    <div :class="embedded ? 'space-y-4' : 'grid gap-6 md:grid-cols-[12rem_minmax(0,1fr)]'">
      <aside
        class="space-y-4"
        aria-label="Member filters"
      >
        <h2
          v-if="!embedded"
          class="section-heading"
        >
          Filters
        </h2>
        <DirectoryFilters
          :inline="embedded"
          :fields="fields"
          :values="directory.params.value"
          label="Filter members"
          @change="directory.write($event)"
        />
        <BaseAlert
          v-if="filters.status.value === 'error'"
          variant="warning"
        >
          The show filter could not be loaded.
        </BaseAlert>
      </aside>

      <div class="min-w-0">
        <DirectoryTabs
          v-if="!embedded"
          :embedded="false"
          class="mb-4"
        />
        <BaseSpinner
          v-if="directory.status.value === 'loading'"
          label="Loading members"
          class="py-6 text-brand"
        />
        <BaseAlert
          v-else-if="directory.status.value === 'error'"
          variant="error"
        >
          <p>The member list could not be loaded.</p>
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
            No members match these filters.
          </p>
          <template v-else>
            <p class="mb-3 text-xs text-muted">
              {{ directory.total.value }} {{ directory.total.value === 1 ? 'member' : 'members' }}
            </p>
            <ul :class="gridClass">
              <MemberCard
                v-for="member in directory.rows.value"
                :key="member.id"
                :member="member"
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
              label="Member list pages"
              @update:page="directory.write({ page: $event })"
            />
          </div>
        </template>
      </div>
    </div>
  </section>
</template>
