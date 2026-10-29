<script setup lang="ts">
/**
 * T-shirts for one event, laid out like the legacy /admin2 tshirt_list: event filter, the "In-stock remaining" well
 * (tally chips, shortfall warning, Set shirt inventory), the registrations table (cards on phones; tap a card or
 * Shirts to hand shirts out), the printable checklist (?print=1, opened in a new tab) and ad-hoc sales. The event is
 * in the hash query (?show_id=). Stock and sales are checked by the server under a lock; counts are re-read after
 * each change.
 */
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import EventFilter from '../../../components/admin/EventFilter.vue';
import { mapAdminError } from '../../../composables/admin/useAdminResource';
import { useConfirm } from '../../../composables/admin/useConfirm';
import { useToast } from '../../../composables/admin/useToast';
import TshirtHandoffDialog from './components/TshirtHandoffDialog.vue';
import TshirtInventoryDialog from './components/TshirtInventoryDialog.vue';
import TshirtPrintSheet from './components/TshirtPrintSheet.vue';
import TshirtSaleDialog from './components/TshirtSaleDialog.vue';
import {
  groupedLines,
  handOutStatus,
  matchesRegistration,
  shirtsApi,
  shortfalls,
  tallyTypes,
  type AdhocSaleRow,
  type ShirtInventory,
  type ShirtLine,
  type ShirtRegistrationRow,
  type ShirtTypeRow,
} from './tshirts';

const route = useRoute();
const router = useRouter();
const toast = useToast();
const { confirm } = useConfirm();

const inventory = ref<ShirtInventory | null>(null);
const loading = ref(false);
const loadError = ref<string | null>(null);
const search = ref('');
const hideComplete = ref(true);
const saving = ref(false);
const openRegistrationId = ref<number | null>(null);
const busyLineId = ref<number | null>(null);
const handoffError = ref<string | null>(null);
const inventoryOpen = ref(false);
const saleOpen = ref(false);
const saleError = ref<string | null>(null);
const searchInput = ref<HTMLInputElement | null>(null);

const showId = computed<number | null>(() => {
  const raw = Array.isArray(route.query.show_id) ? route.query.show_id[0] : route.query.show_id;
  return typeof raw === 'string' && /^\d+$/.test(raw) ? Number(raw) : null;
});
const printing = computed(() => route.query.print === '1');
const printHref = computed(() => `#/admin/tshirts?show_id=${showId.value}&print=1`);
const backHref = computed(() => `#/admin/tshirts?show_id=${showId.value}`);

const types = computed<ShirtTypeRow[]>(() => inventory.value?.types ?? []);
const chips = computed(() => tallyTypes(types.value));
const registrations = computed(() => inventory.value?.registrations ?? []);
const visibleRegistrations = computed(() =>
  registrations.value.filter((row) => matchesRegistration(row, search.value) && !(hideComplete.value && row.all_handed_out)),
);
const openRegistration = computed(() => registrations.value.find((row) => row.id === openRegistrationId.value) ?? null);
const shortage = computed(() => shortfalls(types.value));
const shortageSummary = computed(() => {
  const list = shortage.value;
  if (list.length === 1) {
    const [one] = list;
    return { name: one!.name, committed: one!.committed, stock: one!.stock, short: one!.short };
  }
  return {
    name: 'Total:',
    committed: list.reduce((sum, item) => sum + item.committed, 0),
    stock: list.reduce((sum, item) => sum + item.stock, 0),
    short: list.reduce((sum, item) => sum + item.short, 0),
  };
});

function chipClass(type: ShirtTypeRow): string {
  if (type.remaining < 0) return 'a2-label-important';
  if (type.remaining === 0) return 'a2-label-warning';
  return 'a2-label-success';
}

function chipTitle(type: ShirtTypeRow): string {
  return `On hand: ${type.stock}, handed out: ${type.handed_out}, ad-hoc sold: ${type.adhoc_sold} (available: ${type.remaining})`;
}

function statusClass(row: ShirtRegistrationRow): string {
  const label = handOutStatus(row).label;
  if (label === 'All handed out') return 'a2-label-success';
  return label === 'Partial' ? 'a2-label-partial' : 'a2-label-pending';
}

async function load(): Promise<void> {
  if (showId.value === null) {
    inventory.value = null;
    return;
  }
  loading.value = true;
  loadError.value = null;
  try {
    inventory.value = await shirtsApi.inventory(showId.value);
  } catch (error) {
    loadError.value = mapAdminError(error, 'The T-shirt list could not be loaded.').message;
  } finally {
    loading.value = false;
  }
}

function onShowChange(value: number | null): void {
  void router.replace({ query: { ...route.query, show_id: value === null ? undefined : String(value) } });
}

function openShirts(row: ShirtRegistrationRow): void {
  handoffError.value = null;
  openRegistrationId.value = row.id;
}

/** A click anywhere on the row or card opens the shirts dialog (legacy phone behaviour); the Shirts button is the keyboard path. */
function onRowClick(event: MouseEvent, row: ShirtRegistrationRow): void {
  if ((event.target as HTMLElement).closest('button, a, input')) return;
  openShirts(row);
}

async function toggleLine(line: ShirtLine, handedOut: boolean): Promise<void> {
  busyLineId.value = line.id;
  handoffError.value = null;
  try {
    await shirtsApi.setHandedOut(line, handedOut);
    await load();
  } catch (error) {
    handoffError.value = mapAdminError(error, 'Could not update shirt').message;
  } finally {
    busyLineId.value = null;
  }
}

async function saveStock(stock: Record<number, number>): Promise<void> {
  if (showId.value === null) return;
  saving.value = true;
  try {
    inventory.value = await shirtsApi.setStock(showId.value, stock);
    inventoryOpen.value = false;
    toast.success('Stock saved.');
  } catch (error) {
    toast.error(mapAdminError(error, 'The stock could not be saved.').message);
  } finally {
    saving.value = false;
  }
}

function openSale(): void {
  saleError.value = null;
  saleOpen.value = true;
}

async function recordSale(typeIds: number[]): Promise<void> {
  if (showId.value === null || typeIds.length === 0) return;
  saving.value = true;
  saleError.value = null;
  try {
    inventory.value = await shirtsApi.recordSale(showId.value, typeIds);
    saleOpen.value = false;
    toast.success('Sale recorded.');
  } catch (error) {
    const mapped = mapAdminError(error, 'The sale could not be recorded.');
    saleError.value = mapped.fieldErrors.type_ids?.[0] ?? mapped.message;
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

function saleText(sale: AdhocSaleRow): string {
  return sale.items.map((item) => (item.count > 1 ? `${item.count}x ${item.type_name}` : item.type_name)).join(', ');
}

function clearSearch(): void {
  search.value = '';
  searchInput.value?.focus();
}

watch(showId, () => void load());
onMounted(() => void load());
</script>

<template>
  <div class="a2-page">
    <h1 class="sr-only">
      T-shirts
    </h1>

    <TshirtPrintSheet
      v-if="printing && inventory"
      :inventory="inventory"
      :back-to="backHref"
    />

    <template v-if="!printing">
      <EventFilter
        :model-value="showId"
        @update:model-value="onShowChange"
      />

      <p
        v-if="showId === null"
        class="a2-well"
      >
        Choose an event to see its T-shirts.
      </p>
      <p
        v-else-if="loadError"
        role="alert"
        class="a2-alert a2-alert-error"
      >
        {{ loadError }}
      </p>
      <p
        v-else-if="loading && inventory === null"
        class="a2-muted"
      >
        Loading…
      </p>

      <template v-if="inventory">
        <section
          class="a2-well"
          aria-labelledby="tshirt-tally-heading"
        >
          <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-2">
            <h2
              id="tshirt-tally-heading"
              class="font-bold"
            >
              In-stock remaining
            </h2>
            <span class="a2-muted a2-small min-w-0 flex-1 basis-48">on-hand count minus handed out (orders are separate; shortfalls show below)</span>
            <button
              type="button"
              class="a2-btn a2-btn-info"
              @click="inventoryOpen = true"
            >
              Set shirt inventory
            </button>
          </div>
          <details
            v-if="shortage.length > 0"
            class="a2-alert a2-alert-error mt-2.5 mb-0"
          >
            <summary class="cursor-pointer">
              <strong>Inventory shortfall</strong> — <strong>{{ shortageSummary.name }}</strong>
              {{ shortageSummary.committed }} ordered/sold, {{ shortageSummary.stock }} available ({{ shortageSummary.short }} short)
            </summary>
            <ul
              v-if="shortage.length > 1"
              class="mt-2 ml-4.5 list-disc"
            >
              <li
                v-for="item in shortage"
                :key="item.name"
              >
                <strong>{{ item.name }}</strong>: {{ item.committed }} ordered/sold, {{ item.stock }} available ({{ item.short }} short)
              </li>
            </ul>
          </details>
          <p
            v-if="chips.length === 0"
            class="a2-muted mt-2"
          >
            No shirt types to display yet. Set inventory or open registrations with shirt orders.
          </p>
          <ul
            v-else
            class="mt-2 flex flex-wrap gap-x-1.5 gap-y-1.5"
            aria-label="Shirts available"
          >
            <li
              v-for="type in chips"
              :key="type.id"
              class="a2-label"
              :class="chipClass(type)"
              :title="chipTitle(type)"
            >
              {{ type.name }}: {{ type.remaining }} available
            </li>
          </ul>
        </section>

        <div class="a2-toolbar">
          <label class="a2-check">
            <input
              v-model="hideComplete"
              type="checkbox"
            >
            Hide fully handed out
          </label>
          <span
            class="a2-toolbar-count"
            aria-live="polite"
          ><b>Showing:</b> {{ visibleRegistrations.length }}</span>
        </div>

        <div class="a2-search">
          <label
            for="tshirt-search"
            class="a2-search-label"
          >Narrow by name or registration number</label>
          <div class="a2-search-field">
            <input
              id="tshirt-search"
              ref="searchInput"
              v-model="search"
              type="search"
              autocomplete="off"
              placeholder="Name or reg # (e.g. 42 or #42)…"
              class="a2-input"
            >
            <button
              v-if="search.trim() !== ''"
              type="button"
              class="a2-search-clear"
              aria-label="Clear search"
              title="Clear search"
              @click="clearSearch"
            >
              <span aria-hidden="true">&times;</span>
            </button>
          </div>
        </div>

        <table
          class="a2-table a2-cards a2-cards--pair"
          aria-label="Registrations with shirts"
        >
          <thead>
            <tr>
              <th scope="col">
                Reg #
              </th>
              <th scope="col">
                Name
              </th>
              <th scope="col">
                T-shirts
              </th>
              <th scope="col">
                Status
              </th>
              <th scope="col">
                Action
              </th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="row in visibleRegistrations"
              :key="row.id"
              :class="row.all_handed_out ? 'a2-row-done' : ''"
              :data-registration-id="row.id"
              data-tap
              @click="onRowClick($event, row)"
            >
              <td data-label="Reg #">
                {{ row.invoice_id }}
              </td>
              <td
                data-label="Name"
                class="a2-cell-title"
              >
                {{ row.primary_name }}
              </td>
              <td
                data-label="T-shirts"
                class="a2-cell-sep"
              >
                <template
                  v-for="(group, index) in groupedLines(row.lines)"
                  :key="group.key"
                >
                  <span v-if="index > 0">, </span><span :class="group.handedOut ? 'font-bold text-[#1d6b3a]' : 'font-semibold text-[#b35400]'">{{ group.label }}</span>
                </template>
              </td>
              <td data-label="Status">
                <span
                  class="a2-label"
                  :class="statusClass(row)"
                >{{ handOutStatus(row).label }}</span>
                <span
                  v-if="handOutStatus(row).label === 'Partial'"
                  class="a2-muted a2-small mt-1 block"
                >{{ handOutStatus(row).left }} left to hand out</span>
              </td>
              <td class="a2-cell-nolabel">
                <button
                  type="button"
                  class="a2-btn a2-btn-info a2-btn-block"
                  :aria-label="`Shirts for #${row.invoice_id} ${row.primary_name}`"
                  @click="openShirts(row)"
                >
                  Shirts
                </button>
              </td>
            </tr>
          </tbody>
        </table>
        <p
          v-if="visibleRegistrations.length === 0"
          class="a2-muted mb-4"
        >
          No shirts match.
        </p>

        <p class="mt-4 mb-6 flex flex-wrap items-center gap-x-3.5 gap-y-2.5">
          <a
            :href="printHref"
            target="_blank"
            rel="noopener"
            class="a2-btn"
          >Printable shirt checklist</a>
          <span class="a2-muted a2-small min-w-0 flex-1 basis-48">Opens a new tab — one block per registrant, shirts in a row with checkboxes; bold = already handed out in app.</span>
        </p>

        <h2 class="a2-legend">
          Ad-hoc shirt sales
        </h2>
        <p class="mb-2.5">
          <button
            type="button"
            class="a2-btn a2-btn-info"
            @click="openSale"
          >
            Sell shirt
          </button>
        </p>
        <p
          v-if="inventory.adhoc_sales.length === 0"
          class="a2-muted"
        >
          No ad-hoc shirt sales for this event.
        </p>
        <table
          v-else
          class="a2-table a2-cards"
          aria-label="Ad-hoc sales"
        >
          <thead>
            <tr>
              <th scope="col">
                Sold
              </th>
              <th scope="col">
                Shirts
              </th>
              <th scope="col">
                Sold by
              </th>
              <th scope="col">
                Action
              </th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="sale in inventory.adhoc_sales"
              :key="sale.id"
            >
              <td data-label="Sold">
                {{ sale.created_at ? new Date(sale.created_at.replace(' ', 'T')).toLocaleString() : '' }}
              </td>
              <td data-label="Shirts">
                {{ saleText(sale) }}
              </td>
              <td data-label="Sold by">
                {{ sale.sold_by }}
              </td>
              <td class="a2-cell-nolabel">
                <button
                  type="button"
                  class="a2-btn a2-btn-danger"
                  :aria-label="`Delete sale ${sale.id}`"
                  @click="deleteSale(sale)"
                >
                  Delete
                </button>
              </td>
            </tr>
          </tbody>
        </table>

        <TshirtHandoffDialog
          :row="openRegistration"
          :busy-line-id="busyLineId"
          :error="handoffError"
          @toggle="toggleLine"
          @close="openRegistrationId = null"
        />
        <TshirtInventoryDialog
          :open="inventoryOpen"
          :types="types"
          :saving="saving"
          @save="saveStock"
          @close="inventoryOpen = false"
        />
        <TshirtSaleDialog
          :open="saleOpen"
          :types="types"
          :saving="saving"
          :error="saleError"
          @save="recordSale"
          @close="saleOpen = false"
        />
      </template>
    </template>
  </div>
</template>
