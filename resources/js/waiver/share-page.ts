import { byId, showAlert } from './dom';
import { reportToRum } from './rum';

/** Copy inside the same tap: select the visible field and copy (legacy copy handler), then the Clipboard API. */
async function copyLink(field: HTMLTextAreaElement): Promise<boolean> {
    field.focus();
    field.select();
    if (document.execCommand('copy')) {
        return true;
    }
    if (navigator.clipboard === undefined) {
        return false;
    }
    try {
        await navigator.clipboard.writeText(field.value);

        return true;
    } catch {
        return false;
    }
}

export function mountSharePage(doc: Document): void {
    const root = byId<HTMLElement>(doc, 'sesf-share-root');
    const field = byId<HTMLTextAreaElement>(doc, 'sesf-share-link');
    const copied = byId<HTMLElement>(doc, 'sesf-share-copied');
    const failed = byId<HTMLElement>(doc, 'sesf-share-copy-failed');

    byId<HTMLButtonElement>(doc, 'sesf-share-copy').addEventListener('click', async () => {
        showAlert(copied, '', 'success');
        showAlert(failed, '', 'error');
        if (await copyLink(field)) {
            showAlert(copied, 'Copied to clipboard', 'success');
            window.setTimeout(() => showAlert(copied, '', 'success'), 2800);
            return;
        }
        showAlert(failed, 'Could not copy automatically — tap the box above, choose Select All, then Copy.', 'error');
        reportToRum('waiver_share_copy_failed', { sesf_source: 'waiver_share', phase: 'copy' });
    });

    byId<HTMLButtonElement>(doc, 'sesf-share-native').addEventListener('click', () => {
        if (navigator.share === undefined) {
            window.alert('System share isn’t available in this browser. Use “Copy link” below and paste the link where you need it.');
            return;
        }
        navigator.share({ title: root.dataset.shareTitle ?? '', text: root.dataset.shareText ?? '', url: field.value }).catch((error: unknown) => {
            if (error instanceof Error && error.name !== 'AbortError') {
                reportToRum(error.message, { sesf_source: 'waiver_share', phase: 'navigator_share' });
            }
        });
    });
}
