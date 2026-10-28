<script setup lang="ts">
/**
 * Cars admin (see docs/admin-conventions.md). Create or edit a car: owner, type, body, year, model, nickname, VIN.
 * Filter by confirmation, event, type, body and owner. Confirm or unconfirm a car, upload its photo, or delete it.
 * A car entered in a show cannot be deleted (409): the page says so.
 */
import { onMounted, ref } from 'vue';
import { api } from '../../../api/client';
import AdminDataTable from '../../../components/admin/AdminDataTable.vue';
import AdminFormField from '../../../components/admin/AdminFormField.vue';
import AdminFormModal from '../../../components/admin/AdminFormModal.vue';
import AdminIconButton from '../../../components/admin/AdminIconButton.vue';
import AdminPageHeader from '../../../components/admin/AdminPageHeader.vue';
import ImageUploadModal from '../../../components/admin/ImageUploadModal.vue';
import RelationSelect from '../../../components/admin/RelationSelect.vue';
import StatusBadge from '../../../components/admin/StatusBadge.vue';
import BaseButton from '../../../components/ui/BaseButton.vue';
import BaseCombobox from '../../../components/ui/BaseCombobox.vue';
import SafeImage from '../../../components/ui/SafeImage.vue';
import { useAdminList } from '../../../composables/admin/useAdminList';
import { ADMIN_API_BASE, mapAdminError } from '../../../composables/admin/useAdminResource';
import { useConfirm } from '../../../composables/admin/useConfirm';
import { useToast } from '../../../composables/admin/useToast';
import type { FormValues } from '../../../components/admin/types';
import { CAR_COLUMNS, carFields, carsApi, imageSrc, profileHref, toForm, toInput, type Car, type CarOptions } from './cars';

const list = useAdminList<Car>({
  fetch: (query) => carsApi.list(query),
  defaults: { sort: 'id', direction: 'asc' },
  filterKeys: ['is_confirmed', 'show_id', 'type_id', 'body_id', 'user_id'],
});
const toast = useToast();
const { confirm } = useConfirm();

const CONFIRMED_OPTIONS = [
  { value: '', label: 'All' },
  { value: '1', label: 'Confirmed' },
  { value: '0', label: 'Unconfirmed' },
];

const options = ref<CarOptions>({ types: [], bodies: [] });
const optionsError = ref<string | null>(null);
const editing = ref<Car | null>(null);
const formOpen = ref(false);
const imageFor = ref<Car | null>(null);
const imageOpen = ref(false);
const busyId = ref<number | null>(null);

onMounted(async () => {
  try {
    options.value = (await api.get<{ data: CarOptions }>(`${ADMIN_API_BASE}/cars/options`)).data;
  } catch (error) {
    optionsError.value = mapAdminError(error, 'The type and body choices could not be loaded.').message;
  }
});

function openCreate(): void {
  editing.value = null;
  formOpen.value = true;
}

function openEdit(car: Car): void {
  editing.value = car;
  formOpen.value = true;
}

function save(values: FormValues): Promise<Car> {
  const input = toInput(values);
  return editing.value === null ? carsApi.create(input) : carsApi.update(editing.value.id, input);
}

function onSaved(result: unknown): void {
  toast.success(`Car ${(result as Car).id} ${editing.value === null ? 'created' : 'saved'}.`);
  void list.reload();
}

async function setConfirmed(car: Car, confirmed: boolean): Promise<void> {
  busyId.value = car.id;
  try {
    await carsApi.action(car.id, confirmed ? 'confirm' : 'unconfirm');
    toast.success(`Car ${confirmed ? 'confirmed' : 'marked unconfirmed'}.`);
    await list.reload();
  } catch (error) {
    toast.error(mapAdminError(error).message);
  } finally {
    busyId.value = null;
  }
}

function openImage(car: Car): void {
  imageFor.value = car;
  imageOpen.value = true;
}

function uploadImage(file: File, onProgress: (percent: number) => void): Promise<Car> {
  return carsApi.upload((imageFor.value as Car).id, file, { onProgress });
}

function onUploaded(): void {
  toast.success('Car photo uploaded.');
  void list.reload();
}

async function remove(car: Car): Promise<void> {
  const ok = await confirm({
    title: 'Delete car',
    message: 'Delete this car permanently? This cannot be undone.',
    confirmLabel: 'Delete',
    danger: true,
    typedConfirmation: 'DELETE',
  });
  if (!ok) return;
  busyId.value = car.id;
  try {
    await carsApi.remove(car.id);
    toast.success('Car deleted.');
    await list.reload();
  } catch (error) {
    toast.error(mapAdminError(error, 'The car could not be deleted.').message);
  } finally {
    busyId.value = null;
  }
}

function setFilter(key: 'is_confirmed' | 'show_id' | 'type_id' | 'body_id' | 'user_id', value: string | number | null): void {
  void list.setFilter(key, value === null || value === '' ? null : String(value));
}

function idFilterValue(key: 'show_id' | 'user_id'): number | null {
  const raw = list.state.value.filters[key];
  return raw !== undefined && /^\d+$/.test(raw) ? Number(raw) : null;
}
</script>

<template>
  <div>
    <AdminPageHeader title="Cars">
      <template #actions>
        <BaseButton
          variant="primary"
          @click="openCreate"
        >
          New car
        </BaseButton>
      </template>
    </AdminPageHeader>
    <p
      v-if="optionsError"
      class="text-sm text-danger"
    >
      {{ optionsError }}
    </p>

    <AdminDataTable
      label="Cars"
      search-label="Search cars"
      :columns="CAR_COLUMNS"
      :rows="list.rows.value"
      :meta="list.meta.value"
      :state="list.state.value"
      :loading="list.loading.value"
      :error="list.error.value"
      empty-message="No cars match."
      :active-filters="list.activeFilters.value"
      @row-click="openEdit"
      @sort="list.setSort"
      @page="list.setPage"
      @search="list.setSearch"
      @per-page="list.setPerPage"
      @retry="list.reload"
    >
      <template #filters>
        <div class="w-40">
          <label for="car-filter-confirmed">Confirmation</label>
          <BaseCombobox
            id="car-filter-confirmed"
            :model-value="list.state.value.filters.is_confirmed ?? ''"
            :options="CONFIRMED_OPTIONS"
            @update:model-value="(value) => setFilter('is_confirmed', value)"
          />
        </div>
        <div class="w-64">
          <label for="car-filter-show">Event</label>
          <RelationSelect
            id="car-filter-show"
            :endpoint="`${ADMIN_API_BASE}/lookups/events`"
            :model-value="idFilterValue('show_id')"
            placeholder="Any event"
            @update:model-value="(value) => setFilter('show_id', value)"
          />
        </div>
        <div class="w-40">
          <label for="car-filter-type">Type</label>
          <BaseCombobox
            id="car-filter-type"
            :model-value="list.state.value.filters.type_id ?? ''"
            :options="[{ value: '', label: 'Any' }, ...options.types.map((option) => ({ value: String(option.id), label: option.label }))]"
            @update:model-value="(value) => setFilter('type_id', value)"
          />
        </div>
        <div class="w-40">
          <label for="car-filter-body">Body</label>
          <BaseCombobox
            id="car-filter-body"
            :model-value="list.state.value.filters.body_id ?? ''"
            :options="[{ value: '', label: 'Any' }, ...options.bodies.map((option) => ({ value: String(option.id), label: option.label }))]"
            @update:model-value="(value) => setFilter('body_id', value)"
          />
        </div>
        <div class="w-64">
          <label for="car-filter-owner">Owner</label>
          <RelationSelect
            id="car-filter-owner"
            :endpoint="`${ADMIN_API_BASE}/users/lookup`"
            :model-value="idFilterValue('user_id')"
            placeholder="Any owner"
            @update:model-value="(value) => setFilter('user_id', value)"
          />
        </div>
      </template>

      <template #cell-profile="{ row }">
        <a
          :href="profileHref(row)"
          target="_blank"
          rel="noopener"
          class="text-link underline"
        >Car {{ row.id }}<span class="sr-only"> profile (opens in a new tab)</span></a>
      </template>

      <template #cell-image="{ row }">
        <SafeImage
          :src="imageSrc(row)"
          :alt="`Photo of ${row.model ?? 'car'}`"
          :placeholder="row.has_image ? 'Photo' : 'No photo'"
          img-class="h-10 w-10 rounded-sm border border-border object-cover bg-subtle"
          width="40"
          height="40"
        />
      </template>
      <template #cell-is_confirmed="{ row }">
        <StatusBadge
          :label="row.is_confirmed ? 'Confirmed' : 'Unconfirmed'"
          :variant="row.is_confirmed ? 'success' : 'warning'"
        />
      </template>

      <template #actions="{ row }">
        <AdminIconButton
          icon="edit"
          :label="`Edit car ${row.id}`"
          @click="openEdit(row)"
        />
        <AdminIconButton
          :icon="row.is_confirmed ? 'x' : 'check'"
          :label="`${row.is_confirmed ? 'Unconfirm' : 'Confirm'} car ${row.id}`"
          :disabled="busyId === row.id"
          @click="setConfirmed(row, !row.is_confirmed)"
        />
        <AdminIconButton
          icon="image"
          :label="`Photo for car ${row.id}`"
          @click="openImage(row)"
        />
        <AdminIconButton
          icon="trash"
          variant="danger"
          :label="`Delete car ${row.id}`"
          :disabled="row.in_use"
          @click="remove(row)"
        />
      </template>
    </AdminDataTable>

    <AdminFormModal
      v-model:open="formOpen"
      :title="editing === null ? 'New car' : `Edit car ${editing.id}`"
      :fields="carFields(options)"
      :initial="toForm(editing)"
      :submit="save"
      :submit-label="editing === null ? 'Create car' : 'Save changes'"
      @saved="onSaved"
    >
      <template #field-user_id="{ id, value, setValue, field }">
        <AdminFormField
          :id="id"
          v-slot="{ inputProps }"
          :label="field.label"
          :hint="field.hint"
          :wide="field.wide"
        >
          <RelationSelect
            v-bind="inputProps"
            :model-value="typeof value === 'number' ? value : null"
            :endpoint="`${ADMIN_API_BASE}/users/lookup`"
            placeholder="Search members"
            @update:model-value="(id: number | null) => setValue(id)"
          />
        </AdminFormField>
      </template>
    </AdminFormModal>

    <ImageUploadModal
      v-model:open="imageOpen"
      :title="`Photo for car ${imageFor?.id ?? ''}`"
      :current-src="imageFor ? imageSrc(imageFor) : null"
      :current-alt="`Photo of car ${imageFor?.id ?? ''}`"
      :upload="uploadImage"
      @uploaded="onUploaded"
    />
  </div>
</template>
