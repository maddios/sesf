<script setup lang="ts">
/**
 * Shirts of one event: which shirt types members can order and, per type, an optional price for this event (empty =
 * the type's default price). An event that was never configured offers every type at its default. Prices apply to
 * new orders only; existing orders keep the price they were placed at.
 */
import { computed, ref, watch } from 'vue';
import BaseButton from '../../../components/ui/BaseButton.vue';
import BaseModal from '../../../components/ui/BaseModal.vue';
import MoneyInput from '../../../components/admin/MoneyInput.vue';
import { mapAdminError } from '../../../composables/admin/useAdminResource';
import { useToast } from '../../../composables/admin/useToast';
import { eventShirtsApi, type Event, type EventShirtType } from './events';

const props = defineProps<{ open: boolean; event: Event | null }>();
const emit = defineEmits<{ 'update:open': [open: boolean] }>();

/** shirt_type.price is float(6,2). */
const PRICE_MAX = '9999.99';
const EMPTY_SELECTION = 'Select at least one shirt type. To offer every type, use "Select all".';

interface Row {
  type: EventShirtType;
  selected: boolean;
  price: string | null;
  error: string | null;
}

const toast = useToast();
const rows = ref<Row[]>([]);
const configured = ref(false);
const loading = ref(false);
const saving = ref(false);
const loadError = ref('');
const formError = ref('');

const selectedCount = computed(() => rows.value.filter((row) => row.selected).length);
/** Types being removed that already have orders: they stay on those invoices at their price. */
const removedWithOrders = computed(() => rows.value.filter((row) => !row.selected && row.type.offered && row.type.orders > 0));

function apply(types: EventShirtType[], isConfigured: boolean): void {
  configured.value = isConfigured;
  rows.value = types.map((type) => ({ type, selected: type.offered, price: type.price, error: null }));
}

async function load(): Promise<void> {
  if (!props.open || props.event === null) return;
  loading.value = true;
  loadError.value = '';
  formError.value = '';
  rows.value = [];
  try {
    const result = await eventShirtsApi(props.event.id).get();
    apply(result.data.types, result.data.configured);
  } catch (error) {
    loadError.value = mapAdminError(error, 'The shirts could not be loaded.').message;
  } finally {
    loading.value = false;
  }
}

function selectAll(selected: boolean): void {
  for (const row of rows.value) row.selected = selected;
  formError.value = '';
}

function priceId(row: Row): string {
  return `event-shirt-price-${row.type.id}`;
}

function ordersLabel(count: number): string {
  return count === 1 ? '1 order' : `${count} orders`;
}

async function save(): Promise<void> {
  if (props.event === null || saving.value) return;
  const chosen = rows.value.filter((row) => row.selected);
  formError.value = '';
  if (chosen.length === 0) {
    formError.value = EMPTY_SELECTION;
    return;
  }
  if (chosen.some((row) => row.error !== null)) {
    formError.value = 'Fix the highlighted prices first.';
    return;
  }
  saving.value = true;
  try {
    const result = await eventShirtsApi(props.event.id).save(chosen.map((row) => ({ id: row.type.id, price: row.price })));
    apply(result.data.types, result.data.configured);
    const kept = result.removed_with_orders.reduce((sum, item) => sum + item.orders, 0);
    toast.success(kept > 0
      ? `Shirts saved. ${ordersLabel(kept)} of removed types keep their price.`
      : 'Shirts saved.');
    emit('update:open', false);
  } catch (error) {
    const info = mapAdminError(error, 'The shirts could not be saved.');
    let mapped = false;
    for (const [field, messages] of Object.entries(info.fieldErrors)) {
      const match = /^types\.(\d+)\.(?:price|id)$/.exec(field);
      const row = match === null ? undefined : chosen[Number(match[1])];
      if (row !== undefined) {
        row.error = messages[0] ?? null;
        mapped = true;
      }
    }
    formError.value = info.fieldErrors.types?.[0] ?? (mapped ? 'Fix the highlighted prices first.' : info.message);
  } finally {
    saving.value = false;
  }
}

watch(() => [props.open, props.event?.id], () => void load(), { immediate: true });
</script>

<template>
  <BaseModal
    sheet
    :open="open"
    :title="event ? `Shirts for ${event.name}` : 'Shirts'"
    size="lg"
    @update:open="emit('update:open', $event)"
  >
    <div class="space-y-4">
      <p class="text-sm">
        Choose the shirts members can order for this event. Leave a price empty to use the default price.
        <strong class="font-semibold">Prices apply to new orders only.</strong>
        Existing orders and ended events keep the price they were ordered at.
      </p>
      <p
        v-if="loadError"
        role="alert"
        class="text-danger"
      >
        {{ loadError }}
      </p>
      <p
        v-if="loading"
        class="text-muted"
      >
        Loading shirts…
      </p>

      <template v-if="rows.length > 0">
        <p
          v-if="!configured"
          class="text-sm text-muted"
        >
          This event has no selection yet, so it offers every shirt type at its default price.
        </p>
        <div class="flex flex-wrap items-center gap-2">
          <BaseButton @click="selectAll(true)">
            Select all
          </BaseButton>
          <BaseButton @click="selectAll(false)">
            Select none
          </BaseButton>
          <span
            class="text-sm text-muted"
            aria-live="polite"
          >{{ selectedCount }} of {{ rows.length }} selected</span>
        </div>

        <ul
          class="divide-y divide-border rounded-sm border border-border"
          aria-label="Shirt types"
        >
          <li
            v-for="row in rows"
            :key="row.type.id"
            class="grid grid-cols-1 items-center gap-x-3 gap-y-1 px-3 py-2 @md:grid-cols-[minmax(0,1fr)_11rem]"
          >
            <div class="min-w-0">
              <label class="flex min-h-11 min-w-0 cursor-pointer items-center gap-3">
                <input
                  v-model="row.selected"
                  type="checkbox"
                  class="size-5 shrink-0"
                  :aria-describedby="row.type.orders > 0 ? `event-shirt-orders-${row.type.id}` : undefined"
                  @change="formError = ''"
                >
                <span class="min-w-0 font-medium text-heading">{{ row.type.name }}</span>
              </label>
              <p
                v-if="row.type.orders > 0"
                :id="`event-shirt-orders-${row.type.id}`"
                class="-mt-2 mb-1 pl-8 text-xs text-muted"
              >
                {{ ordersLabel(row.type.orders) }} at this event
              </p>
            </div>
            <div class="min-w-0">
              <label
                :for="priceId(row)"
                class="sr-only"
              >Price for {{ row.type.name }}</label>
              <MoneyInput
                :id="priceId(row)"
                v-model="row.price"
                class="min-h-11"
                :max="PRICE_MAX"
                :disabled="!row.selected"
                :placeholder="`Default $${row.type.default_price}`"
                :aria-describedby="row.error ? `${priceId(row)}-error` : undefined"
                @invalid="row.error = $event"
              />
              <p
                v-if="row.error"
                :id="`${priceId(row)}-error`"
                class="mt-1 text-xs text-danger"
              >
                {{ row.error }}
              </p>
            </div>
          </li>
        </ul>

        <p
          v-if="removedWithOrders.length > 0"
          role="status"
          class="rounded-sm border border-warning-border bg-warning-bg px-3 py-2 text-sm text-warning-fg"
        >
          {{ removedWithOrders.map((row) => `${row.type.name} (${ordersLabel(row.type.orders)})`).join(', ') }}
          will no longer be offered. Those orders stay on their invoices at the price they were ordered at.
        </p>
        <p
          v-if="formError"
          role="alert"
          class="text-sm text-danger"
        >
          {{ formError }}
        </p>
      </template>
    </div>

    <template #footer>
      <BaseButton @click="emit('update:open', false)">
        Cancel
      </BaseButton>
      <BaseButton
        variant="primary"
        :loading="saving"
        loading-label="Saving shirts"
        :disabled="loading || rows.length === 0"
        @click="save"
      >
        Save shirts
      </BaseButton>
    </template>
  </BaseModal>
</template>
