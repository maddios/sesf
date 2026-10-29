<script setup lang="ts">
/** Choose which registration's invoice to show. Changing it asks the page to navigate to that invoice. */
import { useId } from 'vue';
import type { InvoiceListItem } from '../../api/billing';

defineProps<{ options: InvoiceListItem[]; modelValue: number }>();
const emit = defineEmits<{ select: [registrationId: number] }>();

const selectId = useId();

function onChange(event: Event): void {
  const value = Number((event.target as HTMLSelectElement).value);
  if (Number.isInteger(value) && value > 0) {
    emit('select', value);
  }
}
</script>

<template>
  <div class="no-print flex flex-col gap-1">
    <label
      :for="selectId"
      class="font-semibold"
    >Registration</label>
    <select
      :id="selectId"
      class="min-w-64 rounded-sm border border-border bg-white px-3 py-2 text-text"
      :value="modelValue"
      @change="onChange"
    >
      <option
        v-for="option in options"
        :key="option.id"
        :value="option.id"
      >
        {{ option.name || `Registration ${option.id}` }}
      </option>
    </select>
  </div>
</template>
