import { describe, expect, it } from 'vitest';
import { editorHtmlToValue, hasMarkup, prepareHtmlForEditor, previewHtml } from '../richText';

function opaqueRaw(html: string): string[] {
  const box = document.createElement('div');
  box.innerHTML = html;
  return Array.from(box.querySelectorAll('div[data-opaque-html]')).map((el) => el.getAttribute('data-html') ?? '');
}

describe('prepareHtmlForEditor', () => {
  it('turns legacy plain text into one escaped paragraph', () => {
    expect(hasMarkup('Fish & chips < 5')).toBe(false);
    expect(prepareHtmlForEditor(' Fish & chips < 5\nnext line ')).toBe('<p>Fish &amp; chips &lt; 5\nnext line</p>');
    expect(prepareHtmlForEditor('   ')).toBe('');
  });

  it('keeps markup the editor supports as is', () => {
    const html = '<p style="text-align: center"><strong>Hi</strong> <a href="https://example.org">x</a> <span style="color: #b91c1c; font-size: 20px">c</span></p><ul><li>one</li></ul>';
    expect(prepareHtmlForEditor(html)).toBe(html);
  });

  it('wraps top-level inline runs in a paragraph', () => {
    expect(prepareHtmlForEditor('Line one<br><b>two</b><p>Para</p>')).toBe('<p>Line one<br><b>two</b></p><p>Para</p>');
  });

  it('keeps unsupported blocks and runs in opaque blocks', () => {
    const table = '<table border="1"><tbody><tr><td valign="top" style="width: 50%">A</td></tr></tbody></table>';
    const prepared = prepareHtmlForEditor(`<p>Intro</p>${table}<center><big>Big</big></center>Text <font color="red">red</font><p align="center">old align</p><iframe src="https://video.example/1"></iframe>`);
    expect(prepared.startsWith('<p>Intro</p>')).toBe(true);
    expect(opaqueRaw(prepared)).toEqual([
      table,
      '<center><big>Big</big></center>',
      'Text <font color="red">red</font>',
      '<p align="center">old align</p>',
      '<iframe src="https://video.example/1"></iframe>',
    ]);
  });

  it('treats a paragraph with an image, a class or a comment as opaque', () => {
    expect(opaqueRaw(prepareHtmlForEditor('<p>A <img src="/a.jpg"></p>'))).toEqual(['<p>A <img src="/a.jpg"></p>']);
    expect(opaqueRaw(prepareHtmlForEditor('<p class="lead">A</p>'))).toEqual(['<p class="lead">A</p>']);
    expect(opaqueRaw(prepareHtmlForEditor('<p>A<!-- note --></p>'))).toEqual(['<p>A<!-- note --></p>']);
    expect(opaqueRaw(prepareHtmlForEditor('<p><a href="javascript:alert(1)">x</a></p>'))).toHaveLength(1);
  });
});

describe('editorHtmlToValue', () => {
  it('expands opaque blocks back to their raw HTML', () => {
    const prepared = prepareHtmlForEditor('<p>Intro</p><table><tbody><tr><td>A &amp; B</td></tr></tbody></table>');
    expect(editorHtmlToValue(prepared)).toBe('<p>Intro</p><table><tbody><tr><td>A &amp; B</td></tr></tbody></table>');
  });

  it('drops the trailing empty paragraph and returns an empty string for an empty editor', () => {
    expect(editorHtmlToValue('<p>A</p><p></p>')).toBe('<p>A</p>');
    expect(editorHtmlToValue('<p></p>')).toBe('');
  });

  it('stores characters outside latin1 as numeric entities', () => {
    expect(editorHtmlToValue('<p>It’s 🚗</p>')).toBe('<p>It&#8217;s &#128663;</p>');
  });
});

describe('previewHtml', () => {
  it('neutralises active content in opaque previews', () => {
    const box = document.createElement('div');
    box.appendChild(previewHtml('<img src="/a.jpg" onerror="alert(1)"><a href=" javascript:alert(1)">x</a><script>alert(2)</script><iframe src="https://x.example"></iframe>'));
    expect(box.innerHTML).toBe('<img src="/a.jpg"><a>x</a><span class="rte-opaque-note">[script]</span><span class="rte-opaque-note">[iframe]</span>');
  });
});
