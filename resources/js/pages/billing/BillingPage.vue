<script setup lang="ts">
/**
 * Member billing (legacy member billing and invoice pages): a picker for the member's registrations and the invoice
 * for the one chosen. The URL names the registration; without one the newest registration is shown.
 */
import { computed, watch } from 'vue';
import { RouterLink, useRouter } from 'vue-router';
import '../../../css/billing-print.css';
import type { InvoiceListItem } from '../../api/billing';
import BaseAlert from '../../components/ui/BaseAlert.vue';
import BaseButton from '../../components/ui/BaseButton.vue';
import BaseSpinner from '../../components/ui/BaseSpinner.vue';
import MemberTabs from '../../components/member/MemberTabs.vue';
import InvoiceDocument from '../../components/billing/InvoiceDocument.vue';
import InvoicePicker from '../../components/billing/InvoicePicker.vue';
import PayPalPayment from '../../components/billing/PayPalPayment.vue';
import { getAppUrl, getPaypalEmail } from '../../config';
import { setPageTitle } from '../../composables/usePageTitle';
import { isPayable, registerPath } from '../../composables/billing/invoice';
import { useBilling } from '../../composables/billing/useBilling';

const props = defineProps<{ memberName: string; memberId: number; registrationId?: number }>();

const router = useRouter();
const billing = useBilling(() => ({ memberId: props.memberId, registrationId: props.registrationId }));
const paypalEmail = getPaypalEmail();
const appUrl = getAppUrl();

const invoice = computed(() => billing.invoice.value);
const profileBase = computed(() => `/${encodeURIComponent(props.memberName)}/u${props.memberId}`);

/** The picker lists the registrations; an invoice opened from a link that is not in the list still appears. */
const pickerOptions = computed<InvoiceListItem[]>(() => {
  const list = billing.invoices.value;
  const current = invoice.value;
  if (current === null || list.some((item) => item.id === current.id)) {
    return list;
  }
  return [{ id: current.id, name: current.carShow?.name ?? '' }, ...list];
});

const editPath = computed(() => (invoice.value === null ? null : registerPath(invoice.value)));
const canPay = computed(() => invoice.value !== null && isPayable(invoice.value));

function selectInvoice(registrationId: number): void {
  void router.push(`${profileBase.value}/billing/${registrationId}`);
}

function printInvoice(): void {
  window.print();
}

watch(
  () => billing.status.value,
  (next) => {
    if (next === 'notFound') {
      setPageTitle('Invoice not found');
    } else if (next === 'ready' && invoice.value !== null) {
      setPageTitle(`Billing: ${invoice.value.carShow?.name || 'Registration'}`);
    }
  },
);
</script>

<template>
  <section class="space-y-6">
    <MemberTabs
      :member-name="memberName"
      :member-id="memberId"
    />
    <header class="no-print">
      <h1 class="page-title">
        Billing
      </h1>
    </header>

    <BaseSpinner
      v-if="billing.status.value === 'loading'"
      label="Loading invoices"
      class="py-6 text-brand"
    />

    <div
      v-else-if="billing.status.value === 'notFound'"
      class="mx-auto max-w-xl"
    >
      <h2 class="text-xl font-semibold">
        Invoice not found
      </h2>
      <p class="mt-3">
        This invoice does not exist or is not available to you.
      </p>
      <p class="mt-4">
        <RouterLink :to="profileBase + '/billing'">
          Back to billing
        </RouterLink>
      </p>
    </div>

    <BaseAlert
      v-else-if="billing.status.value === 'error'"
      variant="error"
    >
      <p>The invoice could not be loaded.</p>
      <BaseButton
        variant="link"
        @click="billing.load()"
      >
        Try again
      </BaseButton>
    </BaseAlert>

    <div
      v-else-if="billing.status.value === 'empty'"
      class="mx-auto max-w-xl"
    >
      <p>You have no registrations yet.</p>
      <p class="mt-4">
        <RouterLink to="/events">
          Browse events
        </RouterLink>
      </p>
    </div>

    <template v-else-if="invoice !== null">
      <div class="no-print flex flex-wrap items-end justify-between gap-4">
        <InvoicePicker
          v-if="pickerOptions.length > 0"
          :options="pickerOptions"
          :model-value="invoice.id"
          @select="selectInvoice"
        />
        <div class="flex flex-wrap items-center gap-3">
          <BaseButton
            variant="default"
            @click="printInvoice"
          >
            Print invoice
          </BaseButton>
          <RouterLink
            v-if="editPath !== null"
            :to="editPath"
          >
            Edit registration
          </RouterLink>
        </div>
      </div>

      <InvoiceDocument :invoice="invoice" />

      <PayPalPayment
        v-if="canPay"
        :registration-id="invoice.id"
        :owed="invoice.totals.owed"
        :cancelled="invoice.isCancelled"
        :email="paypalEmail"
        :app-url="appUrl"
        @refresh="billing.load()"
      />
    </template>
  </section>
</template>
