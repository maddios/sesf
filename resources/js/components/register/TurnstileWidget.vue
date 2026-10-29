<script setup lang="ts">
/**
 * Cloudflare Turnstile widget, rendered explicitly. Emits `update:token` with the token once solved, or null when it
 * expires or errors. `reset()` (exposed) clears the widget; tokens are single-use, so call it after every failed submit.
 */
import { onBeforeUnmount, onMounted, ref, useId } from 'vue';
import { loadTurnstile, type TurnstileApi } from './turnstileLoader';

const props = defineProps<{ siteKey: string }>();
const emit = defineEmits<{ 'update:token': [token: string | null] }>();

const container = ref<HTMLElement | null>(null);
const status = ref<'loading' | 'ready' | 'error'>('loading');
const fallbackId = useId();
let api: TurnstileApi | null = null;
let widgetId: string | null = null;
let unmounted = false;

async function render(): Promise<void> {
  try {
    api = await loadTurnstile();
  } catch {
    status.value = 'error';
    return;
  }
  if (unmounted || container.value === null) {
    return;
  }
  widgetId = api.render(container.value, {
    sitekey: props.siteKey,
    theme: 'light',
    callback: (token) => emit('update:token', token),
    'expired-callback': () => emit('update:token', null),
    'error-callback': () => {
      emit('update:token', null);
      status.value = 'error';
    },
  });
  status.value = 'ready';
}

/** Clears the widget and the held token. Safe to call before the widget has rendered. */
function reset(): void {
  emit('update:token', null);
  if (api !== null && widgetId !== null) {
    try {
      api.reset(widgetId);
    } catch {
      // The widget is gone or was never rendered; the token has already been cleared above.
    }
  }
}

defineExpose({ reset });

onMounted(() => {
  void render();
});

onBeforeUnmount(() => {
  unmounted = true;
});
</script>

<template>
  <div>
    <div
      :id="fallbackId"
      ref="container"
      class="min-h-[65px]"
    />
    <p
      v-if="status === 'loading'"
      class="text-sm text-muted"
    >
      Loading the security check. If it does not appear, turn off content blockers for this site and reload the page.
    </p>
    <p
      v-else-if="status === 'error'"
      role="alert"
      class="text-sm text-danger-fg"
    >
      The security check could not load. Reload the page to try again.
    </p>
  </div>
</template>
