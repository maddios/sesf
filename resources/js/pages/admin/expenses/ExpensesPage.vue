<script setup lang="ts">
/**
 * Expense tracker for one event (legacy /expense_tracker, now admin-only), laid out like the legacy /admin2 page: event
 * filter, the registration income well, the expense and income lines (cards on phones), and the totals. The event is
 * kept in the hash query as ?show_id= (legacy URLs redirect here). Lines are added, edited and deleted in a sheet.
 * Expenses are positive and shown in red parentheses; income is negative.
 */
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import EventFilter from '../../../components/admin/EventFilter.vue';
import AdminFormModal from '../../../components/admin/AdminFormModal.vue';
import FormField from '../../../components/FormField.vue';
import MoneyInput from '../../../components/admin/MoneyInput.vue';
import { formatAdminDate } from '../../../components/admin/format';
import type { FormValues } from '../../../components/admin/types';
import { mapAdminError } from '../../../composables/admin/useAdminResource';
import { useConfirm } from '../../../composables/admin/useConfirm';
import { useToast } from '../../../composables/admin/useToast';
import {
  EXPENSE_FIELDS,
  expensesApi,
  fetchLedger,
  formatAmount,
  isNegative,
  isPositive,
  toForm,
  toInput,
  type Expense,
  type ExpenseLedger,
} from './expenses';

const route = useRoute();
const router = useRouter();
const toast = useToast();
const { confirm } = useConfirm();

const ledger = ref<ExpenseLedger | null>(null);
const loading = ref(false);
const error = ref<string | null>(null);
const editing = ref<Expense | null>(null);
const formOpen = ref(false);
const busyId = ref<number | null>(null);

const showId = computed<number | null>(() => {
  const raw = route.query.show_id;
  return typeof raw === 'string' && /^\d+$/.test(raw) ? Number(raw) : null;
});

async function load(): Promise<void> {
  const id = showId.value;
  ledger.value = null;
  error.value = null;
  if (id === null) return;
  loading.value = true;
  try {
    const result = await fetchLedger(id);
    if (showId.value === id) ledger.value = result;
  } catch (caught) {
    if (showId.value === id) error.value = mapAdminError(caught, 'The expenses could not be loaded.').message;
  } finally {
    loading.value = false;
  }
}

watch(showId, load, { immediate: true });

function chooseShow(id: number | null): void {
  const query = { ...route.query };
  if (id === null) delete query.show_id;
  else query.show_id = String(id);
  void router.replace({ path: route.path, query });
}

function openCreate(): void {
  editing.value = null;
  formOpen.value = true;
}

function openEdit(expense: Expense): void {
  editing.value = expense;
  formOpen.value = true;
}

function save(values: FormValues): Promise<Expense> {
  const input = toInput(values);
  return editing.value === null
    ? expensesApi.create({ ...input, show_id: showId.value as number })
    : expensesApi.update(editing.value.id, input);
}

function onSaved(): void {
  toast.success(editing.value === null ? 'Item added.' : 'Item saved.');
  void load();
}

async function remove(expense: Expense): Promise<void> {
  const ok = await confirm({
    title: 'Delete item',
    message: `Are you sure you want to delete “${expense.description || 'this entry'}”?`,
    confirmLabel: 'Delete',
    danger: true,
  });
  if (!ok) return;
  busyId.value = expense.id;
  try {
    await expensesApi.remove(expense.id);
    toast.success('Item deleted.');
    await load();
  } catch (caught) {
    toast.error(mapAdminError(caught, 'The item could not be deleted.').message);
  } finally {
    busyId.value = null;
  }
}

/** Total expenses: a net expense in red parentheses, net income with a minus sign (legacy). */
function totalText(decimal: string): string {
  if (isPositive(decimal)) return `(${formatAmount(decimal)})`;
  return isNegative(decimal) ? `-${formatAmount(decimal)}` : formatAmount(decimal);
}

function grossText(decimal: string): string {
  return isNegative(decimal) ? `(${formatAmount(decimal)})` : formatAmount(decimal);
}
</script>

<template>
  <div class="a2-page">
    <h1 class="sr-only">
      Expense tracker
    </h1>

    <EventFilter
      :model-value="showId"
      @update:model-value="chooseShow"
    />

    <p
      v-if="showId === null"
      class="a2-well"
    >
      Choose an event to see its expenses.
    </p>
    <p
      v-else-if="loading"
      class="a2-muted"
      role="status"
    >
      Loading expenses…
    </p>
    <div
      v-else-if="error"
      role="alert"
      class="a2-alert a2-alert-error"
    >
      <p>{{ error }}</p>
      <button
        type="button"
        class="a2-btn a2-btn-small mt-2"
        @click="load"
      >
        Try again
      </button>
    </div>

    <template v-else-if="ledger">
      <section
        class="a2-well"
        aria-labelledby="expenses-income"
      >
        <h2
          id="expenses-income"
          class="font-bold"
        >
          Registration Income
        </h2>
        <dl class="mt-2 grid max-w-xl grid-cols-[1fr_auto] gap-x-4 gap-y-1">
          <dt>Registration Fees</dt>
          <dd class="a2-num">
            {{ formatAmount(ledger.totals.registration_fees) }}
          </dd>
          <dt>Net (Actual received registration fees)</dt>
          <dd class="a2-num">
            {{ formatAmount(ledger.totals.net_received) }}
          </dd>
        </dl>
      </section>

      <section aria-labelledby="expenses-lines">
        <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-2">
          <h2
            id="expenses-lines"
            class="a2-legend"
          >
            Expense and Income
          </h2>
          <button
            type="button"
            class="a2-btn a2-btn-info"
            @click="openCreate"
          >
            New item
          </button>
        </div>
        <p class="a2-muted a2-small mb-2">
          Expenses are shown in red, in parentheses.
        </p>
        <table
          class="a2-table a2-cards"
          aria-labelledby="expenses-lines"
        >
          <thead>
            <tr>
              <th scope="col">
                Description
              </th>
              <th scope="col">
                Date
              </th>
              <th
                scope="col"
                class="a2-num"
              >
                Amount
              </th>
              <th scope="col">
                <span class="sr-only">Actions</span>
              </th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="ledger.data.length === 0">
              <td
                colspan="4"
                class="a2-cell-nolabel a2-muted"
              >
                No expenses or income yet.
              </td>
            </tr>
            <tr
              v-for="item in ledger.data"
              :key="item.id"
            >
              <td
                data-label="Description"
                class="a2-cell-title"
              >
                {{ item.description }}
              </td>
              <td data-label="Date">
                {{ formatAdminDate(item.created_at) }}
              </td>
              <td
                data-label="Amount"
                class="a2-num"
                :class="item.type === 'expense' ? 'text-[#a94442]' : undefined"
              >
                {{ item.type === 'expense' ? `(${formatAmount(item.amount)})` : formatAmount(item.amount) }}
                <span class="sr-only">{{ item.type }}</span>
              </td>
              <td class="a2-cell-nolabel">
                <div class="flex justify-end gap-1">
                  <button
                    type="button"
                    class="a2-btn a2-btn-info a2-btn-small"
                    :aria-label="`Edit ${item.description || `item ${item.id}`}`"
                    @click="openEdit(item)"
                  >
                    Edit
                  </button>
                  <button
                    type="button"
                    class="a2-btn a2-btn-danger a2-btn-small"
                    :disabled="busyId === item.id"
                    :aria-label="`Delete ${item.description || `item ${item.id}`}`"
                    @click="remove(item)"
                  >
                    Delete
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>

        <dl class="a2-well mt-3 grid max-w-xl grid-cols-[1fr_auto] gap-x-4 gap-y-1">
          <dt>Total Expenses</dt>
          <dd
            class="a2-num"
            :class="isPositive(ledger.totals.total_expenses) ? 'text-[#a94442]' : undefined"
          >
            {{ totalText(ledger.totals.total_expenses) }}
          </dd>
          <dt>Gross (Registration fees - Expenses)</dt>
          <dd
            class="a2-num"
            :class="isNegative(ledger.totals.gross) ? 'text-[#a94442]' : undefined"
          >
            {{ grossText(ledger.totals.gross) }}
          </dd>
        </dl>
      </section>
    </template>

    <AdminFormModal
      v-model:open="formOpen"
      :title="editing === null ? 'New item' : 'Edit item'"
      :fields="EXPENSE_FIELDS"
      :initial="toForm(editing)"
      :submit="save"
      :submit-label="editing === null ? 'Add item' : 'Save changes'"
      @saved="onSaved"
    >
      <template #field-amount="{ id, value, errors, setValue }">
        <FormField
          :id="id"
          v-slot="{ inputProps }"
          label="Amount (required)"
          :errors="errors"
        >
          <MoneyInput
            v-bind="inputProps"
            max="999999.99"
            :model-value="typeof value === 'string' ? value : null"
            @update:model-value="(amount: string | null) => setValue(amount)"
          />
        </FormField>
      </template>
    </AdminFormModal>
  </div>
</template>
