<script setup lang="ts">
/** Judges assigned to one event (admin only). Only judge accounts that are not yet assigned can be added. */
import { computed, ref, watch } from 'vue';
import BaseCombobox from '../../../components/ui/BaseCombobox.vue';
import { mapAdminError } from '../../../composables/admin/useAdminResource';
import { useConfirm } from '../../../composables/admin/useConfirm';
import { useToast } from '../../../composables/admin/useToast';
import { assignJudge, fetchEligibleJudges, fetchJudges, unassignJudge, type JudgingUser } from './judging';

const props = defineProps<{
  showId: number;
}>();

const toast = useToast();
const { confirm } = useConfirm();
const judges = ref<JudgingUser[]>([]);
const eligible = ref<JudgingUser[]>([]);
const selectedUserId = ref('');
const judgeOptions = computed(() => eligible.value.map((judge) => ({ value: String(judge.user_id), label: judge.name, description: judge.email })));
const error = ref('');
const addError = ref('');

async function load(): Promise<void> {
  try {
    const [assigned, available] = await Promise.all([fetchJudges(props.showId), fetchEligibleJudges(props.showId)]);
    judges.value = assigned;
    eligible.value = available;
    error.value = '';
  } catch (caught) {
    error.value = mapAdminError(caught, 'The event judges could not be loaded.').message;
  }
}

watch(
  () => props.showId,
  () => {
    selectedUserId.value = '';
    addError.value = '';
    void load();
  },
  { immediate: true },
);

async function add(): Promise<void> {
  if (selectedUserId.value === '') return;
  addError.value = '';
  try {
    await assignJudge(props.showId, Number(selectedUserId.value));
    toast.success('Judge added to this event.');
    selectedUserId.value = '';
    await load();
  } catch (caught) {
    addError.value = mapAdminError(caught, 'The judge could not be added.').message;
  }
}

async function remove(judge: JudgingUser): Promise<void> {
  const ok = await confirm({
    title: 'Remove judge',
    message: `Remove ${judge.name} as a judge for this event?`,
    confirmLabel: 'Remove',
    danger: true,
  });
  if (!ok) return;
  try {
    await unassignJudge(props.showId, judge.user_id);
    toast.success(`${judge.name} removed as a judge.`);
    await load();
  } catch (caught) {
    toast.error(mapAdminError(caught, 'The judge could not be removed.').message);
  }
}
</script>

<template>
  <section aria-labelledby="event-judges-heading">
    <h2
      id="event-judges-heading"
      class="sr-only"
    >
      Event judges
    </h2>

    <p
      v-if="error"
      role="alert"
      class="a2-alert a2-alert-error mt-2"
    >
      {{ error }}
    </p>
    <p
      v-else-if="judges.length === 0"
      class="a2-muted"
    >
      No judges are assigned to this event yet.
    </p>
    <ul
      v-else
      class="mt-2 divide-y"
    >
      <li
        v-for="judge in judges"
        :key="judge.user_id"
        class="flex flex-wrap items-center gap-2 py-2"
      >
        <span class="font-medium text-heading">{{ judge.name }}</span>
        <span class="text-muted">{{ judge.email }}</span>
        <button
          type="button"
          class="a2-btn a2-btn-small ml-auto"
          :aria-label="`Remove ${judge.name} as judge`"
          @click="remove(judge)"
        >
          Remove
        </button>
      </li>
    </ul>

    <form
      class="mt-3 flex flex-wrap items-end gap-3"
      @submit.prevent="add"
    >
      <div class="min-w-64 flex-1 sm:max-w-md">
        <label
          for="event-judge-select"
          class="a2-search-label"
        >Judge account</label>
        <BaseCombobox
          id="event-judge-select"
          :model-value="selectedUserId === '' ? null : selectedUserId"
          :options="judgeOptions"
          placeholder="Choose a judge"
          :disabled="eligible.length === 0"
          @update:model-value="(value) => (selectedUserId = value === null ? '' : String(value))"
        />
      </div>
      <button
        class="a2-btn a2-btn-info"
        type="submit"
        :disabled="selectedUserId === ''"
      >
        Add judge
      </button>
      <p
        v-if="addError"
        role="alert"
        class="w-full text-danger"
      >
        {{ addError }}
      </p>
    </form>
  </section>
</template>
