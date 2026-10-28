<script setup lang="ts">
/**
 * One member in the directory grid. Guests see first name + last initial and state only (the API masks it);
 * the photo falls back to the legacy generic avatar when missing or broken.
 */
import { computed, ref } from 'vue';
import { RouterLink } from 'vue-router';
import type { MemberRow } from '../../api/members';
import { GENERIC_AVATAR_URL } from '../../api/members';
import SafeImage from '../ui/SafeImage.vue';

const props = defineProps<{ member: MemberRow }>();

const imageFailed = ref(false);
const imageSrc = computed(() =>
  imageFailed.value || props.member.imageUrl === '' ? GENERIC_AVATAR_URL : props.member.imageUrl,
);

function onImageError(): void {
  imageFailed.value = true;
}
</script>

<template>
  <li>
    <article class="flex h-full flex-col overflow-hidden rounded-md border border-border bg-panel shadow-card">
      <RouterLink
        v-if="member.profilePath"
        :to="member.profilePath"
        tabindex="-1"
        aria-hidden="true"
        class="block"
      >
        <SafeImage
          :src="imageSrc"
          resize="directoryCard"
          :alt="member.fullName"
          img-class="aspect-square w-full object-cover"
          @error="onImageError"
        />
      </RouterLink>
      <SafeImage
        v-else
        :src="imageSrc"
        resize="directoryCard"
        :alt="member.fullName"
        img-class="aspect-square w-full object-cover"
        @error="onImageError"
      />
      <div class="flex flex-1 flex-col gap-1 p-3">
        <h2 class="text-base font-semibold text-heading">
          <RouterLink
            v-if="member.profilePath"
            :to="member.profilePath"
            class="text-heading no-underline hover:text-brand hover:underline"
          >
            {{ member.fullName }}
          </RouterLink>
          <template v-else>
            {{ member.fullName }}
          </template>
        </h2>
        <p
          v-if="member.cityState"
          class="text-sm text-muted"
        >
          {{ member.cityState }}
        </p>
      </div>
    </article>
  </li>
</template>
