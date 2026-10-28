<script setup lang="ts">
/**
 * Members | Cars tab bar of the directories (legacy nav-tabs above the grid). On an event's Who's Coming tab
 * it switches between that event's members and cars; otherwise between /members and /cars.
 */
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import TabNav, { type TabNavItem } from '../ui/TabNav.vue';

const props = defineProps<{ embedded: boolean }>();

const route = useRoute();

/** Event members/cars paths share the event prefix: /SoutheastSharkfest2026/e36/members <-> .../cars. */
const tabs = computed<TabNavItem[]>(() => {
  const base = props.embedded ? route.path.replace(/\/(members|cars)\/?$/, '') : '';
  return [
    { label: 'Members', to: `${base}/members` },
    { label: 'Cars', to: `${base}/cars` },
  ];
});
</script>

<template>
  <TabNav
    :tabs="tabs"
    :label="embedded ? 'Who\'s coming: members or cars' : 'Directory: members or cars'"
  />
</template>
