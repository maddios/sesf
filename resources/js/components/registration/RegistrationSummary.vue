<script setup lang="ts">
import { computed } from 'vue';
import type { Payment, Quote } from '../../api/registration';

/**
 * Live totals. The figures come from the quote endpoint (never recomputed here), so `summary` is the latest quote
 * or the saved payment. Status drives the loading and error copy; the region is polite so changes are announced.
 */
const props = defineProps<{
  summary: Payment | Quote | null;
  status: 'idle' | 'loading' | 'ready' | 'error';
}>();

const lines = computed(() => ('lineItems' in (props.summary ?? {}) ? (props.summary as Quote).lineItems : []));

function money(amount: string): string {
  return `$${amount}`;
}

const rows = computed(() => {
  const s = props.summary;
  if (s === null) {
    return [];
  }
  return [
    { label: 'Total', value: money(s.total) },
    { label: 'Received', value: money(s.received) },
    { label: 'Discount', value: money(s.discount) },
    { label: 'Owed', value: money(s.owed) },
  ];
});
</script>

<template>
  <section
    aria-labelledby="registration-summary-title"
    class="space-y-3 rounded-md border border-border bg-panel p-4 shadow-card"
  >
    <h2
      id="registration-summary-title"
      class="section-heading"
    >
      Summary
    </h2>
    <p
      v-if="status === 'loading' && summary === null"
      role="status"
      class="text-sm text-muted"
    >
      Calculating your total...
    </p>
    <p
      v-if="status === 'error'"
      role="alert"
      class="text-sm text-danger-fg"
    >
      Totals could not be updated. Your selections are still saved when you submit.
    </p>
    <p
      v-else-if="status === 'loading' && summary !== null"
      class="text-xs text-muted"
    >
      Updating totals...
    </p>
    <div aria-live="polite">
      <ul
        v-if="lines.length > 0"
        class="space-y-1 text-sm"
      >
        <li
          v-for="(line, index) in lines"
          :key="index"
          class="flex justify-between gap-3"
        >
          <span class="min-w-0">
            <span>{{ line.label }}<span
              v-if="line.quantity > 1"
              class="text-muted"
            > x {{ line.quantity }}</span></span>
            <span
              v-if="line.detail"
              class="block truncate text-xs leading-tight text-muted"
              :title="line.detail"
            >{{ line.detail }}</span>
          </span>
          <span class="shrink-0 tabular-nums">{{ money(line.amount) }}</span>
        </li>
      </ul>
      <dl
        v-if="rows.length > 0"
        class="mt-3 divide-y divide-border text-sm"
      >
        <div
          v-for="row in rows"
          :key="row.label"
          class="flex justify-between gap-3 py-1"
          :class="row.label === 'Owed' ? 'font-semibold text-heading' : ''"
        >
          <dt>{{ row.label }}</dt>
          <dd class="tabular-nums">
            {{ row.value }}
          </dd>
        </div>
      </dl>
    </div>
  </section>
</template>
