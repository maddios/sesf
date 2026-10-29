import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { mountPortalPage } from './portal-page';
import { PORTAL_SIGNED_MESSAGE } from './thank-you-page';
import { jsonResponse, stubFetch } from '../test/helpers';

const BASE = '/waiver/9';
const STATUS = `GET ${BASE}/status`;
const VERIFY = 'POST /login/email-signin/verify-code';

const PORTAL_MARKUP = `
  <div id="sesf-portal-status-msg"></div>
  <div id="sesf-portal-loading"></div>
  <div id="sesf-portal-login"></div>
  <div id="sesf-portal-list" hidden></div>
  <ul id="sesf-portal-people"></ul>
  <p id="sesf-portal-signed-in"></p>
  <dialog id="sesf-waiver-modal">
    <h2 id="sesf-waiver-modal-title">Waiver</h2>
    <iframe id="sesf-waiver-modal-iframe"></iframe>
    <p id="sesf-waiver-modal-foot" hidden></p>
    <button id="sesf-waiver-modal-close" type="button">Close</button>
  </dialog>
  <button id="sesf-tab-password" type="button"></button>
  <button id="sesf-tab-email" type="button"></button>
  <div id="sesf-panel-password"></div>
  <div id="sesf-panel-email" hidden></div>
  <form id="sesf-portal-login-form">
    <input id="sesf-portal-email" />
    <input id="sesf-portal-password" />
    <input id="sesf-portal-remember" type="checkbox" />
  </form>
  <form id="sesf-portal-email-step1"><input id="sesf-portal-email-addr" /></form>
  <form id="sesf-portal-email-step2" hidden><input id="sesf-portal-otp" /></form>
  <button id="sesf-portal-email-restart" type="button"></button>
  <button id="sesf-portal-logout" type="button"></button>
`;

HTMLDialogElement.prototype.showModal = function showModal(this: HTMLDialogElement) {
  this.open = true;
};

const realLocation = window.location;
let location: { origin: string; href: string; reload: () => void };

function mountPortal(): void {
  document.body.innerHTML = PORTAL_MARKUP;
  mountPortalPage(document, BASE);
}

function signingModal(): HTMLDialogElement {
  return document.getElementById('sesf-waiver-modal') as HTMLDialogElement;
}

beforeEach(() => {
  location = { origin: realLocation.origin, href: '', reload: vi.fn() };
  Object.defineProperty(window, 'location', { configurable: true, value: location });
});

afterEach(() => {
  vi.useRealTimers();
  Object.defineProperty(window, 'location', { configurable: true, value: realLocation });
});

describe('portal verifyCode', () => {
  it('navigates to the server-resolved redirect target, not the raw redirect_path', async () => {
    stubFetch({
      [STATUS]: () => jsonResponse(401, []),
      [VERIFY]: () => jsonResponse(200, { user_id: 4, redirect_path: 'members', redirect: '/#/members' }),
    });
    mountPortal();
    (document.getElementById('sesf-portal-email-addr') as HTMLInputElement).value = 'fan@example.test';
    const otp = document.getElementById('sesf-portal-otp') as HTMLInputElement;
    otp.value = '123456';
    otp.dispatchEvent(new Event('input'));

    await vi.waitFor(() => expect(location.href).toBe('/#/members'));
  });
});

describe('portal status', () => {
  it('tells a signed-in member who is not registered for the event, and keeps log out available', async () => {
    stubFetch({
      [STATUS]: () => jsonResponse(403, { error: 'not_registered', message: 'You are not registered for this event.', signedInAs: 'mia@example.test' }),
    });
    mountPortal();

    await vi.waitFor(() => expect(document.getElementById('sesf-portal-status-msg')?.textContent).toBe('You are not registered for this event.'));
    expect(document.getElementById('sesf-portal-list')?.hidden).toBe(false);
    expect(document.getElementById('sesf-portal-signed-in')?.textContent).toBe('Signed in as mia@example.test');
    expect(document.getElementById('sesf-portal-people')?.children).toHaveLength(0);
  });

  it('keeps the generic message for other failures', async () => {
    stubFetch({ [STATUS]: () => jsonResponse(500, {}) });
    mountPortal();

    await vi.waitFor(() => expect(document.getElementById('sesf-portal-status-msg')?.textContent).toContain('could not load your waivers'));
  });
});

describe('portal sign modal message listener', () => {
  it('starts the countdown for a signed message from the modal iframe', () => {
    vi.useFakeTimers();
    stubFetch({ [STATUS]: () => jsonResponse(401, []) });
    mountPortal();
    signingModal().showModal();
    const frame = document.getElementById('sesf-waiver-modal-iframe') as HTMLIFrameElement;

    window.dispatchEvent(new MessageEvent('message', { data: { type: PORTAL_SIGNED_MESSAGE }, origin: location.origin, source: frame.contentWindow }));

    expect(document.getElementById('sesf-waiver-modal-title')?.textContent).toBe('Thank you');
  });

  it('ignores a message with the right origin and type sent from another window', () => {
    vi.useFakeTimers();
    stubFetch({ [STATUS]: () => jsonResponse(401, []) });
    mountPortal();
    signingModal().showModal();

    window.dispatchEvent(new MessageEvent('message', { data: { type: PORTAL_SIGNED_MESSAGE }, origin: location.origin, source: window }));

    expect(document.getElementById('sesf-waiver-modal-title')?.textContent).toBe('Waiver');
    expect(document.getElementById('sesf-waiver-modal-foot')?.hidden).toBe(true);
  });
});
