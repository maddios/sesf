<script setup lang="ts">
/**
 * "Set shirt inventory" (legacy inventory modal): on-hand count per shirt type with the ordered total for reference.
 * Steppers and a number field per row; Save sends only the changed counts.
 */
import { computed, ref, watch } from 'vue';
import BaseModal from '../../../../components/ui/BaseModal.vue';
import { formatCents, type ShirtTypeRow } from '../tshirts';

const props = defineProps<{ open: boolean; types: ShirtTypeRow[]; saving: boolean }>();
const emit = defineEmits<{ save: [stock: Record<number, number>]; close: [] }>();

const draft = ref<Record<number, number>>({});

watch(
  () => props.open,
  (open) => {
    if (open) draft.value = Object.fromEntries(props.types.map((type) => [type.id, type.stock]));
  },
  { immediate: true },
);

const changed = computed(() => props.types.filter((type) => (draft.value[type.id] ?? 0) !== type.stock));

function step(typeId: number, delta: number): void {
  draft.value = { ...draft.value, [typeId]: Math.max(0, (draft.value[typeId] ?? 0) + delta) };
}

function save(): void {
  emit('save', Object.fromEntries(changed.value.map((type) => [type.id, draft.value[type.id] ?? 0])));
}
</script>

<template>
  <BaseModal
    :open="open"
    title="Shirt inventory for this event"
    sheet
    @close="emit('close')"
  >
    <div class="a2-page">
      <p class="a2-muted a2-small mb-3">
        Enter how many of each size/type you have on hand. Tallies are stock minus handed out; ordered totals are shown
        for reference and shortfall warnings.
      </p>
      <table
        class="w-full"
        aria-label="Stock by shirt type"
      >
        <tbody>
          <tr
            v-for="type in types"
            :key="type.id"
            class="border-b border-[#eee]"
          >
            <th
              scope="row"
              class="py-2 pr-2 text-left font-normal"
            >
              <span class="font-semibold text-[#333]">{{ type.name }}</span>
              <span class="block text-xs text-[#6b7280]">
                {{ type.ordered }} ordered<template v-if="type.price_cents !== null"> · {{ formatCents(type.price_cents) }}</template>
              </span>
            </th>
            <td class="py-2">
              <div class="flex items-center justify-end gap-1">
                <button
                  type="button"
                  class="a2-btn min-w-10"
                  :aria-label="`Fewer ${type.name} on hand`"
                  :disabled="(draft[type.id] ?? 0) === 0"
                  @click="step(type.id, -1)"
                >
                  −
                </button>
                <input
                  v-model.number="draft[type.id]"
                  type="number"
                  inputmode="numeric"
                  min="0"
                  max="65535"
                  :aria-label="`${type.name} on hand`"
                  class="a2-input w-16 text-right"
                >
                <button
                  type="button"
                  class="a2-btn min-w-10"
                  :aria-label="`More ${type.name} on hand`"
                  @click="step(type.id, 1)"
                >
                  +
                </button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
    <template #footer>
      <button
        type="button"
        class="a2-btn"
        @click="emit('close')"
      >
        Cancel
      </button>
      <button
        type="button"
        class="a2-btn a2-btn-info"
        :disabled="saving || changed.length === 0"
        @click="save"
      >
        {{ saving ? 'Saving…' : 'Save inventory' }}
      </button>
    </template>
  </BaseModal>
</template>
