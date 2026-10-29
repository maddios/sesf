<script setup lang="ts">
/**
 * First-login welcome banner (legacy nav.blade.php memberTutorialController). Shown to a signed-in member while
 * GET tutorial/tutorial answers show_login_welcome = true; the close button hides it at once and saves
 * show_login_welcome = 0 (POST tutorial/tutorial). If saving fails the banner comes back so it can be closed again.
 * Reloaded whenever the signed-in member changes, like the legacy `login.update` listener.
 */
import { ref, watch } from 'vue';
import { api } from '../../api/client';
import { useAuthStore } from '../../stores/auth';

interface TutorialState {
  show_login_welcome: boolean;
}

const auth = useAuthStore();
const visible = ref(false);

watch(
  () => auth.userId,
  async (userId) => {
    visible.value = false;
    if (userId === null) return;
    try {
      const state = await api.get<TutorialState | null>('/tutorial/tutorial');
      visible.value = auth.userId === userId && state?.show_login_welcome === true;
    } catch {
      visible.value = false;
    }
  },
  { immediate: true },
);

async function hide(): Promise<void> {
  visible.value = false;
  try {
    await api.post('/tutorial/tutorial', { show_login_welcome: 0 });
  } catch {
    visible.value = true;
  }
}
</script>

<template>
  <section
    v-if="visible && auth.user"
    aria-label="Welcome"
    class="relative mx-auto mt-4 w-full max-w-site rounded-md bg-warning-bg px-4 py-3 pr-12 text-sm text-warning-fg md:w-[calc(100%-2rem)]"
  >
    <button
      type="button"
      class="absolute right-2 top-2 rounded-sm px-2 text-xl leading-none"
      aria-label="Close the welcome tutorial"
      @click="hide"
    >
      &times;
    </button>
    <p><b>Hi {{ auth.user.firstName }}, Welcome to the new home of the SESF!</b></p>
    <p class="mt-2">
      -Since this is your first time visiting the site, please visit your
      <a
        :href="auth.user.profileUrl"
        class="underline"
      >member profile</a>
      (click on your name on the top right of the screen). There you can configure all personal settings and upload a
      profile picture.
    </p>
    <p class="mt-2">
      -Also, stop by your
      <a
        :href="`${auth.user.profileUrl}/cars`"
        class="underline"
      >garage</a>
      to add or update your cars and vehicle images.
    </p>
    <p class="mt-2">
      -You can close this tutorial when you are done by clicking the x on the top right corner of this yellow box!
    </p>
  </section>
</template>
