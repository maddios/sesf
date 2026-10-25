<script setup lang="ts">
/**
 * Member settings (legacy /:name/u:id/settings). One section open at a time, as in the legacy accordion; all
 * sections stay mounted so switching sections never drops unsaved input. Password and account deletion act on
 * the signed-in session, so they are shown only to the account owner (admins see the member-data sections).
 */
import { computed } from 'vue';
import type { AccordionItem } from '../../components/ui/BaseAccordion.vue';
import BaseAccordion from '../../components/ui/BaseAccordion.vue';
import BaseAlert from '../../components/ui/BaseAlert.vue';
import MemberTabs from '../../components/member/MemberTabs.vue';
import AccountInfoSection from '../../components/account/AccountInfoSection.vue';
import AddressSection from '../../components/account/AddressSection.vue';
import DeleteAccountSection from '../../components/account/DeleteAccountSection.vue';
import EmailPreferencesSection from '../../components/account/EmailPreferencesSection.vue';
import PasswordSection from '../../components/account/PasswordSection.vue';
import ProfileImageSection from '../../components/account/ProfileImageSection.vue';
import { useAuthStore } from '../../stores/auth';

const props = defineProps<{ memberName: string; memberId: number }>();

const auth = useAuthStore();
const isOwner = computed(() => auth.userId === props.memberId);

const items = computed<AccordionItem[]>(() => {
  const list: AccordionItem[] = [{ id: 'image', title: 'Profile image' }, { id: 'info', title: 'Account information' }];
  if (isOwner.value) {
    list.push({ id: 'password', title: 'Password' });
  }
  list.push({ id: 'address', title: 'Address' }, { id: 'preferences', title: 'Email preferences' });
  if (isOwner.value) {
    list.push({ id: 'delete', title: 'Delete account' });
  }
  return list;
});
</script>

<template>
  <section class="space-y-6">
    <MemberTabs
      :member-name="memberName"
      :member-id="memberId"
    />
    <header>
      <h1 class="page-title">
        Account settings
      </h1>
    </header>

    <BaseAlert
      v-if="!isOwner"
      variant="info"
    >
      <p>Admins edit members in the admin area. These settings belong to the member who owns this account.</p>
    </BaseAlert>

    <BaseAccordion
      v-else
      :items="items"
      :default-open="['image']"
      :heading-level="2"
    >
      <template #image>
        <ProfileImageSection :user-id="memberId" />
      </template>
      <template #info>
        <AccountInfoSection :user-id="memberId" />
      </template>
      <template #password>
        <PasswordSection />
      </template>
      <template #address>
        <AddressSection />
      </template>
      <template #preferences>
        <EmailPreferencesSection />
      </template>
      <template #delete>
        <DeleteAccountSection />
      </template>
    </BaseAccordion>
  </section>
</template>
