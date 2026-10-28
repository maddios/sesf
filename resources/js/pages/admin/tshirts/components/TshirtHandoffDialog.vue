<script setup lang="ts">
/** "Shirts for registrant" (legacy T-shirt modal): one hand-out switch per shirt line of one registration. */
import BaseModal from '../../../../components/ui/BaseModal.vue';
import HandoffToggle from '../../../../components/admin/HandoffToggle.vue';
import type { ShirtLine, ShirtRegistrationRow } from '../tshirts';

defineProps<{ row: ShirtRegistrationRow | null; busyLineId: number | null; error: string | null }>();
const emit = defineEmits<{ toggle: [line: ShirtLine, handedOut: boolean]; close: [] }>();
</script>

<template>
  <BaseModal
    :open="row !== null"
    title="Shirts for registrant"
    size="sm"
    sheet
    @close="emit('close')"
  >
    <div
      v-if="row"
      class="a2-page"
    >
      <p class="a2-modal-line">
        <span class="a2-modal-label">Registration #</span>
        <strong>#{{ row.invoice_id }}</strong>
      </p>
      <p class="a2-modal-line">
        <span class="a2-modal-label">Primary</span>
        {{ row.primary_name }}
      </p>
      <div class="a2-modal-section">
        <span class="a2-modal-label">Mark each shirt as handed out</span>
        <ul class="mt-2.5 space-y-2.5">
          <li
            v-for="line in row.lines"
            :key="line.id"
          >
            <HandoffToggle
              :label="line.type_name"
              :checked="line.handed_out"
              :busy="busyLineId === line.id"
              @toggle="(handedOut) => emit('toggle', line, handedOut)"
            />
          </li>
        </ul>
      </div>
      <p
        v-if="error"
        role="alert"
        class="a2-alert a2-alert-error mt-3"
      >
        {{ error }}
      </p>
    </div>
    <template #footer>
      <button
        type="button"
        class="a2-btn"
        @click="emit('close')"
      >
        Close
      </button>
    </template>
  </BaseModal>
</template>
