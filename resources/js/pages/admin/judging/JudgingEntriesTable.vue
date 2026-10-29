<script setup lang="ts">
/**
 * The legacy judging list table: car #, year, body, model (car page link), judging type with answered/missing
 * counts, score, owner, email, the Questions · Answers · QR forms and Remove. Below 768px each row is a card laid
 * out like the legacy mobile list (big car number, Remove top right, indigo judging-type box, form buttons).
 */
import type { JudgingEntry } from './judging';

defineProps<{
  entries: JudgingEntry[];
}>();

const emit = defineEmits<{
  remove: [entry: JudgingEntry];
}>();
</script>

<template>
  <div class="a2-scroll">
    <table class="a2-table a2-cards a2-cards--pair a2-judging">
      <caption class="sr-only">
        Judging entries for the selected event
      </caption>
      <thead>
        <tr>
          <th scope="col">
            Car #
          </th>
          <th scope="col">
            Year
          </th>
          <th scope="col">
            Body
          </th>
          <th scope="col">
            Model
          </th>
          <th scope="col">
            Judging type
          </th>
          <th scope="col">
            Score
          </th>
          <th scope="col">
            Owner
          </th>
          <th scope="col">
            Email
          </th>
          <th scope="col">
            Forms
          </th>
          <th scope="col">
            <span class="sr-only">Remove</span>
          </th>
        </tr>
      </thead>
      <tbody>
        <tr
          v-for="entry in entries"
          :key="entry.id"
        >
          <td
            data-label="Car #"
            class="a2-cell-big"
          >
            {{ entry.invoice_id }}
          </td>
          <td
            data-label="Year"
            class="a2-cell-third"
          >
            {{ entry.year ?? '' }}
          </td>
          <td
            data-label="Body"
            class="a2-cell-third"
          >
            {{ entry.body ?? '' }}
          </td>
          <td
            data-label="Model"
            class="a2-cell-third"
          >
            <a :href="`#/car/${entry.car_id}`">{{ entry.model ?? '' }}</a>
          </td>
          <td
            data-label="Judging type"
            class="a2-judging-type"
          >
            {{ entry.judging_type }}
            <span
              v-if="entry.num_questions > 0"
              class="a2-qstats"
            >
              <strong>{{ entry.num_answered }}/{{ entry.num_questions }}</strong> answered
              <span
                v-if="entry.num_answered < entry.num_questions"
                class="a2-incomplete"
              >({{ entry.num_questions - entry.num_answered }} missing)</span>
            </span>
            <span class="a2-qstats a2-mobile-only">Score: <strong>{{ entry.score ?? '—' }}</strong></span>
          </td>
          <td
            data-label="Score"
            class="a2-desktop-only"
          >
            {{ entry.score ?? '—' }}
          </td>
          <td
            data-label="Owner"
            class="a2-cell-owner"
          >
            {{ entry.owner_name }}
          </td>
          <td
            data-label="Email"
            class="a2-cell-email"
          >
            <a
              v-if="entry.owner_email !== null"
              :href="`mailto:${entry.owner_email}?subject=SESF Judging`"
            >{{ entry.owner_email }}</a>
          </td>
          <td
            data-label="Forms"
            class="a2-cell-sep"
          >
            <span class="a2-form-links">
              <a
                :href="entry.judging_url"
                target="_blank"
                rel="noopener"
                :aria-label="`Questions for car ${entry.invoice_id} (opens in a new tab)`"
              >Questions</a><span
                class="a2-sep"
                aria-hidden="true"
              > · </span><a
                :href="entry.answers_url"
                target="_blank"
                rel="noopener"
                :aria-label="`Answers for car ${entry.invoice_id} (opens in a new tab)`"
              >Answers</a><span
                class="a2-sep"
                aria-hidden="true"
              > · </span><a
                :href="entry.qr_url"
                target="_blank"
                rel="noopener"
                :aria-label="`QR code for car ${entry.invoice_id} (opens in a new tab)`"
              >QR</a>
            </span>
          </td>
          <td class="a2-cell-corner a2-cell-nolabel">
            <button
              type="button"
              class="a2-btn a2-btn-small"
              :aria-label="`Remove car ${entry.invoice_id} from judging`"
              @click="emit('remove', entry)"
            >
              Remove
            </button>
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</template>
