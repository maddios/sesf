import { afterEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor, within } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { axe } from 'vitest-axe';
import { defineComponent, h, ref } from 'vue';
import type { Editor } from '@tiptap/core';
import RichTextEditor from '../RichTextEditor.vue';

function harness(initial: string, maxlength?: number) {
  const emitted: string[] = [];
  const Host = defineComponent({
    setup() {
      const value = ref(initial);
      return () =>
        h('main', [h(RichTextEditor, {
          modelValue: value.value,
          label: 'Description',
          id: 'desc',
          maxlength,
          'onUpdate:modelValue': (next: string) => {
            emitted.push(next);
            value.value = next;
          },
        })]);
    },
  });
  render(Host);
  return emitted;
}

function editorFor(): Editor {
  const dom = document.querySelector('.ProseMirror') as (HTMLElement & { editor?: Editor }) | null;
  if (!dom?.editor) throw new Error('editor not mounted');
  return dom.editor;
}

function selectAll(): void {
  const editor = editorFor();
  editor.commands.setTextSelection({ from: 1, to: editor.state.doc.content.size - 1 });
}

const last = (list: string[]): string => list[list.length - 1] ?? '';

afterEach(() => {
  vi.restoreAllMocks();
});

describe('RichTextEditor', () => {
  it('shows the toolbar, the help line and the mode switch, emits nothing on load and passes axe', async () => {
    const emitted = harness('Legacy plain text');
    const textbox = await screen.findByRole('textbox', { name: 'Description' });
    expect(textbox.id).toBe('desc');
    const toolbar = screen.getByRole('toolbar', { name: 'Description formatting' });
    for (const name of ['Bold', 'Italic', 'Link', 'Bullet list', 'Numbered list', 'Align left', 'Align center', 'Align right', 'Justify']) {
      expect(within(toolbar).getByRole('button', { name })).toBeTruthy();
    }
    expect(within(toolbar).getByRole('combobox', { name: 'Font' })).toBeTruthy();
    expect(within(toolbar).getByRole('combobox', { name: 'Size' })).toBeTruthy();
    expect(within(toolbar).getByLabelText('Text colour')).toBeTruthy();
    expect(screen.getByRole('switch', { name: 'Visual editor' }).getAttribute('aria-checked')).toBe('true');
    expect(screen.getByText(/Select words or a sentence to change font, size, or color\. Alignment applies to the current paragraph\./)).toBeTruthy();
    expect(textbox.textContent).toBe('Legacy plain text');
    expect(emitted).toEqual([]);
    expect(await axe(document.body)).toHaveNoViolations();
  });

  it('applies bold, italic, lists and alignment', async () => {
    const user = userEvent.setup();
    const emitted = harness('<p>Hello</p>');
    await screen.findByRole('textbox', { name: 'Description' });

    selectAll();
    await user.click(screen.getByRole('button', { name: 'Bold' }));
    expect(last(emitted)).toBe('<p><strong>Hello</strong></p>');
    expect(screen.getByRole('button', { name: 'Bold' }).getAttribute('aria-pressed')).toBe('true');

    await user.click(screen.getByRole('button', { name: 'Italic' }));
    expect(last(emitted)).toBe('<p><strong><em>Hello</em></strong></p>');

    await user.click(screen.getByRole('button', { name: 'Align center' }));
    expect(last(emitted)).toBe('<p style="text-align: center;"><strong><em>Hello</em></strong></p>');

    await user.click(screen.getByRole('button', { name: 'Bullet list' }));
    expect(last(emitted)).toMatch(/^<ul><li><p style="text-align: center;?">/);

    await user.click(screen.getByRole('button', { name: 'Numbered list' }));
    expect(last(emitted)).toMatch(/^<ol><li>/);
  });

  it('sets font, size and colour on the selection', async () => {
    const user = userEvent.setup();
    const emitted = harness('<p>Hello</p>');
    await screen.findByRole('textbox', { name: 'Description' });

    selectAll();
    await user.click(screen.getByRole('combobox', { name: 'Font' }));
    await user.click(await screen.findByRole('option', { name: 'Georgia' }));
    expect(last(emitted)).toBe('<p><span style="font-family: Georgia, serif;">Hello</span></p>');

    selectAll();
    await user.click(screen.getByRole('combobox', { name: 'Size' }));
    await user.click(await screen.findByRole('option', { name: 'Large' }));
    expect(last(emitted)).toContain('font-size: 20px');

    selectAll();
    await fireEvent.input(screen.getByLabelText('Text colour'), { target: { value: '#b91c1c' } });
    expect(last(emitted)).toMatch(/color: (#b91c1c|rgb\(185, 28, 28\))/);

    selectAll();
    await user.click(screen.getByRole('combobox', { name: 'Font' }));
    await user.click(await screen.findByRole('option', { name: 'Site default' }));
    expect(last(emitted)).not.toContain('font-family');
  });

  it('adds and removes a link through the prompt', async () => {
    const user = userEvent.setup();
    const emitted = harness('<p>Site</p>');
    await screen.findByRole('textbox', { name: 'Description' });
    const prompt = vi.spyOn(window, 'prompt').mockReturnValue('https://example.org/');

    selectAll();
    await user.click(screen.getByRole('button', { name: 'Link' }));
    expect(last(emitted)).toBe('<p><a href="https://example.org/">Site</a></p>');

    prompt.mockReturnValue('');
    selectAll();
    await user.click(screen.getByRole('button', { name: 'Link' }));
    expect(last(emitted)).toBe('<p>Site</p>');
  });

  it('edits the HTML in Raw mode and loads it back into the visual editor', async () => {
    const user = userEvent.setup();
    const emitted = harness('<p>Start</p>');
    await screen.findByRole('textbox', { name: 'Description' });

    await user.click(screen.getByRole('switch', { name: 'Visual editor' }));
    const raw = screen.getByRole('textbox', { name: 'Description (HTML)' }) as HTMLTextAreaElement;
    expect(raw.value).toBe('<p>Start</p>');
    expect(raw.id).toBe('desc');
    expect(emitted).toEqual([]);

    await fireEvent.update(raw, '<p>Changed <b>raw</b></p>');
    expect(last(emitted)).toBe('<p>Changed <b>raw</b></p>');

    await user.click(screen.getByRole('switch', { name: 'Visual editor' }));
    const textbox = await screen.findByRole('textbox', { name: 'Description' });
    expect(textbox.innerHTML).toContain('<strong>raw</strong>');
    expect(last(emitted)).toBe('<p>Changed <b>raw</b></p>');
  });

  it('keeps unsupported legacy markup unchanged while other text is edited', async () => {
    const legacy = '<center><big>Primary Sponsors</big></center><table border="1"><tbody><tr><td valign="top">Fri</td></tr></tbody></table><iframe src="https://video.example/1"></iframe><p>Edit me</p>';
    const emitted = harness(legacy);
    const textbox = await screen.findByRole('textbox', { name: 'Description' });
    expect(textbox.querySelectorAll('.rte-opaque')).toHaveLength(3);
    expect(textbox.querySelector('iframe')).toBeNull();

    const editor = editorFor();
    editor.commands.insertContentAt(editor.state.doc.content.size - 1, '!');
    await waitFor(() => expect(emitted).toHaveLength(1));
    expect(last(emitted)).toBe(legacy.replace('Edit me', 'Edit me!'));
  });

  it('counts the HTML against maxlength and explains an overflow without cutting', async () => {
    const emitted = harness('<p>12345</p>', 20);
    await screen.findByRole('textbox', { name: 'Description' });
    expect(screen.getByText('12 / 20 characters, formatting included')).toBeTruthy();

    selectAll();
    await userEvent.setup().click(screen.getByRole('button', { name: 'Bold' }));
    expect(last(emitted)).toBe('<p><strong>12345</strong></p>');
    expect(screen.getByRole('alert').textContent).toBe('Too long by 9 characters. The formatting (HTML) counts too: remove some text or formatting before saving.');
    expect(screen.getByRole('textbox', { name: 'Description' }).getAttribute('aria-invalid')).toBe('true');
  });
});
