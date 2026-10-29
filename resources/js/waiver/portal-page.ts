import { describeLoginFailure } from './errors';
import { byId, showAlert, type Tone } from './dom';
import { requestJson, type ApiResult } from './http';
import { PORTAL_SIGNED_MESSAGE } from './thank-you-page';
import { waiverTitle } from './portal-title';

interface Person {
    name: string;
    guest_id: number | null;
    status: 'signed' | 'not_signed' | 'not_started';
}

interface PortalStatus {
    signedInAs: string;
    people: Person[];
}

interface NotRegistered {
    message: string;
    signedInAs?: string;
}

const OTP_LENGTH = 6;
const COUNTDOWN_SECONDS = 8;
const TOO_MANY = 'Too many attempts. Please wait a minute.';
const FALLBACK_PREFIX = 'If no waiver shows above, ';
const FALLBACK_LINK = 'click here to sign in a new tab';

function isPortalStatus(body: unknown): body is PortalStatus {
    return typeof body === 'object' && body !== null && Array.isArray((body as { people?: unknown }).people);
}

function isNotRegistered(body: unknown): body is NotRegistered {
    const candidate = body as { error?: unknown; message?: unknown } | null;
    return candidate?.error === 'not_registered' && typeof candidate.message === 'string';
}

function firstMessage(result: ApiResult, fallback: string): string {
    return Array.isArray(result.body) && typeof result.body[0] === 'string' ? result.body[0] : fallback;
}

/** Member portal for one event (base = /waiver/{showId}): password or emailed-code sign-in, status, sign and share. */
export function mountPortalPage(doc: Document, base: string): void {
    const message = byId<HTMLElement>(doc, 'sesf-portal-status-msg');
    const loading = byId<HTMLElement>(doc, 'sesf-portal-loading');
    const loginSection = byId<HTMLElement>(doc, 'sesf-portal-login');
    const listSection = byId<HTMLElement>(doc, 'sesf-portal-list');
    const people = byId<HTMLUListElement>(doc, 'sesf-portal-people');
    const signedIn = byId<HTMLElement>(doc, 'sesf-portal-signed-in');
    const modal = byId<HTMLDialogElement>(doc, 'sesf-waiver-modal');
    const modalFrame = byId<HTMLIFrameElement>(doc, 'sesf-waiver-modal-iframe');
    const modalTitle = byId<HTMLElement>(doc, 'sesf-waiver-modal-title');
    const modalFoot = byId<HTMLElement>(doc, 'sesf-waiver-modal-foot');
    let signingComplete = false;
    let countdown: number | undefined;
    let otpBusy = false;

    function say(text: string, tone: Tone): void {
        showAlert(message, text, tone);
    }

    function selectTab(method: 'password' | 'email'): void {
        const password = method === 'password';
        byId<HTMLElement>(doc, 'sesf-tab-password').setAttribute('aria-selected', String(password));
        byId<HTMLElement>(doc, 'sesf-tab-email').setAttribute('aria-selected', String(!password));
        byId<HTMLElement>(doc, 'sesf-panel-password').hidden = !password;
        byId<HTMLElement>(doc, 'sesf-panel-email').hidden = password;
        say('', 'info');
    }

    function showLogin(): void {
        loading.hidden = true;
        listSection.hidden = true;
        loginSection.hidden = false;
    }

    function startSigning(person: Person, button: HTMLButtonElement): void {
        const query = person.guest_id === null ? '' : `?guest_id=${person.guest_id}`;
        button.disabled = true;
        void requestJson('GET', `${base}/sign-url${query}`).then((result) => {
            button.disabled = false;
            const url = (result.body as { url?: unknown } | null)?.url;
            if (result.ok && typeof url === 'string') {
                openSigningModal(url, person.name);
                return;
            }
            say('This waiver can not be opened right now. Refresh this page and try again.', 'error');
            void refresh();
        });
    }

    function renderPerson(person: Person): HTMLLIElement {
        const item = doc.createElement('li');
        const name = doc.createElement('span');
        name.textContent = person.name;
        const badge = doc.createElement('span');
        badge.className = person.status === 'signed' ? 'sesf-waiver-badge sesf-waiver-badge-signed' : 'sesf-waiver-badge';
        badge.textContent = person.status === 'signed' ? 'Signed' : 'Not signed';
        item.append(name, ' ', badge);

        if (person.status !== 'signed') {
            const actions = doc.createElement('div');
            actions.className = 'sesf-waiver-actions';
            const share = doc.createElement('a');
            share.className = 'sesf-btn sesf-btn-secondary';
            share.href = `${base}/share${person.guest_id === null ? '' : `?guest_id=${person.guest_id}`}`;
            share.textContent = 'Share';
            const sign = doc.createElement('button');
            sign.type = 'button';
            sign.className = 'sesf-btn sesf-btn-primary';
            sign.textContent = 'Sign';
            sign.setAttribute('aria-label', `Sign waiver for ${person.name}`);
            sign.addEventListener('click', () => startSigning(person, sign));
            actions.append(share, sign);
            item.append(actions);
        }

        return item;
    }

    function showList(body: PortalStatus): void {
        loading.hidden = true;
        loginSection.hidden = true;
        signedIn.textContent = `Signed in as ${body.signedInAs}`;
        signedIn.hidden = body.signedInAs === '';
        people.replaceChildren(...body.people.map(renderPerson));
        listSection.hidden = false;
    }

    function showNotRegistered(body: NotRegistered): void {
        showList({ signedInAs: body.signedInAs ?? '', people: [] });
        say(body.message, 'error');
    }

    async function refresh(): Promise<void> {
        const result = await requestJson('GET', `${base}/status`);
        if (result.status === 401) {
            showLogin();
            return;
        }
        if (result.status === 403 && isNotRegistered(result.body)) {
            showNotRegistered(result.body);
            return;
        }
        if (!result.ok || !isPortalStatus(result.body)) {
            loading.hidden = true;
            say('We could not load your waivers. Refresh this page to try again.', 'error');
            return;
        }
        showList(result.body);
    }

    function stopCountdown(): void {
        window.clearInterval(countdown);
        countdown = undefined;
    }

    function startCountdown(): void {
        signingComplete = true;
        modalTitle.textContent = 'Thank you';
        let left = COUNTDOWN_SECONDS;
        const tick = (): void => {
            modalFoot.hidden = false;
            modalFoot.textContent = `Thank you for signing. Close this panel with × or wait — updating your waiver list in ${left}s…`;
        };
        stopCountdown();
        tick();
        countdown = window.setInterval(() => {
            left -= 1;
            if (left <= 0) {
                stopCountdown();
                window.location.reload();
                return;
            }
            tick();
        }, 1000);
    }

    function openSigningModal(url: string, personName: string): void {
        signingComplete = false;
        stopCountdown();
        modalFoot.hidden = true;
        modalFoot.replaceChildren();
        modalTitle.textContent = waiverTitle(personName);
        modalFrame.onload = () => {
            if (!signingComplete) {
                modalFoot.hidden = true;
            }
        };
        modalFrame.onerror = () => {
            if (signingComplete) {
                return;
            }
            const link = doc.createElement('a');
            link.href = url;
            link.target = '_blank';
            link.rel = 'noopener noreferrer';
            link.textContent = FALLBACK_LINK;
            modalFoot.replaceChildren(FALLBACK_PREFIX, link, '.');
            modalFoot.hidden = false;
        };
        modalFrame.src = url;
        modal.showModal();
    }

    function closeSigningModal(): void {
        if (modal.open) {
            modal.close();
        }
    }

    modal.addEventListener('close', () => {
        stopCountdown();
        modalFrame.src = 'about:blank';
        if (signingComplete) {
            window.location.reload();
        }
    });
    byId<HTMLButtonElement>(doc, 'sesf-waiver-modal-close').addEventListener('click', closeSigningModal);
    window.addEventListener('message', (event: MessageEvent<unknown>) => {
        const data = event.data as { type?: unknown } | null;
        if (event.origin !== window.location.origin || event.source !== modalFrame.contentWindow || data?.type !== PORTAL_SIGNED_MESSAGE || !modal.open) {
            return;
        }
        startCountdown();
    });

    byId<HTMLButtonElement>(doc, 'sesf-tab-password').addEventListener('click', () => selectTab('password'));
    byId<HTMLButtonElement>(doc, 'sesf-tab-email').addEventListener('click', () => selectTab('email'));

    byId<HTMLFormElement>(doc, 'sesf-portal-login-form').addEventListener('submit', (event) => {
        event.preventDefault();
        const email = byId<HTMLInputElement>(doc, 'sesf-portal-email').value.trim();
        const password = byId<HTMLInputElement>(doc, 'sesf-portal-password').value;
        const remember = byId<HTMLInputElement>(doc, 'sesf-portal-remember').checked;
        void requestJson('POST', `${base}/login`, { email, password, remember }).then(async (result) => {
            if (result.ok) {
                say('', 'info');
                await refresh();
                return;
            }
            say(describeLoginFailure(result), 'error');
        });
    });

    const step1 = byId<HTMLFormElement>(doc, 'sesf-portal-email-step1');
    const step2 = byId<HTMLFormElement>(doc, 'sesf-portal-email-step2');
    const codeAddr = byId<HTMLInputElement>(doc, 'sesf-portal-email-addr');
    const otp = byId<HTMLInputElement>(doc, 'sesf-portal-otp');

    async function verifyCode(): Promise<void> {
        const code = otp.value.replace(/\D/g, '');
        if (code.length !== OTP_LENGTH) {
            say('Please enter all 6 numbers from the email.', 'error');
            return;
        }
        if (otpBusy) {
            return;
        }
        otpBusy = true;
        const result = await requestJson('POST', '/login/email-signin/verify-code', { email: codeAddr.value.trim(), code });
        otpBusy = false;
        if (result.status === 429) {
            say(TOO_MANY, 'error');
            return;
        }
        if (!result.ok) {
            say(firstMessage(result, 'That code did not match. Try again, or use the button in your email.'), 'error');
            return;
        }
        window.location.href = (result.body as { redirect: string }).redirect;
    }

    step1.addEventListener('submit', (event) => {
        event.preventDefault();
        const email = codeAddr.value.trim();
        if (email === '') {
            say('Please enter your email address.', 'error');
            return;
        }
        say('', 'info');
        void requestJson('POST', '/login/email-signin/request', { email, redirect_path: base }).then((result) => {
            if (result.ok) {
                say('Check your email. We sent you a code.', 'success');
                step1.hidden = true;
                step2.hidden = false;
                return;
            }
            say(result.status === 429 ? TOO_MANY : firstMessage(result, 'Could not send the email. Try again.'), 'error');
        });
    });
    step2.addEventListener('submit', (event) => {
        event.preventDefault();
        void verifyCode();
    });
    otp.addEventListener('input', () => {
        if (otp.value.replace(/\D/g, '').length === OTP_LENGTH) {
            void verifyCode();
        }
    });
    byId<HTMLButtonElement>(doc, 'sesf-portal-email-restart').addEventListener('click', () => {
        step2.hidden = true;
        step1.hidden = false;
        say('', 'info');
    });

    byId<HTMLButtonElement>(doc, 'sesf-portal-logout').addEventListener('click', async () => {
        await requestJson('POST', `${base}/logout`);
        await refresh();
    });

    void refresh();
}
