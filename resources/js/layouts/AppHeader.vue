<script setup lang="ts">
/**
 * Site header: logo, primary nav (EVENTS, MEMBERS, GALLERY, FORUM, SHOP), and LOGIN / CREATE ACCOUNT or the
 * "Welcome" account menu. Below 768px the nav collapses behind an accessible menu button.
 */
import { computed, ref, watch } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import { useAuthStore } from '../stores/auth';
import { getForumUrl, getShopUrl } from '../config';
import DropdownMenu from '../components/ui/DropdownMenu.vue';
import { accountMenuItems, LOGOUT_ITEM_ID, navSectionFor, type NavSection } from './navigation';

const LOGO_SRC = '/images/sesf_final_logo_v2.jpg';

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();

const menuOpen = ref(false);
const menuButton = ref<HTMLButtonElement | null>(null);

interface NavLink {
  label: string;
  to?: string;
  href?: string;
  section?: NavSection;
}

const navLinks = computed<NavLink[]>(() => {
  const links: NavLink[] = [
    { label: 'Events', to: '/events', section: 'events' },
    { label: 'Members', to: '/members', section: 'members' },
    { label: 'Gallery', to: '/gallery', section: 'gallery' },
  ];
  const forum = getForumUrl();
  const shop = getShopUrl();
  if (forum !== '') {
    links.push({ label: 'Forum', href: forum });
  }
  if (shop !== '') {
    links.push({ label: 'Shop', href: shop });
  }
  return links;
});

const activeSection = computed(() => navSectionFor(route.path));
const accountItems = computed(() => (auth.user === null ? [] : accountMenuItems(auth.user)));
const welcomeLabel = computed(() => {
  const name = auth.user?.firstName.trim() ?? '';
  return name === '' ? 'Welcome' : `Welcome ${name}`;
});

function isActive(link: NavLink): boolean {
  return link.section !== undefined && link.section === activeSection.value;
}

async function logout(): Promise<void> {
  menuOpen.value = false;
  await auth.logout();
  await router.push('/');
}

function onAccountSelect(id: string): void {
  if (id === LOGOUT_ITEM_ID) {
    void logout();
  }
}

function closeMenu(returnFocus: boolean): void {
  menuOpen.value = false;
  if (returnFocus) {
    menuButton.value?.focus();
  }
}

watch(
  () => route.fullPath,
  () => {
    menuOpen.value = false;
  },
);

const navLinkClass = 'text-nav uppercase tracking-[0.04em] no-underline';
</script>

<template>
  <header class="mx-auto w-full max-w-site bg-panel shadow-header md:w-[calc(100%-2rem)]">
    <div class="flex items-center gap-4 px-4 py-3 md:gap-8 md:px-panel md:py-5">
      <RouterLink
        to="/"
        class="shrink-0 no-underline"
      >
        <img
          :src="LOGO_SRC"
          alt="Southeast Sharkfest home"
          width="170"
          height="65"
          class="h-12 w-auto md:h-[65px]"
        >
      </RouterLink>

      <nav
        aria-label="Main"
        class="hidden flex-1 md:block"
      >
        <ul class="flex flex-wrap items-center gap-x-7 gap-y-2">
          <li
            v-for="link in navLinks"
            :key="link.label"
          >
            <RouterLink
              v-if="link.to"
              :to="link.to"
              :class="[navLinkClass, 'border-b-2 py-1', isActive(link) ? 'border-brand font-semibold text-heading' : 'border-transparent text-muted hover:text-heading']"
              :aria-current="isActive(link) ? 'page' : undefined"
            >
              {{ link.label }}
            </RouterLink>
            <a
              v-else
              :href="link.href"
              :class="[navLinkClass, 'border-b-2 border-transparent py-1 text-muted hover:text-heading']"
            >{{ link.label }}</a>
          </li>
        </ul>
      </nav>

      <div class="ml-auto hidden md:block">
        <ul
          v-if="!auth.isLoggedIn"
          class="flex flex-col items-end gap-1.5 text-utility uppercase tracking-[0.04em]"
        >
          <li>
            <RouterLink
              to="/login"
              class="text-text no-underline hover:text-brand hover:underline"
            >
              Login
            </RouterLink>
          </li>
          <li>
            <a
              href="#/register"
              class="text-text no-underline hover:text-brand hover:underline"
            >Create Account</a>
          </li>
        </ul>
        <DropdownMenu
          v-else
          :label="welcomeLabel"
          :items="accountItems"
          trigger-class="inline-flex items-center rounded-sm px-2 py-1 text-utility uppercase tracking-[0.04em] text-text hover:bg-subtle"
          @select="onAccountSelect"
        />
      </div>

      <button
        ref="menuButton"
        type="button"
        class="ml-auto inline-flex items-center gap-2 rounded-sm border border-default-border px-3 py-2 text-sm font-medium uppercase tracking-[0.04em] text-text hover:bg-subtle md:hidden"
        aria-controls="mobile-menu"
        :aria-expanded="menuOpen ? 'true' : 'false'"
        @click="menuOpen = !menuOpen"
        @keydown.esc="menuOpen && closeMenu(true)"
      >
        <svg
          class="h-5 w-5"
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          stroke-width="2"
          aria-hidden="true"
          focusable="false"
        >
          <path
            v-if="menuOpen"
            stroke-linecap="round"
            d="M6 6l12 12M18 6L6 18"
          />
          <path
            v-else
            stroke-linecap="round"
            d="M4 7h16M4 12h16M4 17h16"
          />
        </svg>
        Menu
      </button>
    </div>

    <div
      v-show="menuOpen"
      id="mobile-menu"
      class="border-t border-border px-4 pb-4 md:hidden"
      @keydown.esc="closeMenu(true)"
    >
      <nav aria-label="Main menu">
        <ul class="divide-y divide-border">
          <li
            v-for="link in navLinks"
            :key="link.label"
          >
            <RouterLink
              v-if="link.to"
              :to="link.to"
              :class="[navLinkClass, 'block py-3', isActive(link) ? 'font-semibold text-heading' : 'text-muted']"
              :aria-current="isActive(link) ? 'page' : undefined"
            >
              {{ link.label }}
            </RouterLink>
            <a
              v-else
              :href="link.href"
              :class="[navLinkClass, 'block py-3 text-muted']"
            >{{ link.label }}</a>
          </li>
        </ul>
      </nav>
      <div class="mt-2 border-t-2 border-border pt-3">
        <ul
          v-if="!auth.isLoggedIn"
          class="flex flex-wrap gap-x-6 gap-y-2 text-sm uppercase tracking-[0.04em]"
        >
          <li>
            <RouterLink
              to="/login"
              class="text-text no-underline"
            >
              Login
            </RouterLink>
          </li>
          <li>
            <a
              href="#/register"
              class="text-text no-underline"
            >Create Account</a>
          </li>
        </ul>
        <div v-else>
          <p class="text-sm font-semibold text-heading">
            {{ welcomeLabel }}
          </p>
          <ul class="mt-1 text-sm">
            <li
              v-for="item in accountItems"
              :key="item.id"
            >
              <RouterLink
                v-if="item.to !== undefined"
                :to="item.to"
                class="block py-2 text-text no-underline"
              >
                {{ item.label }}
              </RouterLink>
              <a
                v-else-if="item.href !== undefined"
                :href="item.href"
                class="block py-2 text-text no-underline"
              >{{ item.label }}</a>
              <button
                v-else
                type="button"
                class="block w-full py-2 text-left text-text"
                @click="onAccountSelect(item.id)"
              >
                {{ item.label }}
              </button>
            </li>
          </ul>
        </div>
      </div>
    </div>
  </header>
</template>
