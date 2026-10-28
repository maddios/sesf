<script setup lang="ts">
/**
 * WYSIWYG editor for admin HTML fields (Tiptap). v-model is the stored HTML string.
 *
 * - Visual mode: bold, italic, link, lists, alignment, font, size and colour. Markup the editor cannot represent
 *   (tables, <center>, images, embeds, legacy attributes) is shown as a read-only preview and kept unchanged.
 * - Raw mode: the HTML in a monospace textarea.
 * - The value is only emitted after an edit, so opening and closing a form never rewrites legacy text.
 * - `maxlength` shows a counter of the HTML length (the column width counts the markup); going over shows an
 *   error but never cuts the text.
 */
import { computed, onBeforeUnmount, ref, useId, watch } from 'vue';
import { EditorContent, useEditor } from '@tiptap/vue-3';
import BaseCombobox, { type ComboboxValue } from '../ui/BaseCombobox.vue';
import AdminIcon, { type AdminIconName } from './AdminIcon.vue';
import BooleanToggle from './BooleanToggle.vue';
import { editorExtensions } from './editorExtensions';
import { EDITOR_FONTS, EDITOR_FONT_SIZES, editorHtmlToValue, prepareHtmlForEditor } from './richText';

defineOptions({ inheritAttrs: false });

const props = withDefaults(
  defineProps<{
    modelValue: string;
    label: string;
    id?: string;
    maxlength?: number;
    rows?: number;
    ariaDescribedby?: string;
    ariaInvalid?: boolean;
  }>(),
  { id: undefined, maxlength: undefined, rows: 6, ariaDescribedby: undefined, ariaInvalid: false },
);

const emit = defineEmits<{ 'update:modelValue': [value: string] }>();

const uid = useId();
const helpId = `${uid}-help`;
const counterId = `${uid}-counter`;
const visual = ref(true);
let lastEmitted = props.modelValue;

const length = computed(() => props.modelValue.length);
const over = computed(() => props.maxlength !== undefined && length.value > props.maxlength);
const describedBy = computed(() => [props.ariaDescribedby, visual.value ? helpId : '', props.maxlength ? counterId : ''].filter(Boolean).join(' ') || undefined);

const editor = useEditor({
  content: prepareHtmlForEditor(props.modelValue),
  extensions: editorExtensions(),
  onUpdate: ({ editor: current }) => setValue(editorHtmlToValue(current.getHTML())),
});

function editorAttributes(): Record<string, string> {
  const attributes: Record<string, string> = {
    class: 'rte-content event-html',
    role: 'textbox',
    'aria-multiline': 'true',
    'aria-label': props.label,
    'aria-invalid': props.ariaInvalid || over.value ? 'true' : 'false',
  };
  if (props.id) attributes.id = props.id;
  if (describedBy.value) attributes['aria-describedby'] = describedBy.value;
  return attributes;
}

watch(
  [editor, describedBy, over, () => props.ariaInvalid, () => props.label, () => props.id],
  () => editor.value?.setOptions({ editorProps: { attributes: editorAttributes() } }),
  { immediate: true },
);

watch(
  () => props.modelValue,
  (value) => {
    if (value !== lastEmitted) loadIntoEditor(value);
  },
);

onBeforeUnmount(() => editor.value?.destroy());

function setValue(value: string): void {
  lastEmitted = value;
  emit('update:modelValue', value);
}

function loadIntoEditor(value: string): void {
  lastEmitted = value;
  editor.value?.commands.setContent(prepareHtmlForEditor(value), { emitUpdate: false });
}

function setVisual(on: boolean): void {
  if (on) loadIntoEditor(props.modelValue);
  visual.value = on;
}

interface ToolbarButton {
  key: string;
  label: string;
  icon: AdminIconName;
  active: () => boolean;
  run: () => void;
}

function chain() {
  if (!editor.value) throw new Error('Editor is not ready');
  return editor.value.chain().focus();
}

function setLink(): void {
  const previous = editor.value?.getAttributes('link').href as string | undefined;
  const url = window.prompt('Link address (https://…, mailto:… or /path)', previous ?? 'https://');
  if (url === null) return;
  if (url.trim() === '') {
    chain().extendMarkRange('link').unsetLink().run();
    return;
  }
  chain().extendMarkRange('link').setLink({ href: url.trim() }).run();
}

const isActive = (name: string): boolean => editor.value?.isActive(name) ?? false;
const alignActive = (align: string): boolean => editor.value?.isActive({ textAlign: align }) ?? false;

const formatButtons: ToolbarButton[] = [
  { key: 'bold', label: 'Bold', icon: 'bold', active: () => isActive('bold'), run: () => chain().toggleBold().run() },
  { key: 'italic', label: 'Italic', icon: 'italic', active: () => isActive('italic'), run: () => chain().toggleItalic().run() },
  { key: 'link', label: 'Link', icon: 'link', active: () => isActive('link'), run: setLink },
  { key: 'bullet', label: 'Bullet list', icon: 'list', active: () => isActive('bulletList'), run: () => chain().toggleBulletList().run() },
  { key: 'ordered', label: 'Numbered list', icon: 'list-ordered', active: () => isActive('orderedList'), run: () => chain().toggleOrderedList().run() },
];

const alignButtons: ToolbarButton[] = (['left', 'center', 'right', 'justify'] as const).map((align) => ({
  key: align,
  label: align === 'justify' ? 'Justify' : `Align ${align}`,
  icon: `align-${align}` as AdminIconName,
  active: () => alignActive(align),
  run: () => chain().setTextAlign(align).run(),
}));

const fontOptions = [{ value: 'default', label: 'Site default' }, ...EDITOR_FONTS.map((font) => ({ value: font.id, label: font.label }))];
const sizeOptions = [{ value: 'default', label: 'Default size' }, ...EDITOR_FONT_SIZES.map((size) => ({ value: size.id, label: size.label }))];

function textStyle(): Record<string, unknown> {
  return editor.value?.getAttributes('textStyle') ?? {};
}

const fontKey = (stack: unknown): string => (typeof stack === 'string' ? stack.replace(/["']/g, '').replace(/\s*,\s*/g, ',').toLowerCase() : '');
const currentFont = computed(() => EDITOR_FONTS.find((font) => fontKey(font.stack) === fontKey(textStyle().fontFamily))?.id ?? 'default');
const currentSize = computed(() => EDITOR_FONT_SIZES.find((size) => size.css === textStyle().fontSize)?.id ?? 'default');
const currentColor = computed(() => toHex(textStyle().color));

function setFont(value: ComboboxValue | null): void {
  const font = EDITOR_FONTS.find((entry) => entry.id === value);
  if (font) chain().setFontFamily(font.stack).run();
  else chain().unsetFontFamily().run();
}

function setSize(value: ComboboxValue | null): void {
  const size = EDITOR_FONT_SIZES.find((entry) => entry.id === value);
  if (size) chain().setFontSize(size.css).run();
  else chain().unsetFontSize().run();
}

function setColor(event: Event): void {
  chain().setColor((event.target as HTMLInputElement).value).run();
}

function toHex(value: unknown): string {
  if (typeof value !== 'string') return '#333333';
  if (/^#[0-9a-f]{6}$/i.test(value)) return value.toLowerCase();
  if (/^#[0-9a-f]{3}$/i.test(value)) return `#${value.slice(1).split('').map((c) => c + c).join('')}`.toLowerCase();
  const rgb = /^rgba?\((\d+),\s*(\d+),\s*(\d+)/.exec(value);
  return rgb ? `#${rgb.slice(1, 4).map((n) => Number(n).toString(16).padStart(2, '0')).join('')}` : '#333333';
}

const format = (n: number): string => n.toLocaleString('en-US');
</script>

<template>
  <div
    class="rte"
    :class="{ 'is-invalid': ariaInvalid || over }"
  >
    <div class="rte-modebar">
      <span
        aria-hidden="true"
        class="rte-mode-text"
      >Raw</span>
      <BooleanToggle
        :model-value="visual"
        label="Visual editor"
        hide-label
        @update:model-value="setVisual"
      />
      <span
        aria-hidden="true"
        class="rte-mode-text"
      >Visual</span>
    </div>

    <div v-show="visual">
      <div
        class="rte-toolbar"
        role="toolbar"
        :aria-label="`${label} formatting`"
      >
        <button
          v-for="button in formatButtons"
          :key="button.key"
          type="button"
          class="a2-icon-btn rte-btn"
          :aria-label="button.label"
          :title="button.label"
          :aria-pressed="button.active() ? 'true' : 'false'"
          @mousedown.prevent
          @click="button.run"
        >
          <AdminIcon :name="button.icon" />
        </button>
        <span
          class="rte-divider"
          aria-hidden="true"
        />
        <button
          v-for="button in alignButtons"
          :key="button.key"
          type="button"
          class="a2-icon-btn rte-btn"
          :aria-label="button.label"
          :title="button.label"
          :aria-pressed="button.active() ? 'true' : 'false'"
          @mousedown.prevent
          @click="button.run"
        >
          <AdminIcon :name="button.icon" />
        </button>
        <span
          class="rte-divider"
          aria-hidden="true"
        />
        <BaseCombobox
          class="rte-select rte-select-font"
          :model-value="currentFont"
          :options="fontOptions"
          aria-label="Font"
          @update:model-value="setFont"
        />
        <BaseCombobox
          class="rte-select rte-select-size"
          :model-value="currentSize"
          :options="sizeOptions"
          aria-label="Size"
          @update:model-value="setSize"
        />
        <input
          type="color"
          class="rte-color"
          aria-label="Text colour"
          title="Text colour"
          :value="currentColor"
          @input="setColor"
        >
      </div>
      <EditorContent :editor="editor" />
      <p
        :id="helpId"
        class="rte-help"
      >
        Select words or a sentence to change font, size, or color. Alignment applies to the current paragraph.
      </p>
    </div>

    <textarea
      v-if="!visual"
      :id="id"
      class="form-control rte-raw"
      :rows="rows"
      :value="modelValue"
      :aria-label="`${label} (HTML)`"
      :aria-describedby="describedBy"
      :aria-invalid="ariaInvalid || over ? 'true' : 'false'"
      spellcheck="false"
      placeholder="<p>Your text…</p>"
      @input="setValue(($event.target as HTMLTextAreaElement).value)"
    />

    <div
      v-if="maxlength !== undefined"
      :id="counterId"
      class="rte-counter"
      :class="{ 'is-over': over }"
    >
      <span>{{ format(length) }} / {{ format(maxlength) }} characters, formatting included</span>
      <span
        v-if="over"
        role="alert"
        class="rte-counter-error"
      >Too long by {{ format(length - maxlength) }} characters. The formatting (HTML) counts too: remove some text or formatting before saving.</span>
    </div>
  </div>
</template>
