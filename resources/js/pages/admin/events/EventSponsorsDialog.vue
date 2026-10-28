<script setup lang="ts">
/**
 * Sponsors of one event: add a sponsor (picker), reorder them (ReorderableList; the order is per event), set or
 * clear the primary sponsor, and remove a sponsor. Every change is saved at once and reported as a toast.
 */
import { ref, watch } from 'vue';
import BaseButton from '../../../components/ui/BaseButton.vue';
import BaseCombobox from '../../../components/ui/BaseCombobox.vue';
import BaseModal from '../../../components/ui/BaseModal.vue';
import RelationSelect from '../../../components/admin/RelationSelect.vue';
import ReorderableList from '../../../components/admin/ReorderableList.vue';
import StatusBadge from '../../../components/admin/StatusBadge.vue';
import { ADMIN_API_BASE, mapAdminError } from '../../../composables/admin/useAdminResource';
import { useToast } from '../../../composables/admin/useToast';
import { SPONSOR_TIERS, eventSponsorsApi, tierOrNull, type Event, type EventSponsor } from './events';

const props = defineProps<{ open: boolean; event: Event | null }>();
const emit = defineEmits<{ 'update:open': [open: boolean] }>();

const toast = useToast();
const rows = ref<EventSponsor[]>([]);
const primaryId = ref<number | null>(null);
const loading = ref(false);
const loadError = ref('');
const pickId = ref<number | null>(null);
const pickTier = ref('');
const adding = ref(false);

function api(): ReturnType<typeof eventSponsorsApi> {
  return eventSponsorsApi((props.event as Event).id);
}

function apply(list: { data: EventSponsor[]; primary_sponsor_id: number | null }): void {
  rows.value = list.data;
  primaryId.value = list.primary_sponsor_id;
}

async function load(): Promise<void> {
  if (!props.open || props.event === null) return;
  loading.value = true;
  loadError.value = '';
  try {
    apply(await api().list());
  } catch (error) {
    loadError.value = mapAdminError(error, 'The sponsors could not be loaded.').message;
  } finally {
    loading.value = false;
  }
}

async function run(action: () => Promise<void>, failure: string): Promise<void> {
  try {
    await action();
  } catch (error) {
    toast.error(mapAdminError(error, failure).message);
    await load();
  }
}

function onReorder(ids: Array<EventSponsor['id']>): void {
  void run(async () => {
    apply(await api().reorder(ids.map(Number)));
    toast.success('Sponsor order saved.');
  }, 'The order could not be saved.');
}

function add(): void {
  if (pickId.value === null) return;
  const sponsorId = pickId.value;
  adding.value = true;
  void run(async () => {
    const created = await api().add(sponsorId, tierOrNull(pickTier.value));
    pickId.value = null;
    pickTier.value = '';
    await load();
    toast.success(`Sponsor “${created.data.name ?? 'sponsor'}” added.`);
  }, 'The sponsor could not be added.').finally(() => {
    adding.value = false;
  });
}

function setTier(row: EventSponsor, value: string): void {
  void run(async () => {
    await api().setType(row.id, tierOrNull(value));
    await load();
    toast.success(`Tier for “${row.name ?? 'sponsor'}” saved.`);
  }, 'The tier could not be saved.');
}

function remove(row: EventSponsor): void {
  void run(async () => {
    await api().remove(row.id);
    await load();
    toast.success(`Sponsor “${row.name ?? 'sponsor'}” removed.`);
  }, 'The sponsor could not be removed.');
}

function togglePrimary(row: EventSponsor): void {
  const next = primaryId.value === row.sponsor_id ? null : row.sponsor_id;
  void run(async () => {
    const result = await api().setPrimary(next);
    primaryId.value = result.data.primary_sponsor_id;
    toast.success(next === null ? 'Primary sponsor cleared.' : `“${row.name ?? 'Sponsor'}” is now the primary sponsor.`);
  }, 'The primary sponsor could not be set.');
}

watch(() => [props.open, props.event?.id], () => void load(), { immediate: true });
</script>

<template>
  <BaseModal
    sheet
    :open="open"
    :title="event ? `Sponsors for ${event.name}` : 'Sponsors'"
    size="lg"
    @update:open="emit('update:open', $event)"
  >
    <div class="space-y-4">
      <p
        v-if="loadError"
        role="alert"
        class="text-danger"
      >
        {{ loadError }}
      </p>

      <div>
        <label
          for="event-sponsor-pick"
          class="block text-sm font-medium text-text"
        >Add a sponsor</label>
        <div class="mt-1 flex flex-wrap gap-2">
          <div class="min-w-0 flex-1">
            <RelationSelect
              id="event-sponsor-pick"
              :endpoint="event ? `${ADMIN_API_BASE}/events/${event.id}/sponsor-candidates` : ''"
              :model-value="pickId"
              placeholder="Search sponsors"
              @update:model-value="pickId = $event"
            />
          </div>
          <div class="w-full @md:w-40">
            <BaseCombobox
              id="event-sponsor-pick-tier"
              :model-value="pickTier"
              :options="SPONSOR_TIERS"
              aria-label="Tier for the new sponsor"
              @update:model-value="pickTier = String($event ?? '')"
            />
          </div>
          <BaseButton
            :disabled="pickId === null"
            :loading="adding"
            @click="add"
          >
            Add sponsor
          </BaseButton>
        </div>
      </div>

      <p
        v-if="!loading && rows.length === 0 && !loadError"
        class="text-muted"
      >
        No sponsors are attached to this event yet.
      </p>

      <ReorderableList
        v-if="rows.length > 0"
        :items="rows"
        label="Sponsor order"
        :item-label="(row: EventSponsor) => row.name ?? 'Sponsor'"
        :disabled="loading"
        @reorder="onReorder"
      >
        <template #default="{ item }">
          <div class="flex flex-wrap items-center gap-2">
            <span class="font-medium text-heading">{{ item.name }}</span>
            <StatusBadge
              v-if="item.sponsor_id === primaryId"
              label="Primary"
              variant="success"
            />
            <StatusBadge
              v-if="!item.is_enabled"
              label="Disabled"
              variant="neutral"
            />
            <div class="w-full @md:w-40">
              <BaseCombobox
                :model-value="String(item.type_id ?? '')"
                :options="SPONSOR_TIERS"
                :aria-label="`Tier for ${item.name}`"
                @update:model-value="setTier(item, String($event ?? ''))"
              />
            </div>
            <div class="ms-auto flex gap-1">
              <BaseButton
                size="sm"
                :aria-pressed="item.sponsor_id === primaryId ? 'true' : 'false'"
                :aria-label="`${item.sponsor_id === primaryId ? 'Clear primary' : 'Make primary'}: ${item.name}`"
                @click="togglePrimary(item)"
              >
                {{ item.sponsor_id === primaryId ? 'Clear primary' : 'Make primary' }}
              </BaseButton>
              <BaseButton
                size="sm"
                variant="danger"
                :aria-label="`Remove ${item.name}`"
                @click="remove(item)"
              >
                Remove
              </BaseButton>
            </div>
          </div>
        </template>
      </ReorderableList>
    </div>

    <template #footer>
      <BaseButton @click="emit('update:open', false)">
        Done
      </BaseButton>
    </template>
  </BaseModal>
</template>
