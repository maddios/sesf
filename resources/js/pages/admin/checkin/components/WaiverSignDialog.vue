<script setup lang="ts">
/**
 * The waiver form of one party member, opened from the check-in modal (legacy waiver iframe). Closing it reloads the
 * list so the party state shows the signature.
 */
import BaseModal from '../../../../components/ui/BaseModal.vue';

defineProps<{ open: boolean; title: string; url: string | null }>();
const emit = defineEmits<{ close: [] }>();
</script>

<template>
  <BaseModal
    :open="open"
    :title="title"
    size="lg"
    @update:open="(value: boolean) => { if (!value) emit('close'); }"
  >
    <iframe
      v-if="url"
      :src="url"
      :title="title"
      class="h-[70vh] w-full rounded-md border border-border"
    />
    <template #footer>
      <button
        type="button"
        class="min-h-12 rounded-md border border-border px-4 text-base"
        @click="emit('close')"
      >
        Close
      </button>
    </template>
  </BaseModal>
</template>
