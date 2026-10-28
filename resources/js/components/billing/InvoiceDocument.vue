<script setup lang="ts">
/**
 * The invoice itself: the line items as the legacy invoice printed them, then the balance. Money strings from the
 * API are shown as-is with thousands separators; a negative balance is shown as a credit.
 */
import { computed } from 'vue';
import type { Invoice } from '../../api/billing';
import { formatCredit, formatMoney, isNegativeMoney, isPositiveMoney } from '../../composables/billing/money';
import { invoiceStatus } from '../../composables/billing/invoice';
import InvoiceStatusBadge from './InvoiceStatusBadge.vue';

const props = defineProps<{ invoice: Invoice }>();

const totals = computed(() => props.invoice.totals);
const status = computed(() => invoiceStatus(props.invoice));
const title = computed(() => props.invoice.carShow?.name || 'Registration');
const hasDonationLine = computed(() => totals.value.lineItems.some((item) => item.kind === 'donation'));
const showDonationRow = computed(() => !hasDonationLine.value && totals.value.donation !== '0.00');
const discountShown = computed(() => (isPositiveMoney(totals.value.discount) ? `-${formatMoney(totals.value.discount)}` : formatMoney(totals.value.discount)));
const balanceShown = computed(() => {
  if (isNegativeMoney(totals.value.owed)) {
    return `Credit ${formatCredit(totals.value.owed)}`;
  }
  return formatMoney(totals.value.owed);
});
</script>

<template>
  <article
    class="space-y-6 rounded-md border border-border bg-white p-6 print:border-0 print:p-0"
    aria-labelledby="invoice-title"
  >
    <header class="flex flex-wrap items-start justify-between gap-3">
      <div class="space-y-1">
        <p class="text-sm text-muted">
          Invoice no. {{ invoice.invoiceId }}
        </p>
        <h2
          id="invoice-title"
          class="text-xl font-semibold"
        >
          {{ title }}
        </h2>
      </div>
      <InvoiceStatusBadge :status="status" />
    </header>

    <section aria-labelledby="invoice-items-heading">
      <h3
        id="invoice-items-heading"
        class="mb-2 text-base font-semibold"
      >
        Items
      </h3>
      <div class="overflow-x-auto">
        <table class="w-full border-collapse text-left text-sm">
          <caption class="sr-only">
            Invoice line items
          </caption>
          <thead>
            <tr class="border-b border-border">
              <th
                scope="col"
                class="py-2 pr-3 font-semibold"
              >
                Item
              </th>
              <th
                scope="col"
                class="py-2 pr-3 text-right font-semibold"
              >
                Quantity
              </th>
              <th
                scope="col"
                class="py-2 pr-3 text-right font-semibold"
              >
                Unit price
              </th>
              <th
                scope="col"
                class="py-2 text-right font-semibold"
              >
                Amount
              </th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="(item, index) in totals.lineItems"
              :key="`${item.kind}-${index}`"
              class="border-b border-border"
            >
              <th
                scope="row"
                class="w-full max-w-0 py-2 pr-3 font-normal"
              >
                {{ item.label }}
                <span
                  v-if="item.detail"
                  class="block truncate text-xs leading-tight text-muted"
                  :title="item.detail"
                >{{ item.detail }}</span>
              </th>
              <td class="py-2 pr-3 text-right tabular-nums">
                {{ item.quantity }}
              </td>
              <td class="py-2 pr-3 text-right tabular-nums">
                {{ formatMoney(item.unit) }}
              </td>
              <td class="py-2 text-right tabular-nums">
                {{ formatMoney(item.amount) }}
              </td>
            </tr>
            <tr v-if="totals.lineItems.length === 0">
              <td
                colspan="4"
                class="py-3 text-muted"
              >
                No items on this invoice.
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <section
      aria-labelledby="invoice-summary-heading"
      class="ml-auto w-full max-w-sm"
    >
      <h3
        id="invoice-summary-heading"
        class="sr-only"
      >
        Balance
      </h3>
      <dl class="grid grid-cols-[1fr_auto] gap-x-4 gap-y-2 text-sm tabular-nums">
        <template v-if="showDonationRow">
          <dt>Donation</dt>
          <dd class="text-right">
            {{ formatMoney(totals.donation) }}
          </dd>
        </template>
        <dt>Total</dt>
        <dd class="text-right">
          {{ formatMoney(totals.total) }}
        </dd>
        <template v-if="totals.discount !== '0.00'">
          <dt>
            Discount
            <span
              v-if="invoice.discountNote"
              class="block text-xs text-muted"
            >{{ invoice.discountNote }}</span>
          </dt>
          <dd class="text-right">
            {{ discountShown }}
          </dd>
        </template>
        <dt>Amount received</dt>
        <dd class="text-right">
          {{ formatMoney(totals.received) }}
        </dd>
        <dt class="border-t border-border pt-2 font-semibold">
          Balance due
        </dt>
        <dd class="border-t border-border pt-2 text-right font-semibold">
          {{ balanceShown }}
        </dd>
      </dl>
    </section>
  </article>
</template>
