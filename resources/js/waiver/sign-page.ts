import { byId, showAlert } from './dom';
import { describePrefillFailure, describeSignFailure, UNEXPECTED_RESPONSE } from './errors';
import { buildSignPayload, firstValidationError, type SignatureMode, type SignerInput, type SignPayload } from './form';
import { requestJson, type ApiResult } from './http';
import { parsePrefill } from './prefill';
import { clearPending, markCompleted, markPending, recoveryHint } from './recovery';
import { reportToRum } from './rum';
import { isTransient, MAX_SUBMIT_ATTEMPTS, retryDelayMs, SUBMIT_TIMEOUT_MS } from './retry';
import { SignaturePad } from './signature';
import { renderTypedSignature } from './typed';

const CAPTION_DEFAULT = 'Signature:';
const CAPTION_MINOR = 'Signature (parent or guardian signing for the participant named above):';
const PREFILL_RETRY_MS = 750;
const PREFILL_ATTEMPTS = 2;
const OFFLINE_MESSAGE = 'You appear to be offline. Reconnect to the internet, then tap Submit signed waiver again.';
const HIGHLIGHT_MS = 3200;
const HIGHLIGHT_SCROLLS_MS = [0, 250, 600];

function fillIfEmpty(input: HTMLInputElement, value: string): void {
    if (input.value === '') {
        input.value = value;
    }
}

function delay(ms: number): Promise<void> {
    return new Promise((resolve) => window.setTimeout(resolve, ms));
}

function thankYouUrlOf(result: ApiResult): string | null {
    const url = (result.body as { thankYouUrl?: unknown } | null)?.thankYouUrl;

    return typeof url === 'string' ? url : null;
}

/** Signing form for a tracking link (base = /waiver_sign/t/{id}) or a walk-in (base = /waiver_sign/s/{showId}). */
export function mountSignPage(doc: Document, base: string): void {
    const embedded = window.parent !== window;
    const form = byId<HTMLFormElement>(doc, 'sesf-waiver-form');
    const status = byId<HTMLElement>(doc, 'sesf-waiver-status');
    const recovery = byId<HTMLElement>(doc, 'sesf-waiver-recovery');
    const submit = byId<HTMLButtonElement>(doc, 'sesf-waiver-submit');
    const firstInput = byId<HTMLInputElement>(doc, 'sesf-first-name');
    const lastInput = byId<HTMLInputElement>(doc, 'sesf-last-name');
    const emailInput = byId<HTMLInputElement>(doc, 'sesf-email');
    const minor = byId<HTMLInputElement>(doc, 'sesf-signing-for-minor');
    const adultBlock = byId<HTMLFieldSetElement>(doc, 'sesf-adult-block');
    const adultFirst = byId<HTMLInputElement>(doc, 'sesf-adult-first');
    const adultLast = byId<HTMLInputElement>(doc, 'sesf-adult-last');
    const caption = byId<HTMLElement>(doc, 'sesf-sig-caption-text');
    const previewWrap = byId<HTMLElement>(doc, 'sesf-sig-preview-wrap');
    const previewImg = byId<HTMLImageElement>(doc, 'sesf-sig-preview-img');
    const dialog = byId<HTMLDialogElement>(doc, 'sesf-sig-modal');
    const modalMsg = byId<HTMLElement>(doc, 'sesf-sig-modal-msg');
    const modeDraw = byId<HTMLButtonElement>(doc, 'sesf-modal-mode-draw');
    const modeType = byId<HTMLButtonElement>(doc, 'sesf-modal-mode-type');
    const drawBlock = byId<HTMLElement>(doc, 'sesf-sig-draw-block');
    const typeBlock = byId<HTMLElement>(doc, 'sesf-sig-type-block');
    const typeText = byId<HTMLTextAreaElement>(doc, 'sesf-sig-type-text');
    const typePreview = byId<HTMLImageElement>(doc, 'sesf-type-preview-img');
    const typePlaceholder = byId<HTMLElement>(doc, 'sesf-type-preview-placeholder');
    const pad = new SignaturePad(byId<HTMLCanvasElement>(doc, 'sesf-sig-canvas'));
    let modalMode: SignatureMode = 'draw';
    let signatureMode: SignatureMode = 'draw';
    let mainDataUrl = '';
    let submitting = false;
    let completed = false;
    let previewRun = 0;
    let highlightTimer: number | undefined;

    function readInput(): SignerInput {
        return {
            firstName: firstInput.value,
            lastName: lastInput.value,
            email: emailInput.value,
            isMinor: minor.checked,
            guardianFirstName: adultFirst.value,
            guardianLastName: adultLast.value,
        };
    }

    function setModalMode(next: SignatureMode): void {
        modalMode = next;
        drawBlock.hidden = next !== 'draw';
        typeBlock.hidden = next !== 'type';
        modeDraw.setAttribute('aria-pressed', String(next === 'draw'));
        modeType.setAttribute('aria-pressed', String(next === 'type'));
        modalMsg.hidden = true;
    }

    async function refreshTypePreview(): Promise<void> {
        const run = ++previewRun;
        const url = await renderTypedSignature(typeText.value);
        if (run !== previewRun) {
            return;
        }
        typePreview.hidden = url === null;
        typePlaceholder.hidden = url !== null;
        if (url !== null) {
            typePreview.src = url;
        }
    }

    function openModal(): void {
        setModalMode(signatureMode);
        dialog.showModal();
        pad.resize();
    }

    function closeModal(): void {
        dialog.close();
    }

    function showPreview(url: string, mode: SignatureMode): void {
        mainDataUrl = url;
        signatureMode = mode;
        previewImg.src = url;
        previewImg.hidden = false;
        closeModal();
        window.setTimeout(highlightSubmit, 500);
    }

    /** Scrolls the submit button into view and pulses it (legacy highlightSubmit). */
    function highlightSubmit(): void {
        for (const ms of HIGHLIGHT_SCROLLS_MS) {
            window.setTimeout(() => submit.scrollIntoView({ block: 'center', behavior: 'smooth' }), ms);
        }
        submit.classList.remove('sesf-waiver-submit-pulse');
        void submit.offsetWidth;
        submit.classList.add('sesf-waiver-submit-pulse');
        window.clearTimeout(highlightTimer);
        highlightTimer = window.setTimeout(() => submit.classList.remove('sesf-waiver-submit-pulse'), HIGHLIGHT_MS);
    }

    function done(): void {
        if (modalMode === 'draw') {
            if (pad.isEmpty()) {
                showAlert(modalMsg, 'Please add a signature.', 'error');
                return;
            }
            showPreview(pad.toDataUrl(), 'draw');
            return;
        }
        void renderTypedSignature(typeText.value).then((url) => {
            if (url === null) {
                showAlert(modalMsg, 'Please type your name to sign.', 'error');
                return;
            }
            showPreview(url, 'type');
        });
    }

    function clearModal(): void {
        if (modalMode === 'draw') {
            pad.clear();
            return;
        }
        typeText.value = '';
        void refreshTypePreview();
    }

    async function sendWithRetries(payload: SignPayload): Promise<ApiResult> {
        let result: ApiResult = { ok: false, status: 0, body: null };
        for (let attempt = 1; attempt <= MAX_SUBMIT_ATTEMPTS; attempt++) {
            markPending(base, Date.now());
            result = await requestJson('POST', base, payload, SUBMIT_TIMEOUT_MS);
            if (!isTransient(result) || attempt === MAX_SUBMIT_ATTEMPTS) {
                return result;
            }
            showAlert(status, `Connection issue—retrying automatically (attempt ${attempt} of ${MAX_SUBMIT_ATTEMPTS})…`, 'info');
            await delay(retryDelayMs(attempt));
        }

        return result;
    }

    function finishFailure(message: string, isCompleted: boolean): void {
        completed = completed || isCompleted;
        submitting = false;
        submit.disabled = false;
        showAlert(status, message, 'error');
        reportToRum(message, { sesf_source: 'waiver_widget', phase: 'submit' });
    }

    async function submitWaiver(): Promise<void> {
        if (submitting) {
            return;
        }
        showAlert(status, '', 'error');
        if (mainDataUrl === '') {
            showAlert(status, 'Please add your signature.', 'error');
            return;
        }
        const input = readInput();
        const problem = firstValidationError(input);
        if (problem !== null) {
            showAlert(status, problem, 'error');
            return;
        }
        if (navigator.onLine === false) {
            showAlert(status, OFFLINE_MESSAGE, 'error');
            return;
        }

        submitting = true;
        submit.disabled = true;
        const result = await sendWithRetries(buildSignPayload(input, signatureMode, mainDataUrl, typeText.value, 'standalone'));
        const thankYouUrl = thankYouUrlOf(result);
        if (result.ok && thankYouUrl !== null) {
            markCompleted(base, thankYouUrl, Date.now());
            window.location.assign(thankYouUrl);
            return;
        }
        clearPending();
        if (result.ok) {
            finishFailure(UNEXPECTED_RESPONSE, false);
            return;
        }
        const failure = describeSignFailure(result);
        finishFailure(failure.message, failure.completed);
    }

    async function loadPrefill(attempt: number): Promise<void> {
        const result = await requestJson('GET', `${base}/prefill`);
        const prefill = result.ok ? parsePrefill(result.body) : null;
        if (prefill !== null) {
            fillIfEmpty(firstInput, prefill.first);
            fillIfEmpty(lastInput, prefill.last);
            fillIfEmpty(emailInput, prefill.email);
            submit.disabled = false;
            return;
        }
        if (attempt < PREFILL_ATTEMPTS) {
            window.setTimeout(() => void loadPrefill(attempt + 1), PREFILL_RETRY_MS);
            return;
        }
        const message = describePrefillFailure(result);
        showAlert(status, message, 'error');
        reportToRum(message, { sesf_source: 'waiver_widget', phase: 'boot' });
    }

    function updateCaption(): void {
        caption.textContent = minor.checked ? CAPTION_MINOR : CAPTION_DEFAULT;
    }

    minor.addEventListener('change', () => {
        adultBlock.hidden = !minor.checked;
        if (!minor.checked) {
            adultFirst.value = '';
            adultLast.value = '';
        }
        updateCaption();
    });
    previewWrap.addEventListener('click', openModal);
    byId<HTMLButtonElement>(doc, 'sesf-open-sig').addEventListener('click', (event) => {
        event.stopPropagation();
        openModal();
    });
    byId<HTMLButtonElement>(doc, 'sesf-sig-close').addEventListener('click', closeModal);
    byId<HTMLButtonElement>(doc, 'sesf-sig-cancel').addEventListener('click', closeModal);
    byId<HTMLButtonElement>(doc, 'sesf-sig-done').addEventListener('click', done);
    byId<HTMLButtonElement>(doc, 'sesf-sig-clear').addEventListener('click', clearModal);
    modeDraw.addEventListener('click', () => setModalMode('draw'));
    modeType.addEventListener('click', () => setModalMode('type'));
    byId<HTMLButtonElement>(doc, 'sesf-modal-switch-draw').addEventListener('click', () => setModalMode('draw'));
    typeText.addEventListener('input', () => void refreshTypePreview());
    form.addEventListener('submit', (event) => {
        event.preventDefault();
        void submitWaiver();
    });
    window.addEventListener('resize', () => pad.resize());

    if (!embedded) {
        window.addEventListener('beforeunload', (event) => {
            if (!completed && mainDataUrl !== '') {
                event.preventDefault();
                event.returnValue = '';
            }
        });
    }

    showAlert(recovery, recoveryHint(base, Date.now()), 'info');
    setModalMode('draw');
    updateCaption();
    void loadPrefill(1);
}
