<script setup lang="ts">
import { computed } from 'vue';
import BaseAlert from '../ui/BaseAlert.vue';
import BaseButton from '../ui/BaseButton.vue';
import BaseModal from '../ui/BaseModal.vue';

/**
 * Admin confirm step for cancelling or reinstating a saved registration. Nothing is sent until the button is pressed.
 */
const props = defineProps<{
  open: boolean;
  busy: boolean;
  error: string | null;
  mode: 'cancel' | 'reinstate';
}>();

const copy = {
  cancel: {
    title: 'Cancel this registration?',
    body: 'The member will see this registration as cancelled and read only. Their cars, guests, shirts and extras stay on record.',
    action: 'Cancel registration',
    busyLabel: 'Cancelling registration',
  },
  reinstate: {
    title: 'Reinstate this registration?',
    body: 'The registration becomes active again and the member can edit it while registration is open.',
    action: 'Reinstate registration',
    busyLabel: 'Reinstating registration',
  },
} as const;

const text = computed(() => copy[props.mode]);

const emit = defineEmits<{ 'update:open': [open: boolean]; confirm: [] }>();
</script>

<template>
  <BaseModal
    :open="open"
    :title="text.title"
    size="sm"
    :close-on-backdrop="!busy"
    @update:open="emit('update:open', $event)"
  >
    <div class="space-y-3 text-sm">
      <p>{{ text.body }}</p>
      <BaseAlert
        v-if="error"
        variant="error"
      >
        <p>{{ error }}</p>
      </BaseAlert>
    </div>
    <template #footer>
      <BaseButton
        :disabled="busy"
        @click="emit('update:open', false)"
      >
        Keep registration
      </BaseButton>
      <BaseButton
        variant="danger"
        :loading="busy"
        :loading-label="text.busyLabel"
        @click="emit('confirm')"
      >
        {{ text.action }}
      </BaseButton>
    </template>
  </BaseModal>
</template>
