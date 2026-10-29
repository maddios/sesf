<script setup lang="ts">
/**
 * The printable T-shirt pickup list (legacy tshirt_print.blade.php): one line per registrant, shirts left to right
 * with ☐/☑, then the inventory totals. Shown over the admin shell (?print=1, opened in a new tab like the legacy
 * sheet); when printing only the sheet is printed.
 */
import { computed } from 'vue';
import { tallyTypes, type ShirtInventory } from '../tshirts';

const props = defineProps<{ inventory: ShirtInventory; backTo: string }>();

const rows = computed(() => [...props.inventory.registrations].sort((a, b) => a.invoice_id - b.invoice_id));
const types = computed(() => tallyTypes(props.inventory.types));
const totals = computed(() => ({
  stock: types.value.reduce((sum, type) => sum + type.stock, 0),
  ordered: types.value.reduce((sum, type) => sum + type.ordered, 0),
  adhoc: types.value.reduce((sum, type) => sum + type.adhoc_sold, 0),
  remaining: types.value.reduce((sum, type) => sum + type.remaining, 0),
}));

function units(stock: number, used: number): boolean[] {
  return Array.from({ length: stock }, (_, unit) => unit < used);
}

function print(): void {
  window.print();
}
</script>

<template>
  <Teleport to="body">
    <div
      class="tps"
      role="region"
      aria-label="Printable shirt checklist"
    >
      <h1>T-shirt pickup — {{ inventory.show.name }}</h1>
      <p class="tps-meta">
        Shirts left→right. <strong>Bold + filled box</strong> = already handed out in app; empty ☐ = mark on paper when
        picked up. <strong>Struck name</strong> = all shirts handed out. · {{ rows.length }} reg{{ rows.length === 1 ? '' : 's' }} · by #
      </p>
      <div class="tps-tools">
        <button
          type="button"
          class="tps-btn"
          @click="print"
        >
          Print
        </button>
        <a
          class="tps-btn tps-btn--ghost"
          :href="backTo"
        >Back to T-shirts</a>
      </div>
      <p
        v-if="rows.length === 0"
        class="tps-empty"
      >
        No shirt orders for this event.
      </p>
      <section
        v-for="row in rows"
        :key="row.id"
        class="tps-user"
      >
        <div class="tps-user-hd">
          <span class="tps-reg">#{{ row.invoice_id }}</span>
          <span :class="row.all_handed_out ? 'tps-name--done' : ''">{{ row.primary_name }}</span>
        </div>
        <div class="tps-shirts">
          <div
            v-for="line in row.lines"
            :key="line.id"
            class="tps-item"
          >
            <span
              class="tps-check"
              :class="line.handed_out ? 'tps-check--done' : ''"
              :title="line.handed_out ? 'Handed out in app' : undefined"
            >{{ line.handed_out ? '☑' : '☐' }}</span>
            <span :class="line.handed_out ? 'font-bold' : ''">{{ line.type_name }}</span>
          </div>
        </div>
      </section>
      <section
        v-if="types.length > 0"
        class="tps-inventory"
      >
        <h2>Inventory totals</h2>
        <p class="tps-note">
          One ☐ per shirt on hand; filled ☑ = already handed out or ad-hoc sold in the app. “Available” is on-hand
          inventory minus handed out and ad-hoc sold shirts.
        </p>
        <table>
          <thead>
            <tr>
              <th>Shirt</th>
              <th class="num">
                On hand
              </th>
              <th class="num">
                Ordered
              </th>
              <th class="num">
                Ad-hoc sold
              </th>
              <th>On-hand units</th>
              <th class="num">
                Available
              </th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="type in types"
              :key="type.id"
            >
              <td>{{ type.name }}</td>
              <td class="num">
                {{ type.stock }}
              </td>
              <td class="num">
                {{ type.ordered }}
              </td>
              <td class="num">
                {{ type.adhoc_sold }}
              </td>
              <td>
                <span
                  v-if="type.stock === 0"
                  class="tps-muted"
                >—</span>
                <span
                  v-else
                  class="tps-units"
                >
                  <span
                    v-for="(done, unit) in units(type.stock, type.handed_out + type.adhoc_sold)"
                    :key="unit"
                    :class="done ? 'tps-check--done' : ''"
                  >{{ done ? '☑' : '☐' }}</span>
                </span>
              </td>
              <td class="num">
                {{ type.remaining }}
              </td>
            </tr>
            <tr class="tps-totals">
              <td>All types</td>
              <td class="num">
                {{ totals.stock }}
              </td>
              <td class="num">
                {{ totals.ordered }}
              </td>
              <td class="num">
                {{ totals.adhoc }}
              </td>
              <td />
              <td class="num">
                {{ totals.remaining }}
              </td>
            </tr>
          </tbody>
        </table>
      </section>
    </div>
  </Teleport>
</template>

<style scoped>
.tps {
  position: fixed;
  inset: 0;
  z-index: 60;
  overflow-y: auto;
  padding: 12px 14px 16px;
  background: #fff;
  color: #111;
  font-size: 13px;
  line-height: 1.35;
}

h1 {
  margin: 0 0 4px;
  font-size: 1.2rem;
  font-weight: 700;
}

.tps-meta {
  margin: 0 0 8px;
  color: #444;
  font-size: 12px;
}

.tps-tools {
  display: flex;
  gap: 8px;
  margin-bottom: 12px;
}

.tps-btn {
  display: inline-flex;
  align-items: center;
  min-height: 44px;
  padding: 7px 12px;
  font-size: 13px;
  font-weight: 600;
  color: #fff;
  text-decoration: none;
  background: #2563eb;
  border: 1px solid #2563eb;
  border-radius: 5px;
}

.tps-btn--ghost {
  color: #1d4ed8;
  background: #fff;
}

.tps-user {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px 14px;
  padding: 8px 0 10px;
  border-top: 1px solid #bbb;
  break-inside: avoid;
}

.tps-user:first-of-type {
  padding-top: 0;
  border-top: 0;
}

.tps-user-hd {
  flex: 0 0 auto;
  font-weight: 700;
  white-space: nowrap;
}

.tps-reg {
  margin-right: 6px;
  color: #374151;
}

.tps-name--done {
  color: #6b7280;
  font-weight: 600;
  text-decoration: line-through;
}

.tps-shirts {
  display: flex;
  flex: 1 1 auto;
  flex-wrap: wrap;
  align-items: center;
  gap: 2px 12px;
  min-width: 0;
}

.tps-item {
  display: flex;
  align-items: center;
  gap: 6px;
}

.tps-check {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 2rem;
  min-height: 1.85rem;
  font-size: 1.35rem;
  line-height: 1;
}

.tps-check--done {
  color: #000;
  font-weight: 700;
}

.tps-empty {
  padding: 14px;
  color: #666;
  border: 1px dashed #ccc;
}

.tps-inventory {
  margin-top: 1.25rem;
  padding-top: 12px;
  border-top: 1px solid #333;
}

.tps-inventory h2 {
  margin: 0 0 4px;
  font-size: 13px;
  font-weight: 700;
  letter-spacing: 0.03em;
  text-transform: uppercase;
  color: #374151;
}

.tps-note {
  margin: 0 0 8px;
  font-size: 11px;
  color: #555;
}

.tps-inventory table {
  width: 100%;
  border-collapse: collapse;
}

.tps-inventory :is(th, td) {
  padding: 5px 8px;
  text-align: left;
  border: 1px solid #bbb;
}

.tps-inventory th {
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.02em;
  text-transform: uppercase;
  background: #f3f4f6;
}

.tps-inventory .num {
  text-align: right;
  white-space: nowrap;
}

.tps-units {
  display: flex;
  flex-wrap: wrap;
  gap: 2px 5px;
  max-width: 18rem;
  font-size: 0.95rem;
}

.tps-muted {
  color: #767676;
}

.tps-totals td {
  font-weight: 700;
  background: #fafafa;
  border-top: 2px solid #333;
}

@media (max-width: 600px) {
  .tps-inventory {
    overflow-x: auto;
  }
}

@media print {
  .tps {
    position: static;
    overflow: visible;
    padding: 0;
  }

  .tps-tools {
    display: none;
  }

  .tps * {
    print-color-adjust: exact;
  }
}
</style>

<style>
@media print {
  body:has(.tps) > *:not(.tps) {
    display: none !important;
  }
}
</style>
