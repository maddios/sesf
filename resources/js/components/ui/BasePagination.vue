<script setup lang="ts">
/**
 * Page navigation like the legacy `paginate` directive (ui-bootstrap, max 5 pages, no rotation):
 * First, Previous, a block of page numbers with "..." jumps to the neighbouring blocks, Next, Last.
 * Selecting a page writes ?page= into the hash query (other query keys kept) and emits update:page.
 * Set `syncQuery` false to only emit.
 */
import { computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';

const props = withDefaults(
  defineProps<{
    page: number;
    totalPages: number;
    maxSize?: number;
    syncQuery?: boolean;
    label?: string;
  }>(),
  { maxSize: 5, syncQuery: true, label: 'Pagination' },
);

const emit = defineEmits<{ 'update:page': [page: number] }>();

const route = useRoute();
const router = useRouter();

interface PageItem {
  key: string;
  page: number;
  text: string;
  ariaLabel: string;
  current: boolean;
}

const current = computed(() => Math.min(Math.max(1, Math.trunc(props.page) || 1), Math.max(1, props.totalPages)));

const numbers = computed<PageItem[]>(() => {
  const size = Math.max(1, props.maxSize);
  const total = props.totalPages;
  const blockStart = Math.floor((current.value - 1) / size) * size + 1;
  const blockEnd = Math.min(total, blockStart + size - 1);
  const items: PageItem[] = [];
  if (blockStart > 1) {
    items.push({ key: 'prev-block', page: blockStart - 1, text: '...', ariaLabel: `Pages before ${blockStart}`, current: false });
  }
  for (let n = blockStart; n <= blockEnd; n += 1) {
    items.push({ key: `p${n}`, page: n, text: String(n), ariaLabel: `Page ${n}`, current: n === current.value });
  }
  if (blockEnd < total) {
    items.push({ key: 'next-block', page: blockEnd + 1, text: '...', ariaLabel: `Pages after ${blockEnd}`, current: false });
  }
  return items;
});

const isFirst = computed(() => current.value <= 1);
const isLast = computed(() => current.value >= props.totalPages);

async function go(page: number): Promise<void> {
  const target = Math.min(Math.max(1, page), props.totalPages);
  if (target === current.value) {
    return;
  }
  emit('update:page', target);
  if (props.syncQuery) {
    await router.push({ path: route.path, query: { ...route.query, page: String(target) } });
  }
}

const base = 'inline-flex min-w-9 items-center justify-center border border-border px-2.5 py-1 text-[0.8125rem] no-underline';
const enabled = 'bg-panel text-brand hover:bg-subtle hover:text-brand-dark';
const disabledCls = 'cursor-not-allowed bg-panel text-muted';
</script>

<template>
  <nav
    v-if="totalPages > 1"
    :aria-label="label"
  >
    <ul class="flex flex-wrap items-center [&>li+li]:-ml-px [&>li:first-child>button]:rounded-l-sm [&>li:last-child>button]:rounded-r-sm">
      <li>
        <button
          type="button"
          :class="[base, isFirst ? disabledCls : enabled]"
          :disabled="isFirst"
          @click="go(1)"
        >
          First
        </button>
      </li>
      <li>
        <button
          type="button"
          :class="[base, isFirst ? disabledCls : enabled]"
          :disabled="isFirst"
          @click="go(current - 1)"
        >
          Previous
        </button>
      </li>
      <li
        v-for="item in numbers"
        :key="item.key"
      >
        <button
          type="button"
          :class="[base, item.current ? 'z-10 border-brand bg-brand font-semibold text-white' : enabled]"
          :aria-label="item.ariaLabel"
          :aria-current="item.current ? 'page' : undefined"
          @click="go(item.page)"
        >
          {{ item.text }}
        </button>
      </li>
      <li>
        <button
          type="button"
          :class="[base, isLast ? disabledCls : enabled]"
          :disabled="isLast"
          @click="go(current + 1)"
        >
          Next
        </button>
      </li>
      <li>
        <button
          type="button"
          :class="[base, isLast ? disabledCls : enabled]"
          :disabled="isLast"
          @click="go(totalPages)"
        >
          Last
        </button>
      </li>
    </ul>
  </nav>
</template>
