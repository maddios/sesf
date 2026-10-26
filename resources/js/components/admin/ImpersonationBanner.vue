<script setup lang="ts">
/**
 * Shown on every page while the session is "logged in as" a member (admin-api/impersonation). Says whose session it
 * is and gives the way back: POST admin-api/impersonation/stop returns the administrator, then the admin area opens.
 * Mount it once in the app shell, above the page content.
 */
import { onMounted, ref } from 'vue';
import { api } from '../../api/client';
import { ADMIN_API_BASE, mapAdminError } from '../../composables/admin/useAdminResource';
import BaseButton from '../ui/BaseButton.vue';

interface Status {
  impersonating: boolean;
  impersonator_name: string | null;
}

const status = ref<Status | null>(null);
const busy = ref(false);
const error = ref<string | null>(null);

onMounted(async () => {
  try {
    status.value = (await api.get<{ data: Status }>(`${ADMIN_API_BASE}/impersonation`)).data;
  } catch {
    status.value = null;
  }
});

async function stop(): Promise<void> {
  busy.value = true;
  error.value = null;
  try {
    await api.post(`${ADMIN_API_BASE}/impersonation/stop`, {});
    window.location.assign('/#/admin/users');
  } catch (caught) {
    error.value = mapAdminError(caught, 'Could not return to your account. Reload and try again.').message;
    busy.value = false;
  }
}
</script>

<template>
  <div
    v-if="status?.impersonating"
    role="status"
    class="flex flex-wrap items-center justify-between gap-2 bg-warning-bg px-4 py-2 text-sm text-warning-fg"
  >
    <span>
      You are logged in as this member. Your administrator account is {{ status.impersonator_name ?? 'unknown' }}.
    </span>
    <BaseButton
      size="sm"
      :loading="busy"
      @click="stop"
    >
      Return to my account
    </BaseButton>
    <span
      v-if="error"
      class="w-full text-danger"
    >{{ error }}</span>
  </div>
</template>
