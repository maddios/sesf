<script setup lang="ts">
/**
 * The waiver form of one party member, opened from the check-in modal (legacy waiver iframe). The sign page runs in
 * embedded mode; its thank-you page posts a message when the signature is saved, which closes the dialog so the list
 * reloads. Closing with a drawn but unsubmitted signature asks first (legacy).
 */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import BaseModal from '../../../../components/ui/BaseModal.vue';
import { useConfirm } from '../../../../composables/admin/useConfirm';

const WAIVER_SIGNED_MESSAGE = 'sesf-portal-waiver-signed';

const props = defineProps<{ open: boolean; title: string; url: string | null }>();
const emit = defineEmits<{ close: [] }>();

const { confirm } = useConfirm();
const frame = ref<HTMLIFrameElement | null>(null);

const embeddedUrl = computed(() => {
  if (props.url === null) return null;
  const url = new URL(props.url, window.location.origin);
  url.searchParams.set('ui', 'embedded');
  return url.toString();
});

function isDirty(): boolean {
  try {
    const sign = (frame.value?.contentWindow as (Window & { sesfWaiverIsDirty?: () => boolean }) | null | undefined)?.sesfWaiverIsDirty;
    return typeof sign === 'function' && sign();
  } catch {
    return false;
  }
}

async function attemptClose(): Promise<void> {
  if (isDirty()) {
    const discard = await confirm({
      title: 'Close waiver',
      message: 'Close this waiver without submitting? The signature you drew will be lost.',
      confirmLabel: 'Close waiver',
      danger: true,
    });
    if (!discard) return;
  }
  emit('close');
}

function onMessage(event: MessageEvent<unknown>): void {
  const data = event.data as { type?: unknown } | null;
  if (!props.open || event.origin !== window.location.origin || event.source !== frame.value?.contentWindow) return;
  if (data?.type === WAIVER_SIGNED_MESSAGE) emit('close');
}

function onBeforeUnload(event: BeforeUnloadEvent): void {
  if (props.open && isDirty()) {
    event.preventDefault();
    event.returnValue = '';
  }
}

onMounted(() => {
  window.addEventListener('message', onMessage);
  window.addEventListener('beforeunload', onBeforeUnload);
});
onBeforeUnmount(() => {
  window.removeEventListener('message', onMessage);
  window.removeEventListener('beforeunload', onBeforeUnload);
});
</script>

<template>
  <BaseModal
    :open="open"
    :title="title"
    size="lg"
    sheet
    @update:open="(value: boolean) => { if (!value) void attemptClose(); }"
  >
    <iframe
      v-if="embeddedUrl"
      ref="frame"
      :src="embeddedUrl"
      :title="title"
      class="h-[calc(100dvh-8rem)] w-full rounded-md border border-border md:h-[70vh]"
    />
    <template #footer>
      <button
        type="button"
        class="a2-btn"
        @click="attemptClose()"
      >
        Close
      </button>
    </template>
  </BaseModal>
</template>
