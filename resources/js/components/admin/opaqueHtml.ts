import { Node, mergeAttributes } from '@tiptap/core';
import { previewHtml } from './richText';

/**
 * Atomic block holding raw HTML the editor cannot edit (tables, <center>, images, embeds...). The raw markup lives
 * in data-html and is restored by editorHtmlToValue, so it survives visual editing and Raw/Visual switches. The
 * editor shows an inert preview; the markup itself is edited in Raw mode.
 */
export const OpaqueHtml = Node.create({
  name: 'opaqueHtml',
  group: 'block',
  atom: true,
  selectable: true,
  draggable: false,

  addAttributes() {
    return {
      html: {
        default: '',
        parseHTML: (element) => element.getAttribute('data-html') ?? '',
        renderHTML: (attributes) => ({ 'data-html': String(attributes.html ?? '') }),
      },
    };
  },

  parseHTML() {
    return [{ tag: 'div[data-opaque-html]' }];
  },

  renderHTML({ HTMLAttributes }) {
    return ['div', mergeAttributes(HTMLAttributes, { 'data-opaque-html': 'true' })];
  },

  addNodeView() {
    return ({ node }) => {
      const dom = document.createElement('div');
      dom.className = 'rte-opaque';
      dom.contentEditable = 'false';
      const note = document.createElement('p');
      note.className = 'rte-opaque-label';
      note.textContent = 'Kept as is (edit in Raw mode)';
      const body = document.createElement('div');
      body.className = 'event-html';
      body.appendChild(previewHtml(String(node.attrs.html ?? '')));
      dom.append(note, body);
      return { dom };
    };
  },
});
