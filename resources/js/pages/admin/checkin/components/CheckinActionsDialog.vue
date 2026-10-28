<script setup lang="ts">
/**
 * The check-in modal of one registration (legacy checkin_list modal): party waiver state with a Sign link per unsigned
 * member, T-shirt hand-out toggles, payment, the waiver reminder and billing link emails, confirm and undo. Each change
 * returns the registration; the page replaces the row.
 */
import { computed, ref } from 'vue';
import BaseButton from '../../../../components/ui/BaseButton.vue';
import BaseModal from '../../../../components/ui/BaseModal.vue';
import { useConfirm } from '../../../../composables/admin/useConfirm';
import { useToast } from '../../../../composables/admin/useToast';
import { shirtsApi } from '../../tshirts/tshirts';
import { blockReason, checkinApi, failureMessage, type CheckinParty, type CheckinRow } from '../checkin';
import WaiverSignDialog from './WaiverSignDialog.vue';

const props = defineProps<{ row: CheckinRow }>();
const emit = defineEmits<{ changed: [row: CheckinRow]; reload: []; close: [] }>();

const toast = useToast();
const { confirm } = useConfirm();
const alert = ref<{ text: string; kind: 'success' | 'error' | 'info' } | null>(null);
const busy = ref(false);
const signing = ref<{ title: string; url: string } | null>(null);

const partyStatus = computed(() =>
  props.row.party_complete ? 'Everyone in the party has signed the waiver.' : 'At least one person still needs to sign.',
);
const checkedLine = computed(() => (props.row.checked_in_at ? `Checked in: ${new Date(props.row.checked_in_at).toLocaleString()}` : ''));
const confirmDisabled = computed(() => busy.value || props.row.is_checked_in);
const notReady = computed(() => !props.row.is_checked_in && blockReason(props.row) !== null);

function setAlert(text: string, kind: 'success' | 'error' | 'info' = 'info'): void {
  alert.value = { text, kind };
}

function fail(error: unknown, fallback: string): void {
  setAlert(failureMessage(error, fallback), 'error');
}

async function run(action: () => Promise<void>, fallback: string): Promise<void> {
  busy.value = true;
  try {
    await action();
  } catch (error) {
    fail(error, fallback);
  } finally {
    busy.value = false;
  }
}

async function confirmCheckIn(): Promise<void> {
  if (props.row.is_checked_in) return;
  let override = false;
  const reason = blockReason(props.row);
  if (reason !== null) {
    const ok = await confirm({ title: 'Check in anyway?', message: `Warning: ${reason}. Check in anyway?`, confirmLabel: 'Check in anyway', danger: true });
    if (!ok) return;
    override = true;
  }
  await run(async () => {
    const updated = await checkinApi.checkIn(props.row, override);
    emit('changed', updated);
    emit('close');
  }, 'Check-in failed');
}

async function undoCheckIn(): Promise<void> {
  const ok = await confirm({
    title: 'Remove check-in',
    message: 'Remove check-in for this registration? The check-in flag and time will be cleared.',
    confirmLabel: 'Remove check-in',
    danger: true,
  });
  if (!ok) return;
  await run(async () => {
    const updated = await checkinApi.checkOut(props.row);
    emit('changed', updated);
    toast.success('Check-in removed.');
    setAlert('Check-in removed.', 'success');
  }, 'Could not remove check-in');
}

async function sendReminder(): Promise<void> {
  const ok = await confirm({ title: 'Waiver reminder', message: 'Send waiver reminder email to the primary registrant?', confirmLabel: 'Send reminder' });
  if (!ok) return;
  await run(async () => {
    await checkinApi.waiverReminder(props.row);
    setAlert('Reminder queued for primary email.', 'success');
  }, 'Could not queue reminder');
}

async function sendPayLink(): Promise<void> {
  const message = props.row.is_paid
    ? 'Registration is marked paid. Send the billing link email anyway?'
    : 'Send billing / payment link email to the primary registrant?';
  const ok = await confirm({ title: 'Billing link', message, confirmLabel: 'Send email' });
  if (!ok) return;
  await run(async () => {
    await checkinApi.payLinkEmail(props.row);
    setAlert('Billing link email queued for primary email.', 'success');
  }, 'Could not queue email');
}

async function openSign(member: CheckinParty): Promise<void> {
  await run(async () => {
    const link = await checkinApi.waiverSignUrl(props.row, member.guest_id);
    signing.value = { title: `${link.personName} — Waiver`, url: link.url };
  }, 'Could not open waiver');
}

function closeSign(): void {
  signing.value = null;
  emit('reload');
}

async function toggleShirt(line: CheckinRow['shirt_lines'][number], handedOut: boolean): Promise<void> {
  await run(async () => {
    await shirtsApi.setHandedOut(line, handedOut);
    emit('reload');
  }, 'Could not update shirt');
}
</script>

<template>
  <BaseModal
    :open="true"
    :title="`Check-in · Reg #${row.invoice_id}`"
    size="lg"
    @update:open="(value: boolean) => { if (!value) emit('close'); }"
  >
    <div class="space-y-4 text-base">
      <p>
        <span class="text-muted">Primary</span> <strong>{{ row.primary_name }}</strong>
      </p>

      <section class="space-y-2">
        <h3 class="font-semibold">
          Party waivers
        </h3>
        <p :class="row.party_complete ? 'text-success-fg' : 'text-warning-fg'">
          {{ partyStatus }}
        </p>
        <ul class="space-y-2">
          <li
            v-for="member in row.party"
            :key="member.guest_id ?? 'primary'"
            class="flex flex-wrap items-center justify-between gap-2 rounded-md border border-border p-2"
          >
            <span>
              <span :class="member.signed ? 'rounded-full bg-success-bg px-2 text-success-fg' : 'rounded-full bg-warning-bg px-2 text-warning-fg'">{{ member.signed ? 'Signed' : 'Unsigned' }}</span>
              <span class="text-muted"> {{ member.role === 'guest' ? 'Guest' : 'Primary' }}</span>
              {{ member.name }}
            </span>
            <BaseButton
              v-if="!member.signed"
              size="lg"
              class="min-h-12"
              :disabled="busy"
              @click="openSign(member)"
            >
              Sign
            </BaseButton>
          </li>
        </ul>
      </section>

      <section class="space-y-2">
        <h3 class="font-semibold">
          T-shirts (toggle when handed out)
        </h3>
        <p
          v-if="row.shirt_lines.length === 0"
          class="text-muted"
        >
          None on order
        </p>
        <ul
          v-else
          class="grid gap-2 sm:grid-cols-2"
        >
          <li
            v-for="line in row.shirt_lines"
            :key="line.id"
          >
            <label class="flex min-h-12 items-center gap-3 rounded-md border border-border px-3">
              <input
                type="checkbox"
                class="h-6 w-6"
                :checked="line.handed_out"
                :aria-label="`Handed out: ${line.type_name}`"
                :disabled="busy"
                @change="toggleShirt(line, ($event.target as HTMLInputElement).checked)"
              >
              <span>{{ line.type_name }}</span>
            </label>
          </li>
        </ul>
      </section>

      <section class="space-y-1">
        <h3 class="font-semibold">
          Payment
        </h3>
        <span :class="row.is_paid ? 'rounded-full bg-success-bg px-2 text-success-fg' : 'rounded-full bg-warning-bg px-2 text-warning-fg'">{{ row.is_paid ? 'Paid' : 'Not paid' }}</span>
      </section>

      <p
        v-if="checkedLine"
        class="text-muted"
      >
        {{ checkedLine }}
      </p>
      <p
        v-if="alert"
        role="status"
        class="rounded-md border p-3 text-sm"
        :class="alert.kind === 'error' ? 'border-danger-border bg-danger-bg' : alert.kind === 'success' ? 'border-success-border bg-success-bg' : 'border-info-border bg-info-bg'"
      >
        {{ alert.text }}
      </p>
    </div>

    <template #footer>
      <BaseButton
        v-if="!row.party_complete"
        size="lg"
        class="min-h-12"
        :disabled="busy"
        @click="sendReminder"
      >
        Send waiver reminder
      </BaseButton>
      <BaseButton
        v-if="!row.is_paid"
        size="lg"
        class="min-h-12"
        :disabled="busy || !row.primary_email"
        :title="row.primary_email ? 'Sends the same billing link email as new registration.' : 'Primary registrant has no email on file.'"
        @click="sendPayLink"
      >
        Email billing link
      </BaseButton>
      <BaseButton
        v-if="row.is_checked_in"
        variant="danger"
        size="lg"
        class="min-h-12"
        :disabled="busy"
        @click="undoCheckIn"
      >
        Remove check-in
      </BaseButton>
      <BaseButton
        :variant="notReady ? 'default' : 'primary'"
        size="lg"
        class="min-h-12"
        :disabled="confirmDisabled"
        @click="confirmCheckIn"
      >
        {{ row.is_checked_in ? 'Already checked in' : 'Confirm check-in' }}
      </BaseButton>
      <BaseButton
        size="lg"
        class="min-h-12"
        @click="emit('close')"
      >
        Close
      </BaseButton>
    </template>

    <WaiverSignDialog
      :open="signing !== null"
      :title="signing?.title ?? ''"
      :url="signing?.url ?? null"
      @close="closeSign"
    />
  </BaseModal>
</template>
