<script setup lang="ts">
/**
 * Row actions for admin lists: one labelled button for the main action (the same one a row click runs, e.g. "Edit")
 * and an "Actions" menu button holding every other action as icon + text.
 * - Desktop: a popover teleported to body (tables and dialogs cannot clip it), anchored to the button.
 * - Phones (< 768px): a bottom sheet with large rows.
 * - ARIA menu pattern: Enter/Space/ArrowDown open on the first item, ArrowUp on the last; Up/Down/Home/End move;
 *   Escape or a choice closes and returns focus to the button; Tab or a click outside closes.
 * - Danger items go last behind a separator. A disabled item stays focusable (aria-disabled) and says why: a tooltip
 *   on desktop, a visible line in the sheet (and aria-describedby for both).
 * Accessible names: the main button is "<label> <subject>" (e.g. "Edit Spring Show"), the menu button
 * "Actions for <subject>", menu items their visible label.
 */
import { computed, nextTick, onBeforeUnmount, ref, useId } from 'vue';
import { RouterLink } from 'vue-router';
import AdminIcon from './AdminIcon.vue';
import type { RowAction } from './types';

const props = withDefaults(
  defineProps<{
    /** What the row is, e.g. "Spring Show" or "car 7". */
    subject: string;
    actions: RowAction[];
    primary?: RowAction | null;
  }>(),
  { primary: null },
);

const PHONE_MAX_WIDTH = 767;

const open = ref(false);
const sheet = ref(false);
const trigger = ref<HTMLButtonElement | null>(null);
const panel = ref<HTMLElement | null>(null);
const position = ref<{ top: string; left: string }>({ top: '0px', left: '0px' });
const menuId = useId();
const triggerId = useId();

const ordered = computed(() => [...props.actions.filter((action) => action.danger !== true), ...props.actions.filter((action) => action.danger === true)]);
const firstDangerIndex = computed(() => ordered.value.findIndex((action) => action.danger === true));

function partId(part: 'label' | 'hint', index: number): string {
  return `${menuId}-${part}-${index}`;
}

function menuItems(): HTMLElement[] {
  return Array.from(panel.value?.querySelectorAll<HTMLElement>('[role="menuitem"]') ?? []);
}

function place(): void {
  if (sheet.value || trigger.value === null || panel.value === null) return;
  const rect = trigger.value.getBoundingClientRect();
  const { offsetWidth: width, offsetHeight: height } = panel.value;
  const fitsBelow = window.innerHeight - rect.bottom >= height + 8;
  const top = fitsBelow || rect.top < height + 8 ? rect.bottom + 4 : rect.top - height - 4;
  const left = Math.max(8, Math.min(rect.right - width, window.innerWidth - width - 8));
  position.value = { top: `${top}px`, left: `${left}px` };
}

function onOutsidePointer(event: Event): void {
  const target = event.target as Node;
  if (panel.value?.contains(target) || trigger.value?.contains(target)) return;
  if (sheet.value) return;
  closeMenu(false);
}

function listen(on: boolean): void {
  const method = on ? 'addEventListener' : 'removeEventListener';
  document[method]('pointerdown', onOutsidePointer, true);
  window[method]('scroll', place, true);
  window[method]('resize', place);
}

async function openMenu(focus: 'first' | 'last'): Promise<void> {
  sheet.value = window.innerWidth <= PHONE_MAX_WIDTH;
  open.value = true;
  listen(true);
  await nextTick();
  place();
  const items = menuItems();
  (focus === 'first' ? items[0] : items[items.length - 1])?.focus();
}

function closeMenu(returnFocus: boolean): void {
  if (!open.value) return;
  open.value = false;
  listen(false);
  if (returnFocus) trigger.value?.focus();
}

onBeforeUnmount(() => closeMenu(false));

function onTriggerClick(): void {
  if (open.value) {
    closeMenu(false);
  } else {
    void openMenu('first');
  }
}

function onTriggerKeydown(event: KeyboardEvent): void {
  if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
    event.preventDefault();
    void openMenu(event.key === 'ArrowDown' ? 'first' : 'last');
  }
}

function onMenuKeydown(event: KeyboardEvent): void {
  const items = menuItems();
  const index = items.indexOf(document.activeElement as HTMLElement);
  const moves: Record<string, number> = {
    ArrowDown: (index + 1) % items.length,
    ArrowUp: (index - 1 + items.length) % items.length,
    Home: 0,
    End: items.length - 1,
  };
  if (event.key in moves) {
    event.preventDefault();
    items[moves[event.key] as number]?.focus();
  } else if (event.key === 'Escape') {
    event.preventDefault();
    closeMenu(true);
  } else if (event.key === 'Tab') {
    closeMenu(false);
  }
}

function choose(action: RowAction): void {
  if (action.disabled === true) return;
  closeMenu(true);
  action.handler?.();
}

function runPrimary(): void {
  props.primary?.handler?.();
}

function isButton(action: RowAction): boolean {
  return action.disabled === true || action.handler !== undefined;
}
</script>

<template>
  <span class="admin-row-actions">
    <template v-if="primary !== null">
      <RouterLink
        v-if="primary.to !== undefined"
        :to="primary.to"
        class="a2-btn a2-btn-small admin-row-primary"
        :aria-label="`${primary.label} ${subject}`"
      >
        <AdminIcon :name="primary.icon" />
        {{ primary.label }}
      </RouterLink>
      <button
        v-else
        type="button"
        class="a2-btn a2-btn-small admin-row-primary"
        :aria-label="`${primary.label} ${subject}`"
        :disabled="primary.disabled"
        :title="primary.disabled ? primary.hint : undefined"
        @click="runPrimary"
      >
        <AdminIcon :name="primary.icon" />
        {{ primary.label }}
      </button>
    </template>
    <button
      v-if="ordered.length > 0"
      :id="triggerId"
      ref="trigger"
      type="button"
      class="a2-btn a2-btn-small admin-row-more"
      aria-haspopup="menu"
      :aria-expanded="open ? 'true' : 'false'"
      :aria-controls="open ? menuId : undefined"
      :aria-label="`Actions for ${subject}`"
      @click="onTriggerClick"
      @keydown="onTriggerKeydown"
    >
      <AdminIcon name="more" />
      Actions
    </button>

    <Teleport
      v-if="open"
      to="body"
    >
      <div
        v-if="sheet"
        class="admin-actions-backdrop"
        aria-hidden="true"
        @click="closeMenu(true)"
      />
      <div
        ref="panel"
        :class="sheet ? 'admin-actions-sheet' : 'admin-actions-popover'"
        :style="sheet ? undefined : position"
        @keydown="onMenuKeydown"
      >
        <p
          v-if="sheet"
          class="admin-actions-sheet-title"
          aria-hidden="true"
        >
          {{ subject }}
        </p>
        <ul
          :id="menuId"
          role="menu"
          :aria-label="`Actions for ${subject}`"
          class="admin-actions-menu"
        >
          <template
            v-for="(action, index) in ordered"
            :key="action.id"
          >
            <li
              v-if="index === firstDangerIndex && index > 0"
              role="separator"
              class="admin-actions-sep"
            />
            <li role="none">
              <button
                v-if="isButton(action)"
                type="button"
                role="menuitem"
                tabindex="-1"
                class="admin-actions-item"
                :class="action.danger ? 'is-danger' : ''"
                :aria-disabled="action.disabled ? 'true' : undefined"
                :aria-labelledby="partId('label', index)"
                :aria-describedby="action.hint ? partId('hint', index) : undefined"
                :title="!sheet ? action.hint : undefined"
                @click="choose(action)"
              >
                <AdminIcon :name="action.icon" />
                <span class="admin-actions-text">
                  <span :id="partId('label', index)">{{ action.label }}</span>
                  <span
                    v-if="action.hint"
                    :id="partId('hint', index)"
                    class="admin-actions-hint"
                  >{{ action.hint }}</span>
                </span>
              </button>
              <RouterLink
                v-else-if="action.to !== undefined"
                :to="action.to"
                role="menuitem"
                tabindex="-1"
                class="admin-actions-item"
                @click="closeMenu(false)"
              >
                <AdminIcon :name="action.icon" />
                <span class="admin-actions-text"><span>{{ action.label }}</span></span>
              </RouterLink>
              <a
                v-else
                :href="action.href"
                role="menuitem"
                tabindex="-1"
                class="admin-actions-item"
                @click="closeMenu(false)"
              >
                <AdminIcon :name="action.icon" />
                <span class="admin-actions-text"><span>{{ action.label }}</span></span>
              </a>
            </li>
          </template>
        </ul>
      </div>
    </Teleport>
  </span>
</template>
