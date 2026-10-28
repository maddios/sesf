<script setup lang="ts">
/**
 * Cars admin (see docs/admin-conventions.md). Create or edit a car: owner, type, body, year, model, nickname, VIN.
 * Filter by confirmation, event, type, body and owner. Confirm or unconfirm a car, upload its photo, or delete it.
 * A car entered in a show cannot be deleted (409): the page says so.
 */
import { onMounted, ref } from 'vue';
import { api } from '../../../api/client';
import AdminDataTable from '../../../components/admin/AdminDataTable.vue';
import AdminFormModal from '../../../components/admin/AdminFormModal.vue';
import ImageUploadModal from '../../../components/admin/ImageUploadModal.vue';
import RelationSelect from '../../../components/admin/RelationSelect.vue';
import StatusBadge from '../../../components/admin/StatusBadge.vue';
import BaseButton from '../../../components/ui/BaseButton.vue';
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

function onIdFilter(key: 'show_id' | 'user_id', value: number | null): void {
  void list.setFilter(key, value === null ? null : String(value));
}

function idFilterValue(key: 'show_id' | 'user_id'): number | null {
  const raw = list.state.value.filters[key];
  return raw !== undefined && /^\d+$/.test(raw) ? Number(raw) : null;
}
</script>

<template>
  <div class="space-y-4">
    <h1 class="page-title">
      Cars
    </h1>
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
      @sort="list.setSort"
      @page="list.setPage"
      @search="list.setSearch"
      @per-page="list.setPerPage"
      @retry="list.reload"
    >
      <template #filters>
        <div>
          <label
            for="car-filter-confirmed"
            class="block text-sm font-medium text-text"
          >Confirmation</label>
          <select
            id="car-filter-confirmed"
            class="form-control mt-1"
            :value="list.state.value.filters.is_confirmed ?? ''"
            @change="list.setFilter('is_confirmed', ($event.target as HTMLSelectElement).value)"
          >
            <option value="">
              All
            </option>
            <option value="1">
              Confirmed
            </option>
            <option value="0">
              Unconfirmed
            </option>
          </select>
        </div>
        <div class="sm:w-64">
          <label
            for="car-filter-show"
            class="block text-sm font-medium text-text"
          >Event</label>
          <div class="mt-1">
            <RelationSelect
              id="car-filter-show"
              :endpoint="`${ADMIN_API_BASE}/lookups/events`"
              :model-value="idFilterValue('show_id')"
              placeholder="Any event"
              @update:model-value="(value: number | null) => onIdFilter('show_id', value)"
            />
          </div>
        </div>
        <div>
          <label
            for="car-filter-type"
            class="block text-sm font-medium text-text"
          >Type</label>
          <select
            id="car-filter-type"
            class="form-control mt-1"
            :value="list.state.value.filters.type_id ?? ''"
            @change="list.setFilter('type_id', ($event.target as HTMLSelectElement).value)"
          >
            <option value="">
              Any
            </option>
            <option
              v-for="option in options.types"
              :key="option.id"
              :value="String(option.id)"
            >
              {{ option.label }}
            </option>
          </select>
        </div>
        <div>
          <label
            for="car-filter-body"
            class="block text-sm font-medium text-text"
          >Body</label>
          <select
            id="car-filter-body"
            class="form-control mt-1"
            :value="list.state.value.filters.body_id ?? ''"
            @change="list.setFilter('body_id', ($event.target as HTMLSelectElement).value)"
          >
            <option value="">
              Any
            </option>
            <option
              v-for="option in options.bodies"
              :key="option.id"
              :value="String(option.id)"
            >
              {{ option.label }}
            </option>
          </select>
        </div>
        <div class="sm:w-64">
          <label
            for="car-filter-owner"
            class="block text-sm font-medium text-text"
          >Owner</label>
          <div class="mt-1">
            <RelationSelect
              id="car-filter-owner"
              :endpoint="`${ADMIN_API_BASE}/users/lookup`"
              :model-value="idFilterValue('user_id')"
              placeholder="Any owner"
              @update:model-value="(value: number | null) => onIdFilter('user_id', value)"
            />
          </div>
        </div>
      </template>

      <template #toolbar-end>
        <BaseButton
          variant="primary"
          @click="openCreate"
        >
          Add car
        </BaseButton>
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
        <div class="inline-flex flex-wrap justify-end gap-1">
          <BaseButton
            size="sm"
            :aria-label="`Edit car ${row.id}`"
            @click="openEdit(row)"
          >
            Edit
          </BaseButton>
          <BaseButton
            size="sm"
            :loading="busyId === row.id"
            :aria-label="`${row.is_confirmed ? 'Unconfirm' : 'Confirm'} car ${row.id}`"
            @click="setConfirmed(row, !row.is_confirmed)"
          >
            {{ row.is_confirmed ? 'Unconfirm' : 'Confirm' }}
          </BaseButton>
          <BaseButton
            size="sm"
            :aria-label="`Photo for car ${row.id}`"
            @click="openImage(row)"
          >
            Photo
          </BaseButton>
          <BaseButton
            size="sm"
            variant="danger"
            :disabled="row.in_use"
            :title="row.in_use ? 'Entered in a show: it cannot be deleted.' : undefined"
            :aria-label="`Delete car ${row.id}`"
            @click="remove(row)"
          >
            Delete
          </BaseButton>
        </div>
      </template>
    </AdminDataTable>

    <AdminFormModal
      v-model:open="formOpen"
      :title="editing === null ? 'Add car' : `Edit car ${editing.id}`"
      :fields="carFields(options)"
      :initial="toForm(editing)"
      :submit="save"
      :submit-label="editing === null ? 'Create car' : 'Save changes'"
      @saved="onSaved"
    >
      <template #field-user_id="{ id, value, setValue }">
        <label
          :for="id"
          class="block text-sm font-medium text-text"
        >Owner</label>
        <div class="mt-1">
          <RelationSelect
            :id="id"
            :model-value="typeof value === 'number' ? value : null"
            :endpoint="`${ADMIN_API_BASE}/users/lookup`"
            placeholder="Search members"
            @update:model-value="(id: number | null) => setValue(id)"
          />
        </div>
        <p class="mt-1 text-sm text-muted">
          Leave empty for a sold car.
        </p>
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
