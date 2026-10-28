<script setup lang="ts">
/**
 * T-shirts for one event (legacy TshirtList): stock per type with the available tallies and the shortfall warning,
 * hand-outs per registration line with a status column, walk-up sales, and the printable pickup sheet (the page's print
 * stylesheet shows the sheet in legacy layout). The event is in the hash query (?show_id=). Stock and sales are checked
 * by the server under a lock; the counts are re-read after each change.
 */
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import RelationSelect from '../../../components/admin/RelationSelect.vue';
import BaseButton from '../../../components/ui/BaseButton.vue';
import { ADMIN_API_BASE, mapAdminError } from '../../../composables/admin/useAdminResource';
import { useConfirm } from '../../../composables/admin/useConfirm';
import { useToast } from '../../../composables/admin/useToast';
import {
  formatCents,
  handOutStatus,
  matchesRegistration,
  saleLines,
  shirtsApi,
  shortfalls,
  type AdhocSaleRow,
  type ShirtInventory,
  type ShirtLine,
  type ShirtTypeRow,
} from './tshirts';

const route = useRoute();
const router = useRouter();
const toast = useToast();
const { confirm } = useConfirm();

const inventory = ref<ShirtInventory | null>(null);
const loading = ref(false);
const loadError = ref<string | null>(null);
const busyLineId = ref<number | null>(null);
const search = ref('');
const hideComplete = ref(false);
const stockDraft = ref<Record<number, number>>({});
const saleCounts = ref<Record<number, number>>({});
const saving = ref(false);

const showId = computed<number | null>(() => {
  const raw = Array.isArray(route.query.show_id) ? route.query.show_id[0] : route.query.show_id;
  return typeof raw === 'string' && /^\d+$/.test(raw) ? Number(raw) : null;
});

const types = computed<ShirtTypeRow[]>(() => inventory.value?.types ?? []);
const stockChanged = computed(() => types.value.filter((type) => stockDraft.value[type.id] !== type.stock));
const saleTotal = computed(() => Object.values(saleCounts.value).reduce((sum, count) => sum + count, 0));
const registrations = computed(() => inventory.value?.registrations ?? []);
const visibleRegistrations = computed(() =>
  registrations.value.filter((row) => matchesRegistration(row, search.value) && !(hideComplete.value && row.all_handed_out)),
);
const handedTotal = computed(() => types.value.reduce((sum, type) => sum + type.handed_out, 0));
const lineTotal = computed(() => registrations.value.reduce((sum, row) => sum + row.lines.length, 0));
const shortage = computed(() => shortfalls(types.value));
const shortageTotals = computed(() => ({
  committed: shortage.value.reduce((sum, item) => sum + item.committed, 0),
  stock: shortage.value.reduce((sum, item) => sum + item.stock, 0),
  short: shortage.value.reduce((sum, item) => sum + item.short, 0),
}));

function chipClass(type: ShirtTypeRow): string {
  if (type.remaining < 0) return 'bg-danger-bg text-danger-fg';
  if (type.remaining === 0) return 'bg-warning-bg text-warning-fg';
  return 'bg-success-bg text-success-fg';
}

function chipTitle(type: ShirtTypeRow): string {
  return `On hand: ${type.stock}, handed out: ${type.handed_out}, ad-hoc sold: ${type.adhoc_sold} (available: ${type.remaining})`;
}

function resetDrafts(data: ShirtInventory): void {
  stockDraft.value = Object.fromEntries(data.types.map((type) => [type.id, type.stock]));
  saleCounts.value = Object.fromEntries(data.types.map((type) => [type.id, 0]));
}

async function load(): Promise<void> {
  if (showId.value === null) {
    inventory.value = null;
    return;
  }
  loading.value = true;
  loadError.value = null;
  try {
    const data = await shirtsApi.inventory(showId.value);
    inventory.value = data;
    resetDrafts(data);
  } catch (error) {
    loadError.value = mapAdminError(error, 'The T-shirt list could not be loaded.').message;
  } finally {
    loading.value = false;
  }
}

function onShowChange(value: number | null): void {
  void router.replace({ query: { ...route.query, show_id: value === null ? undefined : String(value) } });
}

function stepStock(typeId: number, delta: number): void {
  stockDraft.value = { ...stockDraft.value, [typeId]: Math.max(0, (stockDraft.value[typeId] ?? 0) + delta) };
}

async function saveStock(): Promise<void> {
  if (showId.value === null) return;
  const changed = Object.fromEntries(stockChanged.value.map((type) => [type.id, stockDraft.value[type.id] ?? 0]));
  saving.value = true;
  try {
    const data = await shirtsApi.setStock(showId.value, changed);
    inventory.value = data;
    resetDrafts(data);
    toast.success('Stock saved.');
  } catch (error) {
    toast.error(mapAdminError(error, 'The stock could not be saved.').message);
  } finally {
    saving.value = false;
  }
}

async function toggleLine(line: ShirtLine): Promise<void> {
  busyLineId.value = line.id;
  try {
    await shirtsApi.setHandedOut(line, !line.handed_out);
    await load();
  } catch (error) {
    toast.error(mapAdminError(error, 'The hand-out could not be recorded.').message);
  } finally {
    busyLineId.value = null;
  }
}

function availableFor(type: ShirtTypeRow): number {
  return Math.max(0, type.available_for_adhoc);
}

function stepSale(type: ShirtTypeRow, delta: number): void {
  const next = (saleCounts.value[type.id] ?? 0) + delta;
  saleCounts.value = { ...saleCounts.value, [type.id]: Math.min(availableFor(type), Math.max(0, next)) };
}

async function recordSale(): Promise<void> {
  if (showId.value === null || saleTotal.value === 0) return;
  saving.value = true;
  try {
    const data = await shirtsApi.recordSale(showId.value, saleLines(saleCounts.value));
    inventory.value = data;
    resetDrafts(data);
    toast.success('Sale recorded.');
  } catch (error) {
    const mapped = mapAdminError(error, 'The sale could not be recorded.');
    toast.error(mapped.fieldErrors.type_ids?.[0] ?? mapped.message);
  } finally {
    saving.value = false;
  }
}

async function deleteSale(sale: AdhocSaleRow): Promise<void> {
  const ok = await confirm({
    title: 'Delete sale',
    message: 'Delete this ad-hoc shirt sale? This will put the shirt(s) back into available inventory.',
    confirmLabel: 'Delete sale',
    danger: true,
  });
  if (!ok) return;
  try {
    await shirtsApi.deleteSale(sale);
    toast.success('Sale deleted.');
    await load();
  } catch (error) {
    toast.error(mapAdminError(error, 'The sale could not be deleted.').message);
  }
}

function printSheet(): void {
  window.print();
}

watch(showId, () => void load());
onMounted(() => void load());
</script>

<template>
  <div class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-3 print:hidden">
      <h1 class="page-title">
        T-shirts
      </h1>
      <BaseButton
        size="lg"
        class="min-h-12"
        :disabled="inventory === null"
        @click="printSheet"
      >
        Printable shirt checklist
      </BaseButton>
    </div>

    <div class="grid gap-3 sm:grid-cols-2 print:hidden">
      <div>
        <label
          for="tshirt-show"
          class="block text-sm font-medium text-text"
        >Event</label>
        <div class="mt-1">
          <RelationSelect
            id="tshirt-show"
            :endpoint="`${ADMIN_API_BASE}/lookups/events`"
            :model-value="showId"
            placeholder="Choose an event"
            @update:model-value="onShowChange"
          />
        </div>
      </div>
      <div>
        <label
          for="tshirt-search"
          class="block text-sm font-medium text-text"
        >Narrow by name or registration number</label>
        <input
          id="tshirt-search"
          v-model="search"
          type="search"
          autocomplete="off"
          placeholder="Name or reg # (e.g. 42 or #42)…"
          class="mt-1 min-h-12 w-full rounded-md border border-border bg-surface px-3 text-base"
        >
      </div>
    </div>

    <p
      v-if="showId === null"
      class="rounded-md border border-border bg-subtle p-4 text-sm print:hidden"
    >
      Choose an event to see its T-shirts.
    </p>
    <p
      v-else-if="loadError"
      role="alert"
      class="rounded-md border border-danger-border bg-danger-bg p-4 text-sm"
    >
      {{ loadError }}
    </p>
    <p
      v-else-if="loading && inventory === null"
      class="text-sm text-muted"
    >
      Loading…
    </p>

    <template v-if="inventory">
      <section
        aria-labelledby="tshirt-tally-heading"
        class="space-y-3 print:hidden"
      >
        <h2
          id="tshirt-tally-heading"
          class="text-lg font-semibold"
        >
          Available now
        </h2>
        <p class="text-sm text-muted">
          On-hand count minus handed out (orders are separate; shortfalls show below).
        </p>
        <ul class="flex flex-wrap gap-2">
          <li
            v-for="type in types"
            :key="type.id"
            class="rounded-full px-3 py-1 text-sm"
            :class="chipClass(type)"
            :title="chipTitle(type)"
          >
            {{ type.name }}: {{ type.remaining }} available
          </li>
        </ul>
        <div
          v-if="shortage.length > 0"
          role="alert"
          class="rounded-md border border-danger-border bg-danger-bg p-4 text-sm"
        >
          <p v-if="shortage.length === 1">
            <strong>Inventory shortfall</strong> — <strong>{{ shortage[0].name }}</strong>: {{ shortage[0].committed }} ordered/sold,
            {{ shortage[0].stock }} available ({{ shortage[0].short }} short)
          </p>
          <template v-else>
            <p>
              <strong>Inventory shortfall</strong> — <strong>Total:</strong> {{ shortageTotals.committed }} ordered/sold,
              {{ shortageTotals.stock }} available ({{ shortageTotals.short }} short)
            </p>
            <ul class="mt-2 list-disc pl-5">
              <li
                v-for="item in shortage"
                :key="item.name"
              >
                <strong>{{ item.name }}</strong>: {{ item.committed }} ordered/sold, {{ item.stock }} available ({{ item.short }} short)
              </li>
            </ul>
          </template>
        </div>
      </section>

      <section
        aria-labelledby="tshirt-stock-heading"
        class="space-y-3 print:hidden"
      >
        <h2
          id="tshirt-stock-heading"
          class="text-lg font-semibold"
        >
          Shirt inventory for this event
        </h2>
        <p class="text-sm text-muted">
          Enter how many of each shirt you have on hand. Ordered totals are shown for reference and shortfall warnings.
        </p>
        <div class="overflow-x-auto">
          <table
            class="w-full min-w-[32rem] text-sm"
            aria-label="Stock by shirt type"
          >
            <thead>
              <tr class="border-b border-border text-left">
                <th
                  scope="col"
                  class="py-2 pr-3"
                >
                  Shirt
                </th>
                <th
                  scope="col"
                  class="py-2 pr-3 text-right"
                >
                  On hand
                </th>
                <th
                  scope="col"
                  class="py-2 pr-3 text-right"
                >
                  Ordered
                </th>
                <th
                  scope="col"
                  class="py-2 pr-3 text-right"
                >
                  Handed out
                </th>
                <th
                  scope="col"
                  class="py-2 pr-3 text-right"
                >
                  Ad-hoc sold
                </th>
                <th
                  scope="col"
                  class="py-2 text-right"
                >
                  Available
                </th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="type in types"
                :key="type.id"
                class="border-b border-border"
              >
                <th
                  scope="row"
                  class="py-2 pr-3 text-left font-medium"
                >
                  {{ type.name }}
                  <span
                    v-if="type.price_cents !== null"
                    class="block text-xs text-muted"
                  >{{ formatCents(type.price_cents) }}</span>
                </th>
                <td class="py-2 pr-3">
                  <div class="flex items-center justify-end gap-1">
                    <BaseButton
                      size="lg"
                      class="min-h-12 min-w-12"
                      :aria-label="`Fewer ${type.name} on hand`"
                      :disabled="(stockDraft[type.id] ?? 0) === 0"
                      @click="stepStock(type.id, -1)"
                    >
                      −
                    </BaseButton>
                    <input
                      v-model.number="stockDraft[type.id]"
                      type="number"
                      min="0"
                      max="65535"
                      :aria-label="`${type.name} on hand`"
                      class="min-h-12 w-20 rounded-md border border-border bg-surface px-2 text-right text-base"
                    >
                    <BaseButton
                      size="lg"
                      class="min-h-12 min-w-12"
                      :aria-label="`More ${type.name} on hand`"
                      @click="stepStock(type.id, 1)"
                    >
                      +
                    </BaseButton>
                  </div>
                </td>
                <td class="py-2 pr-3 text-right">
                  {{ type.ordered }}
                </td>
                <td class="py-2 pr-3 text-right">
                  {{ type.handed_out }}
                </td>
                <td class="py-2 pr-3 text-right">
                  {{ type.adhoc_sold }}
                </td>
                <td
                  class="py-2 text-right font-semibold"
                  :class="type.remaining < 0 ? 'text-danger-fg' : ''"
                >
                  {{ type.remaining }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <div class="flex justify-end">
          <BaseButton
            variant="primary"
            size="lg"
            class="min-h-12"
            :loading="saving"
            :disabled="stockChanged.length === 0"
            @click="saveStock"
          >
            Save inventory
          </BaseButton>
        </div>
      </section>

      <section
        aria-labelledby="tshirt-hand-heading"
        class="space-y-3 print:hidden"
      >
        <h2
          id="tshirt-hand-heading"
          class="text-lg font-semibold"
        >
          Hand-outs
          <span class="text-sm font-normal text-muted">{{ handedTotal }} of {{ lineTotal }} handed out</span>
        </h2>
        <label class="flex min-h-12 items-center gap-2 text-base">
          <input
            v-model="hideComplete"
            type="checkbox"
            class="h-6 w-6"
          >
          Hide complete
        </label>
        <p
          v-if="visibleRegistrations.length === 0"
          class="rounded-md border border-border bg-subtle p-4 text-sm"
        >
          No shirts match.
        </p>
        <div
          v-else
          class="overflow-x-auto"
        >
          <table
            class="w-full min-w-[40rem] text-sm"
            aria-label="Registrations with shirts"
          >
            <thead>
              <tr class="border-b border-border text-left">
                <th
                  scope="col"
                  class="py-2 pr-3"
                >
                  Reg #
                </th>
                <th
                  scope="col"
                  class="py-2 pr-3"
                >
                  Name
                </th>
                <th
                  scope="col"
                  class="py-2 pr-3"
                >
                  T-shirts
                </th>
                <th
                  scope="col"
                  class="py-2"
                >
                  Status
                </th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="row in visibleRegistrations"
                :key="row.id"
                class="border-b border-border align-top"
                :class="row.all_handed_out ? 'text-muted' : ''"
                :data-registration-id="row.id"
              >
                <td class="py-2 pr-3">
                  {{ row.invoice_id }}
                </td>
                <td
                  class="py-2 pr-3"
                  :class="row.all_handed_out ? 'line-through' : ''"
                >
                  {{ row.primary_name }}
                </td>
                <td class="py-2 pr-3">
                  <ul class="grid gap-2 sm:grid-cols-2">
                    <li
                      v-for="line in row.lines"
                      :key="line.id"
                    >
                      <BaseButton
                        block
                        size="lg"
                        class="min-h-12"
                        :variant="line.handed_out ? 'primary' : 'default'"
                        :aria-pressed="line.handed_out ? 'true' : 'false'"
                        :loading="busyLineId === line.id"
                        @click="toggleLine(line)"
                      >
                        {{ line.type_name }}: {{ line.handed_out ? 'handed out' : 'not handed out' }}
                      </BaseButton>
                    </li>
                  </ul>
                </td>
                <td class="py-2">
                  <span
                    class="rounded-full px-2 py-0.5"
                    :class="handOutStatus(row).label === 'All handed out' ? 'bg-success-bg text-success-fg' : 'bg-warning-bg text-warning-fg'"
                  >{{ handOutStatus(row).label }}</span>
                  <span
                    v-if="handOutStatus(row).label === 'Partial'"
                    class="block text-muted"
                  >{{ handOutStatus(row).left }} left to hand out</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section
        aria-labelledby="tshirt-sale-heading"
        class="space-y-3 rounded-md border border-border bg-surface p-4 print:hidden"
      >
        <h2
          id="tshirt-sale-heading"
          class="text-lg font-semibold"
        >
          Sell ad-hoc shirt
        </h2>
        <p class="text-sm text-muted">
          Choose shirts from the remaining event inventory. This records inventory only and does not collect payment.
        </p>
        <ul class="grid gap-3 sm:grid-cols-2">
          <li
            v-for="type in types"
            :key="type.id"
            class="flex items-center justify-between gap-2"
          >
            <span>
              {{ type.name }}
              <span class="block text-xs text-muted">{{ availableFor(type) }} available</span>
            </span>
            <div class="flex items-center gap-1">
              <BaseButton
                size="lg"
                class="min-h-12 min-w-12"
                :aria-label="`Remove one ${type.name} from the sale`"
                :disabled="(saleCounts[type.id] ?? 0) === 0"
                @click="stepSale(type, -1)"
              >
                −
              </BaseButton>
              <span
                class="min-w-10 text-center text-lg font-semibold"
                :aria-label="`${type.name} in sale`"
              >{{ saleCounts[type.id] ?? 0 }}</span>
              <BaseButton
                size="lg"
                class="min-h-12 min-w-12"
                :aria-label="`Add one ${type.name} to the sale`"
                :disabled="(saleCounts[type.id] ?? 0) >= availableFor(type)"
                @click="stepSale(type, 1)"
              >
                +
              </BaseButton>
            </div>
          </li>
        </ul>
        <div class="flex justify-end">
          <BaseButton
            variant="primary"
            size="lg"
            class="min-h-12"
            :loading="saving"
            :disabled="saleTotal === 0"
            @click="recordSale"
          >
            Record sale ({{ saleTotal }})
          </BaseButton>
        </div>
      </section>

      <section
        aria-labelledby="tshirt-sales-heading"
        class="space-y-3 print:hidden"
      >
        <h2
          id="tshirt-sales-heading"
          class="text-lg font-semibold"
        >
          Ad-hoc shirt sales
        </h2>
        <p
          v-if="inventory.adhoc_sales.length === 0"
          class="text-sm text-muted"
        >
          No ad-hoc sales yet.
        </p>
        <div
          v-else
          class="overflow-x-auto"
        >
          <table
            class="w-full min-w-[32rem] text-sm"
            aria-label="Ad-hoc sales"
          >
            <thead>
              <tr class="border-b border-border text-left">
                <th
                  scope="col"
                  class="py-2 pr-3"
                >
                  Sold
                </th>
                <th
                  scope="col"
                  class="py-2 pr-3"
                >
                  Shirts
                </th>
                <th
                  scope="col"
                  class="py-2 pr-3"
                >
                  Sold by
                </th>
                <th
                  scope="col"
                  class="py-2"
                >
                  Action
                </th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="sale in inventory.adhoc_sales"
                :key="sale.id"
                class="border-b border-border"
              >
                <td class="py-2 pr-3">
                  {{ sale.created_at ? new Date(sale.created_at).toLocaleString() : '' }}
                </td>
                <td class="py-2 pr-3">
                  {{ sale.items.map((item) => (item.count > 1 ? `${item.count}x ${item.type_name}` : item.type_name)).join(', ') }}
                </td>
                <td class="py-2 pr-3">
                  {{ sale.sold_by }}
                </td>
                <td class="py-2">
                  <BaseButton
                    size="lg"
                    class="min-h-12"
                    :aria-label="`Delete sale ${sale.id}`"
                    @click="deleteSale(sale)"
                  >
                    Delete
                  </BaseButton>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section
        class="hidden print:block"
        aria-label="Printable shirt checklist"
      >
        <h1 class="text-xl font-bold">
          T-shirt pickup — {{ inventory.show.name }}
        </h1>
        <p class="mt-1 text-sm">
          Shirts left to right. <strong>Bold, filled box</strong> = already handed out in the app; empty ☐ = mark on paper when picked up.
          <strong>Struck name</strong> = all shirts handed out. · {{ registrations.length }} registration{{ registrations.length === 1 ? '' : 's' }}
        </p>
        <div
          v-for="row in registrations"
          :key="row.id"
          class="mt-3 break-inside-avoid border-t border-border pt-2"
        >
          <p>
            <span class="font-semibold">#{{ row.invoice_id }}</span>
            <span :class="row.all_handed_out ? 'line-through' : ''"> {{ row.primary_name }}</span>
          </p>
          <ul class="mt-1 flex flex-wrap gap-4">
            <li
              v-for="line in row.lines"
              :key="line.id"
              :class="line.handed_out ? 'font-bold' : ''"
            >
              {{ line.handed_out ? '☑' : '☐' }} {{ line.type_name }}
            </li>
          </ul>
        </div>
        <h2 class="mt-6 font-semibold">
          Inventory totals
        </h2>
        <p class="text-sm">
          One ☐ per shirt on hand; filled ☑ = already handed out or ad-hoc sold in the app. “Available” is on-hand inventory minus handed out and
          ad-hoc sold shirts.
        </p>
        <table class="mt-2 w-full border-collapse text-sm">
          <thead>
            <tr class="border-b text-left">
              <th class="py-1 pr-2">
                Shirt
              </th>
              <th class="py-1 pr-2 text-right">
                On hand
              </th>
              <th class="py-1 pr-2 text-right">
                Ordered
              </th>
              <th class="py-1 pr-2 text-right">
                Ad-hoc sold
              </th>
              <th class="py-1 pr-2">
                On-hand units
              </th>
              <th class="py-1 text-right">
                Available
              </th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="type in types"
              :key="type.id"
              class="border-b align-top"
            >
              <td class="py-1 pr-2">
                {{ type.name }}
              </td>
              <td class="py-1 pr-2 text-right">
                {{ type.stock }}
              </td>
              <td class="py-1 pr-2 text-right">
                {{ type.ordered }}
              </td>
              <td class="py-1 pr-2 text-right">
                {{ type.adhoc_sold }}
              </td>
              <td class="py-1 pr-2">
                <span v-if="type.stock === 0">—</span>
                <span v-else>{{ Array.from({ length: type.stock }, (_, unit) => (unit < type.handed_out + type.adhoc_sold ? '☑' : '☐')).join(' ') }}</span>
              </td>
              <td class="py-1 text-right">
                {{ type.remaining }}
              </td>
            </tr>
            <tr class="font-semibold">
              <td class="py-1 pr-2">
                All types
              </td>
              <td class="py-1 pr-2 text-right">
                {{ types.reduce((sum, type) => sum + type.stock, 0) }}
              </td>
              <td class="py-1 pr-2 text-right">
                {{ types.reduce((sum, type) => sum + type.ordered, 0) }}
              </td>
              <td class="py-1 pr-2 text-right">
                {{ types.reduce((sum, type) => sum + type.adhoc_sold, 0) }}
              </td>
              <td class="py-1 pr-2" />
              <td class="py-1 text-right">
                {{ types.reduce((sum, type) => sum + type.remaining, 0) }}
              </td>
            </tr>
          </tbody>
        </table>
      </section>
    </template>
  </div>
</template>
