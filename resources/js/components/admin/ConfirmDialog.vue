<script setup lang="ts">
/**
 * Confirmation dialog on BaseModal (focus trap, Escape, focus return to the opener). For destructive actions set
 * `danger` (red confirm button; Cancel gets initial focus) and optionally `typedConfirmation`: the confirm button
 * stays disabled until the user types that exact text. Emits `confirm` or `cancel`; v-model:open is the parent's.
 * Pages usually call useConfirm() instead of mounting this directly.
 */
import { computed, ref, useId, watch } from 'vue';
import BaseModal from '../ui/BaseModal.vue';
import BaseButton from '../ui/BaseButton.vue';

const props = withDefaults(
  defineProps<{
    open: boolean;
    title: string;
    message: string;
    confirmLabel?: string;
    cancelLabel?: string;
    danger?: boolean;
    typedConfirmation?: string;
    busy?: boolean;
  }>(),
  { confirmLabel: 'Confirm', cancelLabel: 'Cancel', danger: false, typedConfirmation: '', busy: false },
);

const emit = defineEmits<{ 'update:open': [open: boolean]; confirm: []; cancel: [] }>();

const typed = ref('');
const inputId = useId();
const messageId = useId();

watch(
  () => props.open,
  (open) => {
    if (open) typed.value = '';
  },
);

const needsTyping = computed(() => props.typedConfirmation !== '');
const canConfirm = computed(() => !props.busy && (!needsTyping.value || typed.value.trim() === props.typedConfirmation));

function cancel(): void {
  emit('update:open', false);
  emit('cancel');
}

function confirm(): void {
  if (canConfirm.value) emit('confirm');
}
</script>

<template>
  <BaseModal
    :open="open"
    :title="title"
    size="sm"
    :close-on-backdrop="!busy"
    @update:open="(value) => { if (!value) cancel(); }"
  >
    <form
      novalidate
      :aria-describedby="messageId"
      @submit.prevent="confirm"
    >
      <p
        :id="messageId"
        class="text-sm text-text"
      >
        {{ message }}
      </p>
      <div
        v-if="needsTyping"
        class="mt-4 space-y-1"
      >
        <label
          :for="inputId"
          class="block text-sm font-medium text-text"
        >Type <strong>{{ typedConfirmation }}</strong> to confirm</label>
        <input
          :id="inputId"
          v-model="typed"
          type="text"
          class="form-control w-full"
          autocomplete="off"
          spellcheck="false"
          data-autofocus
        >
      </div>
      <div class="mt-5 flex flex-wrap justify-end gap-2">
        <BaseButton
          :data-autofocus="danger && !needsTyping ? '' : undefined"
          :disabled="busy"
          @click="cancel"
        >
          {{ cancelLabel }}
        </BaseButton>
        <BaseButton
          type="submit"
          :variant="danger ? 'danger' : 'primary'"
          :disabled="!canConfirm"
          :loading="busy"
          :data-autofocus="!danger && !needsTyping ? '' : undefined"
        >
          {{ confirmLabel }}
        </BaseButton>
      </div>
    </form>
  </BaseModal>
</template>
