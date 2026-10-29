<script setup lang="ts">
/**
 * App shell: skip link, header, the first-login welcome banner, the white content panel (<main id="main">) and footer.
 * After each route change (path, not query) focus moves to <main> so keyboard and screen-reader users start
 * at the new page content. Contact Us opens the contact dialog and is also re-emitted as `contact`.
 */
import { nextTick, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import AppHeader from './AppHeader.vue';
import AppFooter from './AppFooter.vue';
import ContactModal from '../components/contact/ContactModal.vue';
import ImpersonationBanner from '../components/admin/ImpersonationBanner.vue';
import TutorialBanner from '../components/member/TutorialBanner.vue';
import { useAuthStore } from '../stores/auth';

const emit = defineEmits<{ contact: [] }>();

const auth = useAuthStore();

const route = useRoute();
const main = ref<HTMLElement | null>(null);
/** The Contact Us dialog lives in the layout, so every page can open it from the footer. */
const contactOpen = ref(false);

function openContact(): void {
  contactOpen.value = true;
  emit('contact');
}

function focusMain(): void {
  main.value?.focus({ preventScroll: true });
}

watch(
  () => route.path,
  async (path, previous) => {
    if (previous === undefined || path === previous) {
      return;
    }
    await nextTick();
    focusMain();
  },
);
</script>

<template>
  <div class="flex min-h-screen flex-col bg-surface text-text">
    <!-- Hash routing: the skip link moves focus itself instead of changing the URL hash. -->
    <a
      href="#main"
      class="sr-only z-50 rounded-sm bg-panel px-4 py-2 font-semibold text-brand shadow-overlay focus:not-sr-only focus:fixed focus:left-4 focus:top-4"
      @click.prevent="focusMain"
    >Skip to main content</a>

    <ImpersonationBanner v-if="auth.isLoggedIn" />

    <AppHeader />

    <TutorialBanner />

    <main
      id="main"
      ref="main"
      tabindex="-1"
      class="mx-auto mt-4 w-full max-w-site flex-1 md:w-[calc(100%-2rem)]"
    >
      <div class="min-h-80 bg-panel px-4 py-6 text-content text-title shadow-panel sm:rounded-panel md:px-panel md:pt-[30px] md:pb-panel">
        <slot />
      </div>
    </main>

    <AppFooter @contact="openContact" />

    <ContactModal v-model:open="contactOpen" />
  </div>
</template>
