<script setup lang="ts">
/**
 * "Register for event": pick an event and the member's cars, then POST admin-api/users/{id}/registrations.
 * Without a `member` (the registrations page) the member is picked first. The cars are the member's own (loaded
 * from admin-api/cars filtered by owner). Errors map onto the fields. Once member and event are known, a link opens
 * the full on-behalf registration editor (/:event/e:id/register?user_id=, legacy admin_register_user).
 */
import { computed, ref, watch } from 'vue';
import AdminFormModal from '../../../components/admin/AdminFormModal.vue';
import RelationSelect, { type RelationOption } from '../../../components/admin/RelationSelect.vue';
import type { AdminField, FormValues } from '../../../components/admin/types';
import { ADMIN_API_BASE, mapAdminError, useAdminResource } from '../../../composables/admin/useAdminResource';
import { api, ApiError } from '../../../api/client';
import type { Car } from '../cars/cars';
import type { AdminUser } from './users';

const props = defineProps<{ open: boolean; member: AdminUser | null }>();
const emit = defineEmits<{ 'update:open': [open: boolean]; registered: [result: unknown] }>();

const carsApi = useAdminResource<Car, Record<string, unknown>>('cars');
const cars = ref<Car[]>([]);
const carsError = ref<string | null>(null);

const chosenMemberId = ref<number | null>(null);
/** lookups/events options carry url_name (the legacy URL form of the event name). */
const chosenEvent = ref<(RelationOption & { url_name: string }) | null>(null);
const memberId = computed(() => props.member?.id ?? chosenMemberId.value);

const FIELDS = computed<AdminField[]>(() => [
  ...(props.member === null ? [{ name: 'user_id', label: 'Member', required: true }] : []),
  { name: 'show_id', label: 'Event', required: true },
  { name: 'car_ids', label: 'Cars entered', hint: 'The member’s cars that go into this event.' },
]);

const initial = computed<FormValues>(() => ({ user_id: null, show_id: null, car_ids: [] }));

/** The full on-behalf editor for the chosen member and event (hash route). */
const editorHref = computed(() => {
  const event = chosenEvent.value;
  return event === null || memberId.value === null ? null : `#/${event.url_name}/e${event.id}/register?user_id=${memberId.value}`;
});

watch(
  () => props.open,
  (open) => {
    if (open) {
      chosenMemberId.value = null;
      chosenEvent.value = null;
    }
  },
);

watch(
  () => [props.open, memberId.value] as const,
  async ([open, id]) => {
    cars.value = [];
    if (!open || id === null) return;
    carsError.value = null;
    try {
      const list = await carsApi.list({ per_page: 100, sort: 'model', direction: 'asc', filter: { user_id: String(id) } });
      cars.value = list.data;
    } catch (error) {
      cars.value = [];
      carsError.value = mapAdminError(error, 'The member’s cars could not be loaded.').message;
    }
  },
  { immediate: true },
);

function submit(values: FormValues): Promise<unknown> {
  // Only cars of the current member: ticks made before the member was changed are dropped.
  const owned = new Set(cars.value.map((car) => car.id));
  const body = { show_id: Number(values.show_id), car_ids: ((values.car_ids as number[]) ?? []).filter((id) => owned.has(id)) };
  if (memberId.value === null) {
    return Promise.reject(new ApiError(422, { message: 'Choose a member.', errors: { user_id: ['Choose a member.'] } }));
  }
  return api.post(`${ADMIN_API_BASE}/users/${memberId.value}/registrations`, body);
}

function onSaved(result: unknown): void {
  emit('registered', result);
}

function toggleCar(current: number[], setValue: (value: unknown) => void, id: number, checked: boolean): void {
  setValue(checked ? [...current, id] : current.filter((item) => item !== id));
}
</script>

<template>
  <AdminFormModal
    :open="open"
    :title="member ? `Register ${member.full_name} for an event` : 'Register for an event'"
    :fields="FIELDS"
    :initial="initial"
    :submit="submit"
    submit-label="Register"
    size="md"
    @update:open="(value: boolean) => emit('update:open', value)"
    @saved="onSaved"
  >
    <template #field-user_id="{ id, value, setValue, errors }">
      <label
        :for="id"
        class="block text-sm font-medium text-text"
      >Member (required)</label>
      <RelationSelect
        :id="id"
        :model-value="typeof value === 'number' ? value : null"
        :endpoint="`${ADMIN_API_BASE}/users/lookup`"
        placeholder="Search members"
        @update:model-value="(picked: number | null) => { chosenMemberId = picked; setValue(picked); }"
      />
      <p
        v-for="message in errors"
        :key="message"
        class="mt-1 text-sm text-danger"
      >
        {{ message }}
      </p>
    </template>

    <template #field-show_id="{ id, value, setValue, errors }">
      <label
        :for="id"
        class="block text-sm font-medium text-text"
      >Event (required)</label>
      <RelationSelect
        :id="id"
        :model-value="typeof value === 'number' ? value : null"
        :endpoint="`${ADMIN_API_BASE}/lookups/events`"
        placeholder="Search events"
        @update:model-value="(id: number | null) => setValue(id)"
        @change="(option: RelationOption | null) => (chosenEvent = option as (RelationOption & { url_name: string }) | null)"
      />
      <a
        v-if="editorHref !== null"
        class="mt-1 inline-block text-sm underline"
        :href="editorHref"
      >Open the full registration editor (guests, shirts, extras)</a>
      <p
        v-for="message in errors"
        :key="message"
        class="mt-1 text-sm text-danger"
      >
        {{ message }}
      </p>
    </template>

    <template #field-car_ids="{ value, setValue, errors }">
      <fieldset class="space-y-2">
        <legend class="block text-sm font-medium text-text">
          Cars entered
        </legend>
        <p
          v-if="carsError"
          class="text-sm text-danger"
        >
          {{ carsError }}
        </p>
        <p
          v-else-if="cars.length === 0"
          class="text-sm text-muted"
        >
          This member has no cars yet.
        </p>
        <label
          v-for="car in cars"
          :key="car.id"
          class="flex items-center gap-2 text-sm"
        >
          <input
            type="checkbox"
            :checked="(value as number[]).includes(car.id)"
            @change="toggleCar(value as number[], setValue, car.id, ($event.target as HTMLInputElement).checked)"
          >
          <span>{{ car.year }} {{ car.model }}{{ car.nickname ? ` “${car.nickname}”` : '' }}</span>
        </label>
        <p
          v-for="message in errors"
          :key="message"
          class="text-sm text-danger"
        >
          {{ message }}
        </p>
      </fieldset>
    </template>
  </AdminFormModal>
</template>
