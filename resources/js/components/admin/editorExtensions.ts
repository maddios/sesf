import StarterKit from '@tiptap/starter-kit';
import Link from '@tiptap/extension-link';
import TextAlign from '@tiptap/extension-text-align';
import { TextStyleKit } from '@tiptap/extension-text-style';
import { OpaqueHtml } from './opaqueHtml';

/** Tiptap extensions of RichTextEditor. The HTML they emit must stay inside App\Support\HtmlSanitizer's allow-list. */
export function editorExtensions() {
  return [
    StarterKit.configure({ codeBlock: false, link: false }),
    Link.configure({ openOnClick: false, HTMLAttributes: { target: null, rel: null } }),
    TextStyleKit.configure({ backgroundColor: false, lineHeight: false }),
    TextAlign.configure({ types: ['heading', 'paragraph'], alignments: ['left', 'center', 'right', 'justify'] }),
    OpaqueHtml,
  ];
}
