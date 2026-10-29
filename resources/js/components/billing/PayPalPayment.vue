<script setup lang="ts">
/**
 * "Pay with PayPal" for a positive balance. A plain POST form to PayPal's webscr endpoint (cmd=_xclick, the same
 * fields as the legacy invoice). Amounts go out as plain two-decimal numbers. Nothing is shown when the balance is
 * not payable. When the receiver or app URL is not configured, a dev-only notice replaces the form.
 */
import { computed } from 'vue';
import { isPositiveMoney } from '../../composables/billing/money';
import { buildPaypalFields, PAYPAL_WEBSCR_URL } from '../../composables/billing/paypal';
import BaseButton from '../ui/BaseButton.vue';

const props = defineProps<{
  registrationId: number;
  /** Balance due, a decimal string from the server. */
  owed: string;
  cancelled: boolean;
  /** PayPal receiver (config paypalEmail). Empty when not configured. */
  email: string;
  /** Public app URL (config appUrl). Empty when not configured. */
  appUrl: string;
}>();

const emit = defineEmits<{ refresh: [] }>();

const fields = computed(() =>
  buildPaypalFields({
    email: props.email,
    appUrl: props.appUrl,
    registrationId: props.registrationId,
    owed: props.owed,
    returnUrl: typeof window === 'undefined' ? '' : window.location.href,
  }),
);

const visible = computed(() => !props.cancelled && fields.value !== null);
const devNotice = computed(
  () => import.meta.env.DEV && !props.cancelled && isPositiveMoney(props.owed) && fields.value === null,
);
</script>

<template>
  <section
    v-if="visible"
    class="no-print space-y-3 rounded-md border border-border bg-white p-6"
    aria-labelledby="paypal-heading"
  >
    <h3
      id="paypal-heading"
      class="text-base font-semibold"
    >
      Pay with PayPal
    </h3>
    <p class="text-sm">
      Payment is taken by PayPal. Your payment confirmation arrives by email. This page updates after PayPal notifies
      us, which can take a few minutes. Use Refresh to check again.
    </p>
    <form
      :action="PAYPAL_WEBSCR_URL"
      method="post"
      target="_blank"
      rel="noopener"
      class="flex flex-wrap items-center gap-3"
    >
      <input
        v-for="(value, name) in fields"
        :key="name"
        type="hidden"
        :name="name"
        :value="value"
      >
      <BaseButton
        type="submit"
        variant="primary"
      >
        Pay with PayPal
      </BaseButton>
      <BaseButton
        type="button"
        @click="emit('refresh')"
      >
        Refresh
      </BaseButton>
    </form>
  </section>
  <p
    v-else-if="devNotice"
    class="no-print rounded-sm border border-dashed border-border p-3 text-sm text-muted"
  >
    PayPal not configured (dev notice: set SESF_PAYPAL_EMAIL and APP_URL).
  </p>
</template>
