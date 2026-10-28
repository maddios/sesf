<script setup lang="ts">
/**
 * Expense tracker for one event (legacy /expense_tracker, now admin-only). The event is chosen above and kept in the
 * hash query as ?show_id= (legacy URLs redirect here). Shows the legacy totals: registration fees, net (actual
 * received), each expense and income line, total expenses and gross (net minus expenses). Lines are added, edited
 * and deleted in a dialog. Expenses are positive and shown in red parentheses; income is negative.
 */
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import FormField from '../../../components/FormField.vue';
import AdminFormModal from '../../../components/admin/AdminFormModal.vue';
import MoneyInput from '../../../components/admin/MoneyInput.vue';
import RelationSelect from '../../../components/admin/RelationSelect.vue';
import { formatAdminDate } from '../../../components/admin/format';
import type { FormValues } from '../../../components/admin/types';
import BaseButton from '../../../components/ui/BaseButton.vue';
import { ADMIN_API_BASE, mapAdminError } from '../../../composables/admin/useAdminResource';
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
  <div class="space-y-4">
    <h1 class="page-title">
      Expense tracker
    </h1>

    <div class="sm:w-80">
      <label
        for="expenses-show"
        class="block text-sm font-medium text-text"
      >Event</label>
      <div class="mt-1">
        <RelationSelect
          id="expenses-show"
          :endpoint="`${ADMIN_API_BASE}/lookups/events`"
          :model-value="showId"
          placeholder="Choose an event"
          @update:model-value="chooseShow"
        />
      </div>
    </div>

    <p
      v-if="showId === null"
      class="text-muted"
    >
      Choose an event to see its expenses.
    </p>
    <p
      v-else-if="loading"
      role="status"
    >
      Loading expenses…
    </p>
    <div
      v-else-if="error"
      role="alert"
      class="space-y-2"
    >
      <p class="text-danger">
        {{ error }}
      </p>
      <BaseButton
        size="sm"
        @click="load"
      >
        Try again
      </BaseButton>
    </div>

    <template v-else-if="ledger">
      <section aria-labelledby="expenses-income">
        <h2
          id="expenses-income"
          class="text-lg font-semibold"
        >
          Registration Income
        </h2>
        <dl class="mt-2 grid max-w-xl grid-cols-[1fr_auto] gap-x-4 gap-y-1">
          <dt>Registration Fees</dt>
          <dd class="text-right tabular-nums">
            {{ formatAmount(ledger.totals.registration_fees) }}
          </dd>
          <dt>Net (Actual received registration fees)</dt>
          <dd class="text-right tabular-nums">
            {{ formatAmount(ledger.totals.net_received) }}
          </dd>
        </dl>
      </section>

      <section
        aria-labelledby="expenses-lines"
        class="space-y-2"
      >
        <div class="flex flex-wrap items-center justify-between gap-2">
          <h2
            id="expenses-lines"
            class="text-lg font-semibold"
          >
            Expense and Income
          </h2>
          <BaseButton
            variant="primary"
            @click="openCreate"
          >
            New item
          </BaseButton>
        </div>
        <p class="text-sm text-muted">
          Expenses are shown in red, in parentheses.
        </p>
        <div class="overflow-x-auto">
          <table
            class="w-full text-sm"
            aria-labelledby="expenses-lines"
          >
            <thead>
              <tr class="border-b border-border text-left">
                <th
                  scope="col"
                  class="py-2 pr-3"
                >
                  Description
                </th>
                <th
                  scope="col"
                  class="py-2 pr-3"
                >
                  Date
                </th>
                <th
                  scope="col"
                  class="py-2 pr-3 text-right"
                >
                  Amount
                </th>
                <th
                  scope="col"
                  class="py-2"
                >
                  <span class="sr-only">Actions</span>
                </th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="ledger.data.length === 0">
                <td
                  colspan="4"
                  class="py-2 text-muted"
                >
                  No expenses or income yet.
                </td>
              </tr>
              <tr
                v-for="item in ledger.data"
                :key="item.id"
                class="border-b border-border"
              >
                <td class="py-2 pr-3">
                  {{ item.description }}
                </td>
                <td class="py-2 pr-3 whitespace-nowrap">
                  {{ formatAdminDate(item.created_at) }}
                </td>
                <td
                  class="py-2 pr-3 text-right tabular-nums"
                  :class="item.type === 'expense' ? 'text-danger' : undefined"
                >
                  {{ item.type === 'expense' ? `(${formatAmount(item.amount)})` : formatAmount(item.amount) }}
                  <span class="sr-only">{{ item.type }}</span>
                </td>
                <td class="py-2 text-right">
                  <div class="inline-flex gap-1">
                    <BaseButton
                      size="sm"
                      :aria-label="`Edit ${item.description || `item ${item.id}`}`"
                      @click="openEdit(item)"
                    >
                      Edit
                    </BaseButton>
                    <BaseButton
                      size="sm"
                      variant="danger"
                      :loading="busyId === item.id"
                      :aria-label="`Delete ${item.description || `item ${item.id}`}`"
                      @click="remove(item)"
                    >
                      Delete
                    </BaseButton>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <dl class="mt-2 grid max-w-xl grid-cols-[1fr_auto] gap-x-4 gap-y-1">
          <dt>Total Expenses</dt>
          <dd
            class="text-right tabular-nums"
            :class="isPositive(ledger.totals.total_expenses) ? 'text-danger' : undefined"
          >
            {{ totalText(ledger.totals.total_expenses) }}
          </dd>
          <dt>Gross (Registration fees - Expenses)</dt>
          <dd
            class="text-right tabular-nums"
            :class="isNegative(ledger.totals.gross) ? 'text-danger' : undefined"
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
