<script setup lang="ts">
/** Renders the shared useConfirm() request. Mounted once by AdminLayout. */
import { computed } from 'vue';
import ConfirmDialog from './ConfirmDialog.vue';
import { useConfirm } from '../../composables/admin/useConfirm';

const { pending, settle } = useConfirm();
const options = computed(() => pending.value?.options);
</script>

<template>
  <ConfirmDialog
    :open="options !== undefined"
    :title="options?.title ?? ''"
    :message="options?.message ?? ''"
    :confirm-label="options?.confirmLabel"
    :cancel-label="options?.cancelLabel"
    :danger="options?.danger ?? false"
    :typed-confirmation="options?.typedConfirmation ?? ''"
    @confirm="settle(true)"
    @cancel="settle(false)"
  />
</template>
