import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { Editor } from '@tiptap/core';
import { editorExtensions } from '../editorExtensions';
import { editorHtmlToValue, prepareHtmlForEditor } from '../richText';

/**
 * Real event texts (tests/Fixtures/legacy-event-texts.json, the 20 newest car_show rows of the dev database) loaded
 * into the editor and saved again must mean the same: same text (whitespace between blocks does not count), same
 * elements (b/strong and i/em count as one; paragraphs the editor adds around loose text are ignored) and the same
 * attributes on every element. A second round trip changes nothing.
 */
type Row = Record<'short_description' | 'description' | 'schedule', string | null> & { id: number };

const ROWS = JSON.parse(readFileSync('tests/Fixtures/legacy-event-texts.json', 'utf8')) as Row[];
const ALIAS: Record<string, string> = { b: 'strong', i: 'em' };

function canonical(html: string) {
  const template = document.createElement('template');
  template.innerHTML = /<(\/?[a-z]|!--)/i.test(html) ? html : html.replace(/&/g, '&amp;').replace(/</g, '&lt;');
  const elements = Array.from(template.content.querySelectorAll('*'));
  const tags: Record<string, number> = {};
  const attributes: string[] = [];
  for (const el of elements) {
    const tag = ALIAS[el.localName] ?? el.localName;
    if (tag !== 'p') tags[tag] = (tags[tag] ?? 0) + 1;
    if (el.attributes.length > 0) {
      const attrs = Array.from(el.attributes)
        .map((attr) => `${attr.name}=${attr.name === 'style' ? attr.value.replace(/;\s*$/, '').replace(/\s+/g, ' ') : attr.value}`)
        .sort();
      attributes.push(`${tag}[${attrs.join(' ')}]`);
    }
  }
  const walker = document.createTreeWalker(template.content, NodeFilter.SHOW_TEXT);
  const texts: string[] = [];
  while (walker.nextNode()) texts.push(walker.currentNode.textContent ?? '');
  const text = texts.join(' ').replace(/\s+/g, ' ').trim();
  return { text, tags, attributes: attributes.sort() };
}

function roundTrip(value: string): string {
  const editor = new Editor({ extensions: editorExtensions(), content: prepareHtmlForEditor(value) });
  const html = editorHtmlToValue(editor.getHTML());
  editor.destroy();
  return html;
}

describe('legacy event texts through the editor', () => {
  it('has the 20 sample rows', () => {
    expect(ROWS).toHaveLength(20);
  });

  for (const row of ROWS) {
    for (const field of ['short_description', 'description', 'schedule'] as const) {
      const value = row[field] ?? '';
      if (value === '') continue;
      it(`keeps car_show ${row.id} ${field}`, () => {
        const result = roundTrip(value);
        expect(canonical(result)).toEqual(canonical(value));
        expect(roundTrip(result)).toBe(result);
      });
    }
  }
});
