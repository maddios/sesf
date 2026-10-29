<script setup lang="ts">
/**
 * Private-profile tabs shown to the profile owner and to admins. Each tab is a real route
 * (/{name}/u{id}, /cars, /settings, /billing); TabNav marks the current one with aria-current.
 */
import { computed } from 'vue';
import TabNav, { type TabNavItem } from '../ui/TabNav.vue';

const props = defineProps<{
  memberName: string;
  memberId: number;
}>();

const tabs = computed<TabNavItem[]>(() => {
  const base = `/${encodeURIComponent(props.memberName)}/u${props.memberId}`;
  return [
    { label: 'Profile', to: base },
    { label: 'Garage', to: `${base}/cars` },
    { label: 'Settings', to: `${base}/settings` },
    { label: 'Billing', to: `${base}/billing` },
  ];
});
</script>

<template>
  <TabNav
    :tabs="tabs"
    label="Member profile sections"
  />
</template>
