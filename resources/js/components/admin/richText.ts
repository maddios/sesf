/**
 * HTML helpers for RichTextEditor. Stored event text is legacy plain text or HTML. The editor only understands a
 * small set of tags; everything else (tables, <center>, <font>, images, iframes...) is kept byte-for-byte in
 * opaque blocks (OpaqueHtml) so editing an event never loses legacy markup. The server sanitizes on output
 * (App\Support\HtmlSanitizer), so nothing here is a security boundary except previewHtml, which keeps opaque
 * previews inert inside the admin.
 */

/** Font stacks the toolbar offers. The server allow-list (HtmlSanitizer::EDITOR_FONT_STACKS) must match. */
export const EDITOR_FONTS = [
  { id: 'arial', label: 'Arial', stack: 'Arial, Helvetica, sans-serif' },
  { id: 'georgia', label: 'Georgia', stack: 'Georgia, serif' },
  { id: 'times', label: 'Times New Roman', stack: "'Times New Roman', Times, serif" },
  { id: 'verdana', label: 'Verdana', stack: 'Verdana, Geneva, sans-serif' },
  { id: 'trebuchet', label: 'Trebuchet MS', stack: "'Trebuchet MS', Helvetica, sans-serif" },
  { id: 'courier', label: 'Courier New', stack: "'Courier New', Courier, monospace" },
] as const;

export const EDITOR_FONT_SIZES = [
  { id: 'sm', label: 'Small', css: '14px' },
  { id: 'md', label: 'Medium', css: '16px' },
  { id: 'lg', label: 'Large', css: '20px' },
  { id: 'xl', label: 'Extra large', css: '28px' },
] as const;

/** Same rule as App\Support\RichText::hasMarkup: anything else is legacy plain text. */
export function hasMarkup(value: string): boolean {
  return /<(\/?[a-z]|!--)/i.test(value);
}

function escapeText(value: string): string {
  return value.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

const INLINE_TAGS = new Set([
  'A', 'ABBR', 'B', 'BIG', 'BR', 'CITE', 'CODE', 'DEL', 'EM', 'FONT', 'I', 'IMG', 'INS', 'KBD', 'MARK', 'Q', 'S',
  'SMALL', 'SPAN', 'STRIKE', 'STRONG', 'SUB', 'SUP', 'U',
]);

const ALIGN = /^text-align:\s*(left|center|right|justify);?$/i;
const SPAN_STYLE = /^(color|font-size|font-family)$/i;
const SAFE_HREF = /^(https?:|mailto:|tel:|[/#?.]|[^:]*$)/i;

function onlyAttributes(el: Element, allowed: string[]): boolean {
  return Array.from(el.attributes).every((attr) => allowed.includes(attr.name));
}

function styleProperties(el: Element): string[] {
  const style = el.getAttribute('style') ?? '';
  return style
    .split(';')
    .map((part) => part.trim())
    .filter((part) => part !== '')
    .map((part) => part.split(':')[0]?.trim() ?? '');
}

/** Whether Tiptap (with the editor's extensions) keeps this element and its attributes unchanged. */
function isEditable(el: Element): boolean {
  const tag = el.tagName;
  let ok: boolean;
  if (tag === 'P' || /^H[1-6]$/.test(tag)) {
    ok = onlyAttributes(el, ['style']) && (!el.hasAttribute('style') || ALIGN.test((el.getAttribute('style') ?? '').trim()));
  } else if (tag === 'A') {
    ok = onlyAttributes(el, ['href', 'target', 'rel', 'title']) && SAFE_HREF.test(el.getAttribute('href') ?? '');
  } else if (tag === 'SPAN') {
    ok = onlyAttributes(el, ['style']) && styleProperties(el).every((prop) => SPAN_STYLE.test(prop));
  } else {
    ok = ['STRONG', 'B', 'EM', 'I', 'U', 'S', 'CODE', 'BR', 'HR', 'UL', 'OL', 'LI', 'BLOCKQUOTE'].includes(tag) && el.attributes.length === 0;
  }
  return ok && Array.from(el.childNodes).every((child) => child.nodeType === Node.TEXT_NODE || (child instanceof Element && isEditable(child)));
}

function serialize(nodes: Node[]): string {
  const box = document.createElement('div');
  nodes.forEach((node) => box.appendChild(node.cloneNode(true)));
  return box.innerHTML;
}

function opaqueBlock(raw: string): string {
  const div = document.createElement('div');
  div.setAttribute('data-opaque-html', 'true');
  div.setAttribute('data-html', raw);
  return div.outerHTML;
}

function parseFragment(html: string): HTMLTemplateElement {
  const template = document.createElement('template');
  template.innerHTML = html;
  return template;
}

/**
 * Stored value to editor content. Plain text becomes one escaped paragraph. Top-level runs of text and inline
 * elements become a paragraph; any run or block the editor cannot represent exactly becomes an opaque block.
 */
export function prepareHtmlForEditor(value: string): string {
  if (value.trim() === '') return '';
  if (!hasMarkup(value)) return `<p>${escapeText(value.trim())}</p>`;

  const parts: string[] = [];
  let run: Node[] = [];
  const flush = (): void => {
    if (run.every((node) => node.nodeType === Node.TEXT_NODE && (node.textContent ?? '').trim() === '')) {
      run = [];
      return;
    }
    const editable = run.every((node) => node.nodeType === Node.TEXT_NODE || (node instanceof Element && isEditable(node)));
    parts.push(editable ? `<p>${serialize(run)}</p>` : opaqueBlock(serialize(run)));
    run = [];
  };

  for (const node of Array.from(parseFragment(value).content.childNodes)) {
    const inline = node.nodeType === Node.TEXT_NODE || node.nodeType === Node.COMMENT_NODE || (node instanceof Element && INLINE_TAGS.has(node.tagName));
    if (inline) {
      run.push(node);
      continue;
    }
    flush();
    parts.push(node instanceof Element && isEditable(node) ? node.outerHTML : opaqueBlock(serialize([node])));
  }
  flush();
  return parts.join('');
}

/** Characters latin1 cannot hold (the car_show columns) become numeric entities, which render the same. */
function latin1Safe(html: string): string {
  return Array.from(html)
    .map((char) => {
      const code = char.codePointAt(0) ?? 0;
      return code > 255 ? `&#${code};` : char;
    })
    .join('');
}

/**
 * Editor HTML to the stored value: opaque blocks are expanded back to their raw HTML, the empty paragraph the
 * editor keeps after a last table or list is dropped, and an empty editor is ''.
 */
export function editorHtmlToValue(html: string): string {
  const template = parseFragment(html);
  for (const block of Array.from(template.content.querySelectorAll('div[data-opaque-html]'))) {
    const raw = block.getAttribute('data-html') ?? '';
    block.replaceWith(parseFragment(raw).content);
  }
  const out = template.innerHTML.replace(/<p><\/p>$/, '');
  return out.replace(/<p><\/p>/g, '').trim() === '' ? '' : latin1Safe(out);
}

const DROP_IN_PREVIEW = 'script, style, iframe, frame, frameset, object, embed, applet, form, input, button, select, textarea, link, meta, base, svg, math, template, noscript';

/** Inert copy of opaque HTML for display in the editor: active content is replaced by a note. */
export function previewHtml(raw: string): DocumentFragment {
  const fragment = parseFragment(raw).content;
  for (const el of Array.from(fragment.querySelectorAll(DROP_IN_PREVIEW))) {
    const note = document.createElement('span');
    note.className = 'rte-opaque-note';
    note.textContent = `[${el.tagName.toLowerCase()}]`;
    el.replaceWith(note);
  }
  for (const el of Array.from(fragment.querySelectorAll('*'))) {
    for (const attr of Array.from(el.attributes)) {
      const name = attr.name.toLowerCase();
      const value = Array.from(attr.value)
        .filter((char) => char.charCodeAt(0) > 32 && char.charCodeAt(0) !== 127)
        .join('')
        .toLowerCase();
      const badUrl = ['href', 'src', 'action', 'formaction', 'xlink:href', 'background'].includes(name) && /^(javascript|vbscript|data):/.test(value);
      if (name.startsWith('on') || name === 'srcdoc' || badUrl) {
        el.removeAttribute(attr.name);
      }
    }
  }
  return fragment;
}
