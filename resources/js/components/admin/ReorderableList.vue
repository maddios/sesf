<script setup lang="ts" generic="T extends { id: number | string }">
/**
 * Reorderable list: drag a row with the mouse, or use its Move up / Move down buttons (keyboard). Alt+ArrowUp and
 * Alt+ArrowDown on a focused row's buttons move it too. Emits `reorder` with the new id order (the parent saves
 * it); the list shows the new order at once and announces the move in a polite live region. The row content is
 * the default slot ({ item, index }); `itemLabel` names rows for the buttons and announcements.
 */
import { nextTick, ref, watch } from 'vue';

const props = withDefaults(
  defineProps<{
    items: T[];
    itemLabel: (item: T) => string;
    label: string;
    disabled?: boolean;
  }>(),
  { disabled: false },
);

const emit = defineEmits<{ reorder: [ids: Array<T['id']>] }>();

const order = ref<T[]>([...props.items]) as { value: T[] };
const announcement = ref('');
const dragIndex = ref<number | null>(null);
const overIndex = ref<number | null>(null);
const list = ref<HTMLElement | null>(null);

watch(
  () => props.items,
  (items) => {
    order.value = [...items];
  },
);

function moveTo(from: number, to: number, focus: 'up' | 'down' | null = null): void {
  if (props.disabled || from === to || to < 0 || to >= order.value.length) return;
  const next = [...order.value];
  const [item] = next.splice(from, 1) as [T];
  next.splice(to, 0, item);
  order.value = next;
  announcement.value = `${props.itemLabel(item)} moved to position ${to + 1} of ${next.length}.`;
  emit(
    'reorder',
    next.map((row) => row.id),
  );
  if (focus !== null) {
    void nextTick(() => {
      const rows = list.value?.querySelectorAll<HTMLElement>('li[data-row]');
      const row = rows?.[to];
      const wanted = row?.querySelector<HTMLButtonElement>(`button[data-move="${focus}"]:not([disabled])`)
        ?? row?.querySelector<HTMLButtonElement>('button[data-move]:not([disabled])');
      wanted?.focus();
    });
  }
}

function onKeydown(event: KeyboardEvent, index: number): void {
  if (!event.altKey) return;
  if (event.key === 'ArrowUp') {
    event.preventDefault();
    moveTo(index, index - 1, 'up');
  } else if (event.key === 'ArrowDown') {
    event.preventDefault();
    moveTo(index, index + 1, 'down');
  }
}

function onDragStart(event: DragEvent, index: number): void {
  dragIndex.value = index;
  event.dataTransfer?.setData('text/plain', String(index));
  if (event.dataTransfer) event.dataTransfer.effectAllowed = 'move';
}

function onDragOver(event: DragEvent, index: number): void {
  if (dragIndex.value === null) return;
  event.preventDefault();
  overIndex.value = index;
}

function onDrop(event: DragEvent, index: number): void {
  event.preventDefault();
  if (dragIndex.value !== null) moveTo(dragIndex.value, index);
  dragIndex.value = null;
  overIndex.value = null;
}

function onDragEnd(): void {
  dragIndex.value = null;
  overIndex.value = null;
}
</script>

<template>
  <div>
    <ul
      ref="list"
      :aria-label="label"
      class="divide-y divide-border rounded-sm border border-border bg-panel"
    >
      <li
        v-for="(item, index) in order"
        :key="item.id"
        data-row
        :draggable="!disabled"
        class="flex items-center gap-3 px-3 py-2"
        :class="[overIndex === index ? 'bg-brand-tint' : '', dragIndex === index ? 'opacity-50' : '']"
        @dragstart="onDragStart($event, index)"
        @dragover="onDragOver($event, index)"
        @drop="onDrop($event, index)"
        @dragend="onDragEnd"
      >
        <span
          aria-hidden="true"
          class="cursor-grab select-none text-muted"
        >⋮⋮</span>
        <div class="min-w-0 flex-1">
          <slot
            :item="item"
            :index="index"
          >
            {{ itemLabel(item) }}
          </slot>
        </div>
        <div class="flex gap-1">
          <button
            type="button"
            data-move="up"
            class="rounded-sm border border-default-border bg-default px-2 py-0.5 text-sm hover:bg-default-hover disabled:opacity-40"
            :aria-label="`Move ${itemLabel(item)} up`"
            :disabled="disabled || index === 0"
            @click="moveTo(index, index - 1, 'up')"
            @keydown="onKeydown($event, index)"
          >
            <span aria-hidden="true">↑</span>
          </button>
          <button
            type="button"
            data-move="down"
            class="rounded-sm border border-default-border bg-default px-2 py-0.5 text-sm hover:bg-default-hover disabled:opacity-40"
            :aria-label="`Move ${itemLabel(item)} down`"
            :disabled="disabled || index === order.length - 1"
            @click="moveTo(index, index + 1, 'down')"
            @keydown="onKeydown($event, index)"
          >
            <span aria-hidden="true">↓</span>
          </button>
        </div>
      </li>
    </ul>
    <p
      class="sr-only"
      aria-live="polite"
      role="status"
    >
      {{ announcement }}
    </p>
  </div>
</template>
