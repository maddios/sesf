<script setup lang="ts">
import BaseButton from '../../../components/ui/BaseButton.vue';
import type { JudgingEntry } from './judging';

defineProps<{
  entries: JudgingEntry[];
}>();

const emit = defineEmits<{
  remove: [entry: JudgingEntry];
}>();
</script>

<template>
  <div class="overflow-x-auto">
    <table class="mt-2 w-full border-collapse text-sm">
      <caption class="sr-only">
        Judging entries for the selected event
      </caption>
      <thead>
        <tr class="border-b text-left text-muted">
          <th
            scope="col"
            class="py-2 pr-3"
          >
            Car #
          </th>
          <th
            scope="col"
            class="py-2 pr-3"
          >
            Year
          </th>
          <th
            scope="col"
            class="py-2 pr-3"
          >
            Body
          </th>
          <th
            scope="col"
            class="py-2 pr-3"
          >
            Model
          </th>
          <th
            scope="col"
            class="py-2 pr-3"
          >
            Judging type
          </th>
          <th
            scope="col"
            class="py-2 pr-3"
          >
            Progress
          </th>
          <th
            scope="col"
            class="py-2 pr-3"
          >
            Score
          </th>
          <th
            scope="col"
            class="py-2 pr-3"
          >
            Owner
          </th>
          <th
            scope="col"
            class="py-2 pr-3"
          >
            Links
          </th>
          <th
            scope="col"
            class="py-2"
          >
            <span class="sr-only">Actions</span>
          </th>
        </tr>
      </thead>
      <tbody>
        <tr
          v-for="entry in entries"
          :key="entry.id"
          class="border-b align-top"
        >
          <td class="py-2 pr-3 font-medium text-heading">
            {{ entry.invoice_id }}
          </td>
          <td class="py-2 pr-3">
            {{ entry.year ?? '' }}
          </td>
          <td class="py-2 pr-3">
            {{ entry.body ?? '' }}
          </td>
          <td class="py-2 pr-3">
            {{ entry.model ?? '' }}
          </td>
          <td class="py-2 pr-3">
            {{ entry.judging_type }}
          </td>
          <td class="py-2 pr-3">
            {{ entry.num_answered }}/{{ entry.num_questions }}
            <span
              v-if="entry.num_answered < entry.num_questions"
              class="text-muted"
            >({{ entry.num_questions - entry.num_answered }} missing)</span>
          </td>
          <td class="py-2 pr-3">
            {{ entry.score ?? '—' }}
          </td>
          <td class="py-2 pr-3">
            {{ entry.owner_name }}
            <br>
            <a
              :href="`mailto:${entry.owner_email}`"
              class="text-link underline"
            >{{ entry.owner_email }}</a>
          </td>
          <td class="py-2 pr-3">
            <div class="flex flex-wrap gap-2">
              <a
                :href="entry.judging_url"
                target="_blank"
                rel="noopener"
                class="text-link underline"
                :aria-label="`Questions for car ${entry.invoice_id} (opens in a new tab)`"
              >Questions</a>
              <a
                :href="entry.answers_url"
                target="_blank"
                rel="noopener"
                class="text-link underline"
                :aria-label="`Answers for car ${entry.invoice_id} (opens in a new tab)`"
              >Answers</a>
              <a
                :href="entry.qr_url"
                target="_blank"
                rel="noopener"
                class="text-link underline"
                :aria-label="`QR code for car ${entry.invoice_id} (opens in a new tab)`"
              >QR</a>
            </div>
          </td>
          <td class="py-2">
            <BaseButton
              size="sm"
              variant="danger"
              :aria-label="`Remove car ${entry.invoice_id} from judging`"
              @click="emit('remove', entry)"
            >
              Remove
            </BaseButton>
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</template>
