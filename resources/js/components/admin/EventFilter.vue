<script setup lang="ts">
/**
 * The legacy /admin2 filter block: a "Filter" legend and an "Event" fieldset with a dropdown (BaseCombobox, type to
 * filter) of the most recent events (lookups/events; the chosen event is added when it is older). Choosing an
 * event applies it at once.
 * Extra filter fields go in the default slot, below the event.
 */
import { computed, onMounted, ref, useId, watch } from 'vue';
import { api } from '../../api/client';
import { ADMIN_API_BASE, mapAdminError } from '../../composables/admin/useAdminResource';
import BaseCombobox from '../ui/BaseCombobox.vue';
import type { RelationOption } from './RelationSelect.vue';

const props = withDefaults(defineProps<{ modelValue: number | null; heading?: string }>(), { heading: 'Filter' });
const emit = defineEmits<{ 'update:modelValue': [value: number | null] }>();

const selectId = useId();
const options = ref<RelationOption[]>([]);
const error = ref<string | null>(null);
const ENDPOINT = `${ADMIN_API_BASE}/lookups/events`;
const choices = computed(() => options.value.map((option) => ({ value: option.id, label: option.label })));

async function fetchOptions(query = ''): Promise<RelationOption[]> {
  const body = await api.get<{ data?: RelationOption[] }>(query === '' ? ENDPOINT : `${ENDPOINT}?${query}`);
  return Array.isArray(body.data) ? body.data : [];
}

async function ensureSelected(): Promise<void> {
  const id = props.modelValue;
  if (id === null || options.value.some((option) => option.id === id)) return;
  options.value = [...options.value, ...(await fetchOptions(`ids=${id}`))];
}

onMounted(async () => {
  try {
    options.value = await fetchOptions();
    await ensureSelected();
  } catch (problem) {
    error.value = mapAdminError(problem, 'The events could not be loaded.').message;
  }
});

watch(
  () => props.modelValue,
  () => void ensureSelected().catch(() => undefined),
);

function onChange(value: string | number | null): void {
  emit('update:modelValue', value === null ? null : Number(value));
}
</script>

<template>
  <div class="mb-2">
    <h2 class="a2-legend">
      {{ heading }}
    </h2>
    <fieldset class="a2-fieldset">
      <legend class="a2-legend a2-legend--sub">
        Event
      </legend>
      <div class="a2-filter-inline">
        <div>
          <label
            :for="selectId"
            class="sr-only"
          >Event</label>
          <BaseCombobox
            :id="selectId"
            :model-value="modelValue"
            :options="choices"
            placeholder="Choose an event"
            @update:model-value="onChange"
          />
        </div>
        <slot />
      </div>
      <p
        v-if="error"
        role="alert"
        class="a2-alert a2-alert-error mt-2"
      >
        {{ error }}
      </p>
    </fieldset>
  </div>
</template>
