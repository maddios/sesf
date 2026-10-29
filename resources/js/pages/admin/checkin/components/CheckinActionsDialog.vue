<script setup lang="ts">
/**
 * The check-in modal of one registration (legacy checkin_list modal): party waiver state with a Sign link per unsigned
 * member, T-shirt hand-out toggles, payment, the waiver reminder and billing link emails, confirm and undo. Each change
 * returns the registration; the page replaces the row.
 */
import { computed, ref } from 'vue';
import BaseModal from '../../../../components/ui/BaseModal.vue';
import HandoffToggle from '../../../../components/admin/HandoffToggle.vue';
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
    if (await checkinApi.waiverReminder(props.row)) {
      setAlert('Reminder queued for primary email.', 'success');
    } else {
      setAlert('The reminder could not be queued and was not sent. Try again.', 'error');
    }
  }, 'Could not queue reminder');
}

async function sendPayLink(): Promise<void> {
  const message = props.row.is_paid
    ? 'Registration is marked paid. Send the billing link email anyway?'
    : 'Send billing / payment link email to the primary registrant?';
  const ok = await confirm({ title: 'Billing link', message, confirmLabel: 'Send email' });
  if (!ok) return;
  await run(async () => {
    if (await checkinApi.payLinkEmail(props.row)) {
      setAlert('Billing link email queued for primary email.', 'success');
    } else {
      setAlert('The billing link email could not be queued and was not sent. Try again.', 'error');
    }
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
    sheet
    :open="true"
    :title="`Check-in · Reg #${row.invoice_id}`"
    size="sm"
    @update:open="(value: boolean) => { if (!value) emit('close'); }"
  >
    <div class="a2-page">
      <p class="a2-modal-line">
        <span class="a2-modal-label">Registration #</span>
        <strong>#{{ row.invoice_id }}</strong>
      </p>
      <p class="a2-modal-line">
        <span class="a2-modal-label">Primary</span>
        {{ row.primary_name }}
      </p>

      <div class="a2-modal-section">
        <span class="a2-modal-label">Party waivers</span>
        <p
          class="mt-1 font-semibold"
          :class="row.party_complete ? 'text-[#1d6b3a]' : 'text-[#b35400]'"
        >
          {{ partyStatus }}
        </p>
        <ul class="mt-2">
          <li
            v-for="member in row.party"
            :key="member.guest_id ?? 'primary'"
            class="a2-subtle-row flex items-center justify-between gap-2"
          >
            <span>
              <span
                class="a2-label"
                :class="member.signed ? 'a2-label-success' : 'a2-label-warning'"
              >{{ member.signed ? 'Signed' : 'Unsigned' }}</span>
              {{ member.role === 'guest' ? 'Guest' : 'Primary' }} {{ member.name }}
            </span>
            <button
              v-if="!member.signed"
              type="button"
              class="a2-btn a2-btn-small a2-btn-info"
              :disabled="busy"
              @click="openSign(member)"
            >
              Sign
            </button>
          </li>
        </ul>
      </div>

      <div class="a2-modal-section">
        <span class="a2-modal-label">T-shirts (toggle when handed out)</span>
        <p
          v-if="row.shirt_lines.length === 0"
          class="a2-muted mt-1"
        >
          None on order
        </p>
        <ul
          v-else
          class="mt-2.5 space-y-2.5"
        >
          <li
            v-for="line in row.shirt_lines"
            :key="line.id"
          >
            <HandoffToggle
              compact
              :label="line.type_name"
              :checked="line.handed_out"
              :busy="busy"
              @toggle="(handedOut) => toggleShirt(line, handedOut)"
            />
          </li>
        </ul>
      </div>

      <p class="a2-modal-line">
        <span class="a2-modal-label">Payment</span>
        <span
          class="a2-label"
          :class="row.is_paid ? 'a2-label-success' : 'a2-label-warning'"
        >{{ row.is_paid ? 'Paid' : 'Not paid' }}</span>
      </p>
      <p
        v-if="checkedLine"
        class="a2-modal-line a2-muted"
      >
        {{ checkedLine }}
      </p>
      <p
        v-if="alert"
        role="status"
        class="a2-alert"
        :class="alert.kind === 'error' ? 'a2-alert-error' : alert.kind === 'success' ? 'a2-alert-success' : 'a2-alert-info'"
      >
        {{ alert.text }}
      </p>
    </div>

    <template #footer>
      <div class="a2-modal-actions">
        <button
          v-if="!row.party_complete"
          type="button"
          class="a2-btn a2-btn-secondary"
          :disabled="busy"
          @click="sendReminder"
        >
          Send waiver reminder
        </button>
        <button
          v-if="!row.is_paid"
          type="button"
          class="a2-btn a2-btn-secondary"
          :disabled="busy || !row.primary_email"
          :title="row.primary_email ? 'Sends the same billing link email as new registration.' : 'Primary registrant has no email on file.'"
          @click="sendPayLink"
        >
          Email billing link
        </button>
        <button
          v-if="row.is_checked_in"
          type="button"
          class="a2-btn a2-btn-warning"
          :disabled="busy"
          @click="undoCheckIn"
        >
          Remove check-in
        </button>
        <button
          type="button"
          class="a2-btn"
          :class="notReady ? 'a2-btn-warning' : 'a2-btn-info'"
          :disabled="confirmDisabled"
          @click="confirmCheckIn"
        >
          {{ row.is_checked_in ? 'Already checked in' : 'Confirm check-in' }}
        </button>
        <button
          type="button"
          class="a2-btn"
          @click="emit('close')"
        >
          Close
        </button>
      </div>
    </template>

    <WaiverSignDialog
      :open="signing !== null"
      :title="signing?.title ?? ''"
      :url="signing?.url ?? null"
      @close="closeSign"
    />
  </BaseModal>
</template>
