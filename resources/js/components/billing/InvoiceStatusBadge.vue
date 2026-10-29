<script setup lang="ts">
/** Status as text plus a shape marker, so the state never relies on colour alone. */
import { computed } from 'vue';
import { STATUS_LABELS, type InvoiceStatus } from '../../composables/billing/invoice';

const props = defineProps<{ status: InvoiceStatus }>();

const MARKERS: Record<InvoiceStatus, string> = { paid: '✓', unpaid: '!', credit: '−', cancelled: '✕' };
const STYLES: Record<InvoiceStatus, string> = {
  paid: 'border-success text-success',
  unpaid: 'border-warning text-warning',
  credit: 'border-primary text-primary',
  cancelled: 'border-muted text-muted line-through',
};

const label = computed(() => STATUS_LABELS[props.status]);
</script>

<template>
  <span
    class="inline-flex items-center gap-1.5 rounded-full border px-3 py-0.5 text-sm font-semibold"
    :class="STYLES[status]"
    :data-status="status"
  >
    <span
      aria-hidden="true"
      class="font-mono"
    >{{ MARKERS[status] }}</span>
    <span>{{ label }}</span>
  </span>
</template>
