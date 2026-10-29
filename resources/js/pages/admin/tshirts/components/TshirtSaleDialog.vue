<script setup lang="ts">
/**
 * "Sell ad-hoc shirt" (legacy sale modal): pick shirts from what is left after orders and earlier sales. One stepper
 * per type that still has shirts available; the sale is sent as one type id per shirt.
 */
import { computed, ref, watch } from 'vue';
import BaseModal from '../../../../components/ui/BaseModal.vue';
import { saleLines, type ShirtTypeRow } from '../tshirts';

const props = defineProps<{ open: boolean; types: ShirtTypeRow[]; saving: boolean; error: string | null }>();
const emit = defineEmits<{ save: [typeIds: number[]]; close: [] }>();

const counts = ref<Record<number, number>>({});

watch(
  () => props.open,
  (open) => {
    if (open) counts.value = {};
  },
  { immediate: true },
);

const sellable = computed(() => props.types.filter((type) => available(type) > 0));
const total = computed(() => Object.values(counts.value).reduce((sum, count) => sum + count, 0));

function available(type: ShirtTypeRow): number {
  return Math.max(0, type.available_for_adhoc);
}

function step(type: ShirtTypeRow, delta: number): void {
  const next = (counts.value[type.id] ?? 0) + delta;
  counts.value = { ...counts.value, [type.id]: Math.min(available(type), Math.max(0, next)) };
}
</script>

<template>
  <BaseModal
    :open="open"
    title="Sell ad-hoc shirt"
    sheet
    @close="emit('close')"
  >
    <div class="a2-page">
      <p class="a2-muted a2-small mb-3">
        Choose shirts from the remaining event inventory. This records inventory only and does not collect payment.
      </p>
      <p
        v-if="error"
        role="alert"
        class="a2-alert a2-alert-error"
      >
        {{ error }}
      </p>
      <p
        v-if="sellable.length === 0"
        class="a2-alert a2-alert-error"
      >
        No shirts are available to sell.
      </p>
      <ul v-else>
        <li
          v-for="type in sellable"
          :key="type.id"
          class="a2-form-row border-b border-[#eee] pb-2"
        >
          <span class="min-w-0">
            <span class="font-semibold text-[#333]">{{ type.name }}</span>
            <span class="block text-xs text-[#6b7280]">{{ available(type) }} available</span>
          </span>
          <span class="flex items-center gap-1">
            <button
              type="button"
              class="a2-btn min-w-10"
              :aria-label="`Remove one ${type.name} from the sale`"
              :disabled="(counts[type.id] ?? 0) === 0"
              @click="step(type, -1)"
            >
              −
            </button>
            <span
              class="min-w-8 text-center text-lg font-semibold"
              :aria-label="`${type.name} in sale`"
            >{{ counts[type.id] ?? 0 }}</span>
            <button
              type="button"
              class="a2-btn min-w-10"
              :aria-label="`Add one ${type.name} to the sale`"
              :disabled="(counts[type.id] ?? 0) >= available(type)"
              @click="step(type, 1)"
            >
              +
            </button>
          </span>
        </li>
      </ul>
    </div>
    <template #footer>
      <button
        type="button"
        class="a2-btn"
        @click="emit('close')"
      >
        Cancel
      </button>
      <button
        type="button"
        class="a2-btn a2-btn-info"
        :disabled="saving || total === 0"
        @click="emit('save', saleLines(counts))"
      >
        Record sale ({{ total }})
      </button>
    </template>
  </BaseModal>
</template>
