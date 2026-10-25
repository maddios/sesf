import { describe, it, expect, afterEach, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import {
  loadTurnstile,
  resetTurnstileLoader,
  TURNSTILE_SCRIPT_URL,
  type TurnstileApi,
  type TurnstileRenderOptions,
} from '../turnstileLoader';
import TurnstileWidget from '../TurnstileWidget.vue';

function fakeTurnstile() {
  const options: TurnstileRenderOptions[] = [];
  const api: TurnstileApi = {
    render: vi.fn((_el: HTMLElement, opts: TurnstileRenderOptions) => {
      options.push(opts);
      return 'widget-1';
    }),
    reset: vi.fn(),
  };
  window.turnstile = api;
  return { api, options };
}

function scriptTags(): HTMLScriptElement[] {
  return Array.from(document.querySelectorAll<HTMLScriptElement>(`script[src="${TURNSTILE_SCRIPT_URL}"]`));
}

afterEach(() => {
  resetTurnstileLoader();
  delete window.turnstile;
  scriptTags().forEach((tag) => tag.remove());
  vi.restoreAllMocks();
});

describe('loadTurnstile', () => {
  it('adds the explicit-render script once and resolves when it reports ready', async () => {
    const first = loadTurnstile();
    const second = loadTurnstile();
    expect(scriptTags()).toHaveLength(1);
    expect(TURNSTILE_SCRIPT_URL).toBe('https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit');

    const { api } = fakeTurnstile();
    scriptTags()[0]?.dispatchEvent(new Event('load'));
    await expect(first).resolves.toBe(api);
    await expect(second).resolves.toBe(api);
  });

  it('resolves immediately when the API is already present, without adding a script', async () => {
    const { api } = fakeTurnstile();
    await expect(loadTurnstile()).resolves.toBe(api);
    expect(scriptTags()).toHaveLength(0);
  });

  it('rejects and removes the tag when the script fails, then retries on the next call', async () => {
    const failing = loadTurnstile();
    scriptTags()[0]?.dispatchEvent(new Event('error'));
    await expect(failing).rejects.toThrow('failed to load');
    expect(scriptTags()).toHaveLength(0);

    const retry = loadTurnstile();
    expect(scriptTags()).toHaveLength(1);
    const { api } = fakeTurnstile();
    scriptTags()[0]?.dispatchEvent(new Event('load'));
    await expect(retry).resolves.toBe(api);
  });

  it('rejects when the script loads but no API is defined', async () => {
    const pending = loadTurnstile();
    scriptTags()[0]?.dispatchEvent(new Event('load'));
    await expect(pending).rejects.toThrow('without its API');
  });
});

describe('TurnstileWidget', () => {
  it('renders explicitly with the site key and emits the token from the callback', async () => {
    const { api, options } = fakeTurnstile();
    const wrapper = mount(TurnstileWidget, { props: { siteKey: 'site-key-1' }, attachTo: document.body });
    await flushPromises();

    expect(api.render).toHaveBeenCalledTimes(1);
    expect(options[0]).toMatchObject({ sitekey: 'site-key-1', theme: 'light' });
    expect(wrapper.find('p').exists()).toBe(false);

    options[0]?.callback?.('token-abc');
    expect(wrapper.emitted('update:token')).toEqual([['token-abc']]);
    wrapper.unmount();
  });

  it('emits null when the token expires or the widget errors, and reports the error state', async () => {
    const { options } = fakeTurnstile();
    const wrapper = mount(TurnstileWidget, { props: { siteKey: 'k' }, attachTo: document.body });
    await flushPromises();

    options[0]?.['expired-callback']?.();
    options[0]?.['error-callback']?.();
    expect(wrapper.emitted('update:token')).toEqual([[null], [null]]);
    await flushPromises();
    expect(wrapper.get('[role="alert"]').text()).toContain('could not load');
    wrapper.unmount();
  });

  it('reset() clears the widget and the held token', async () => {
    const { api } = fakeTurnstile();
    const wrapper = mount(TurnstileWidget, { props: { siteKey: 'k' }, attachTo: document.body });
    await flushPromises();

    (wrapper.vm as unknown as { reset: () => void }).reset();
    expect(api.reset).toHaveBeenCalledWith('widget-1');
    expect(wrapper.emitted('update:token')).toEqual([[null]]);
    wrapper.unmount();
  });

  it('reset() tolerates a widget API that throws, and works before rendering', async () => {
    const wrapper = mount(TurnstileWidget, { props: { siteKey: 'k' } });
    // Before the API has loaded there is nothing to reset: only the token is cleared.
    (wrapper.vm as unknown as { reset: () => void }).reset();
    expect(wrapper.emitted('update:token')).toEqual([[null]]);
    wrapper.unmount();

    const { api } = fakeTurnstile();
    vi.mocked(api.reset).mockImplementation(() => {
      throw new Error('gone');
    });
    const mounted = mount(TurnstileWidget, { props: { siteKey: 'k' }, attachTo: document.body });
    await flushPromises();
    expect(() => (mounted.vm as unknown as { reset: () => void }).reset()).not.toThrow();
    mounted.unmount();
  });

  it('shows the loading fallback text until the widget is ready, and an alert when the script fails', async () => {
    const wrapper = mount(TurnstileWidget, { props: { siteKey: 'k' } });
    expect(wrapper.text()).toContain('Loading the security check');
    scriptTags()[0]?.dispatchEvent(new Event('error'));
    await flushPromises();
    expect(wrapper.get('[role="alert"]').text()).toContain('Reload the page');
    wrapper.unmount();
  });

  it('does not render after unmount if the script arrives late', async () => {
    const { api } = fakeTurnstile();
    const wrapper = mount(TurnstileWidget, { props: { siteKey: 'k' } });
    wrapper.unmount();
    await flushPromises();
    expect(api.render).not.toHaveBeenCalled();
  });
});
