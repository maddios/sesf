<script setup lang="ts">
/**
 * Renders every page of a PDF to canvases sized to the container (legacy pdfRender directive), so it also works
 * on mobile browsers that cannot embed PDFs. pdf.js is loaded only when this component mounts. Pages re-render
 * when the container width changes; a failed load shows a message (the caller keeps the download link).
 */
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { loadPdfjs, type PdfDocument } from './pdfjsLoader';

const props = defineProps<{ url: string; title: string }>();

const MAX_DPR = 2;
const RESIZE_DEBOUNCE_MS = 200;

type State = 'loading' | 'ready' | 'error';

const state = ref<State>('loading');
const container = ref<HTMLElement | null>(null);
let pdf: PdfDocument | null = null;
let token = 0;
let renderedWidth = 0;
let resizeTimer: number | undefined;
let observer: ResizeObserver | null = null;

async function renderPage(document: PdfDocument, number: number, element: HTMLElement, width: number): Promise<void> {
  const page = await document.getPage(number);
  const dpr = Math.min(window.devicePixelRatio || 1, MAX_DPR);
  const base = page.getViewport({ scale: 1 });
  const viewport = page.getViewport({ scale: (width / base.width) * dpr });
  const canvas = window.document.createElement('canvas');
  canvas.width = viewport.width;
  canvas.height = viewport.height;
  canvas.className = 'mb-2.5 block h-auto max-w-full';
  canvas.style.width = `${viewport.width / dpr}px`;
  canvas.setAttribute('role', 'img');
  canvas.setAttribute('aria-label', `${props.title}, page ${number} of ${document.numPages}`);
  element.append(canvas);
  await page.render({ canvas, viewport }).promise;
}

async function renderAll(): Promise<void> {
  const element = container.value;
  if (pdf === null || element === null) {
    return;
  }
  token += 1;
  const current = token;
  renderedWidth = element.clientWidth;
  element.replaceChildren();
  if (renderedWidth === 0) {
    return;
  }
  try {
    for (let number = 1; number <= pdf.numPages && current === token; number += 1) {
      await renderPage(pdf, number, element, renderedWidth);
    }
  } catch {
    state.value = 'error';
  }
}

function onResize(): void {
  window.clearTimeout(resizeTimer);
  resizeTimer = window.setTimeout(() => {
    if (container.value !== null && container.value.clientWidth !== renderedWidth) {
      void renderAll();
    }
  }, RESIZE_DEBOUNCE_MS);
}

onMounted(async () => {
  try {
    const loader = await loadPdfjs();
    pdf = await loader.open(props.url);
    state.value = 'ready';
    await renderAll();
    if (container.value !== null && typeof ResizeObserver !== 'undefined') {
      observer = new ResizeObserver(onResize);
      observer.observe(container.value);
    }
  } catch {
    state.value = 'error';
  }
});

onBeforeUnmount(() => {
  token += 1;
  window.clearTimeout(resizeTimer);
  observer?.disconnect();
  void pdf?.destroy();
});
</script>

<template>
  <div>
    <p
      v-if="state === 'loading'"
      role="status"
      class="py-4 text-muted"
    >
      Loading schedule
    </p>
    <p
      v-else-if="state === 'error'"
      role="alert"
      class="py-4 text-muted"
    >
      The schedule could not be shown here. Please use the download link below.
    </p>
    <div
      ref="container"
      class="w-full"
    />
  </div>
</template>
