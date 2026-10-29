<script setup lang="ts">
/**
 * Account deletion. Opens a confirm dialog that asks for the password; on success the local session is
 * cleared and the visitor is sent to the home page. Only the account owner sees this section.
 */
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import { deleteAccount } from '../../api/account';
import { useAuthStore } from '../../stores/auth';
import { useSaveState } from '../../composables/account/useSaveState';
import FormField from '../FormField.vue';
import ResponseBanner from '../ResponseBanner.vue';
import SubmitButton from '../SubmitButton.vue';
import BaseButton from '../ui/BaseButton.vue';
import BaseModal from '../ui/BaseModal.vue';

const auth = useAuthStore();
const router = useRouter();
const open = ref(false);
const password = ref('');
const passwordError = ref<string | null>(null);
const saveState = useSaveState();

function openDialog(): void {
  password.value = '';
  passwordError.value = null;
  saveState.reset();
  open.value = true;
}

async function onConfirm(): Promise<void> {
  saveState.reset();
  if (password.value === '') {
    passwordError.value = 'Enter your password to delete your account.';
    return;
  }
  passwordError.value = null;
  const ok = await saveState.run(() => deleteAccount(password.value), 'Your account was deleted.');
  if (ok) {
    open.value = false;
    auth.clear();
    await router.replace('/');
  }
}
</script>

<template>
  <div class="space-y-3">
    <p class="text-sm">
      Deleting your account removes your profile and garage from this site. Only an administrator can restore it.
    </p>
    <BaseButton
      variant="danger"
      @click="openDialog"
    >
      Delete my account
    </BaseButton>

    <BaseModal
      v-model:open="open"
      title="Delete your account?"
      size="sm"
    >
      <form
        id="delete-account-form"
        novalidate
        class="space-y-4"
        @submit.prevent="onConfirm"
      >
        <p class="text-sm">
          Enter your password to confirm. This cannot be undone by you.
        </p>
        <FormField
          id="delete-password"
          v-slot="{ inputProps }"
          label="Password"
          :errors="passwordError ? [passwordError] : []"
        >
          <input
            v-bind="inputProps"
            v-model="password"
            data-autofocus
            class="form-control w-full"
            type="password"
            autocomplete="current-password"
            :disabled="saveState.busy.value"
          >
        </FormField>
        <ResponseBanner
          :messages="saveState.messages.value"
          variant="error"
        />
      </form>
      <template #footer>
        <BaseButton
          :disabled="saveState.busy.value"
          @click="open = false"
        >
          Cancel
        </BaseButton>
        <SubmitButton
          form="delete-account-form"
          variant="danger"
          :busy="saveState.busy.value"
          busy-label="Deleting account"
        >
          Delete account
        </SubmitButton>
      </template>
    </BaseModal>
  </div>
</template>
