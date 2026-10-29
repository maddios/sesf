import { describe, it, expect, afterEach } from 'vitest';
import { APP_CONFIG_ELEMENT_ID, getAppUrl, getForumUrl, getMapsKey, getPaypalEmail, getShopUrl, getTurnstileSiteKey } from './config';

function addConfigBlock(text: string) {
  const script = document.createElement('script');
  script.id = APP_CONFIG_ELEMENT_ID;
  script.type = 'application/json';
  script.textContent = text;
  document.body.appendChild(script);
}

afterEach(() => {
  document.getElementById(APP_CONFIG_ELEMENT_ID)?.remove();
  delete window.__SESF__;
});

describe('getForumUrl', () => {
  it('is empty when nothing is configured', () => {
    expect(getForumUrl()).toBe('');
  });

  it('reads window.__SESF__.forumUrl', () => {
    window.__SESF__ = { forumUrl: 'https://forum.example.test/' };
    expect(getForumUrl()).toBe('https://forum.example.test/');
  });

  it('reads the JSON block rendered by the Laravel shell', () => {
    addConfigBlock('{"forumUrl":"https://forum.example.test/"}');
    expect(getForumUrl()).toBe('https://forum.example.test/');
  });

  it('prefers window.__SESF__ over the JSON block', () => {
    addConfigBlock('{"forumUrl":"https://block.example.test/"}');
    window.__SESF__ = { forumUrl: 'https://window.example.test/' };
    expect(getForumUrl()).toBe('https://window.example.test/');
  });

  it('ignores non-http URLs', () => {
    window.__SESF__ = { forumUrl: 'javascript:alert(1)' };
    expect(getForumUrl()).toBe('');
  });

  it('ignores a non-string value', () => {
    window.__SESF__ = { forumUrl: 42 };
    expect(getForumUrl()).toBe('');
  });

  it('is empty when the JSON block is malformed', () => {
    addConfigBlock('{not json');
    expect(getForumUrl()).toBe('');
  });

  it('is empty when the JSON block is not an object', () => {
    addConfigBlock('"just a string"');
    expect(getForumUrl()).toBe('');
  });

  it('is empty when the JSON block is empty text', () => {
    addConfigBlock('');
    expect(getForumUrl()).toBe('');
  });
});

describe('getShopUrl', () => {
  it('is empty when nothing is configured', () => {
    expect(getShopUrl()).toBe('');
  });

  it('reads window.__SESF__.shopUrl', () => {
    window.__SESF__ = { shopUrl: 'https://shop.example.test/' };
    expect(getShopUrl()).toBe('https://shop.example.test/');
  });

  it('reads the JSON block and ignores the forum key', () => {
    addConfigBlock('{"forumUrl":"https://forum.example.test/","shopUrl":"https://shop.example.test/"}');
    expect(getShopUrl()).toBe('https://shop.example.test/');
    expect(getForumUrl()).toBe('https://forum.example.test/');
  });

  it('is empty when the Blade shell does not send shopUrl', () => {
    addConfigBlock('{"forumUrl":"https://forum.example.test/"}');
    expect(getShopUrl()).toBe('');
  });

  it('ignores non-http URLs', () => {
    window.__SESF__ = { shopUrl: 'data:text/html,hi' };
    expect(getShopUrl()).toBe('');
  });
});

describe('getTurnstileSiteKey', () => {
  it('is empty when no key is configured (dev notice state)', () => {
    expect(getTurnstileSiteKey()).toBe('');
  });

  it('reads the key from the JSON block and trims whitespace', () => {
    addConfigBlock('{"turnstileSiteKey":"  0x4AAAA-test_key  "}');
    expect(getTurnstileSiteKey()).toBe('0x4AAAA-test_key');
  });

  it('prefers window.__SESF__', () => {
    addConfigBlock('{"turnstileSiteKey":"block-key"}');
    window.__SESF__ = { turnstileSiteKey: 'window-key' };
    expect(getTurnstileSiteKey()).toBe('window-key');
  });

  it('rejects values that are not plain public-key tokens', () => {
    window.__SESF__ = { turnstileSiteKey: '"><script>' };
    expect(getTurnstileSiteKey()).toBe('');
    window.__SESF__ = { turnstileSiteKey: 12345 };
    expect(getTurnstileSiteKey()).toBe('');
  });
});

describe('getMapsKey', () => {
  it('is empty when not configured', () => {
    expect(getMapsKey()).toBe('');
  });

  it('reads the JSON block key', () => {
    addConfigBlock('{"mapsKey":"AIza-test_key"}');
    expect(getMapsKey()).toBe('AIza-test_key');
  });

  it('rejects whitespace-only and overlong values', () => {
    window.__SESF__ = { mapsKey: '   ' };
    expect(getMapsKey()).toBe('');
    window.__SESF__ = { mapsKey: 'a'.repeat(201) };
    expect(getMapsKey()).toBe('');
  });
});

describe('getPaypalEmail', () => {
  it('is empty when not configured', () => {
    expect(getPaypalEmail()).toBe('');
  });

  it('reads the address from the JSON block and trims whitespace', () => {
    addConfigBlock('{"paypalEmail":"  pay@example.test  "}');
    expect(getPaypalEmail()).toBe('pay@example.test');
  });

  it('prefers window.__SESF__', () => {
    addConfigBlock('{"paypalEmail":"block@example.test"}');
    window.__SESF__ = { paypalEmail: 'window@example.test' };
    expect(getPaypalEmail()).toBe('window@example.test');
  });

  it('rejects markup, non-strings, missing @ and overlong values', () => {
    window.__SESF__ = { paypalEmail: '"><script>@x' };
    expect(getPaypalEmail()).toBe('');
    window.__SESF__ = { paypalEmail: 42 };
    expect(getPaypalEmail()).toBe('');
    window.__SESF__ = { paypalEmail: 'no-at-sign' };
    expect(getPaypalEmail()).toBe('');
    window.__SESF__ = { paypalEmail: `${'a'.repeat(250)}@example.test` };
    expect(getPaypalEmail()).toBe('');
  });
});

describe('getAppUrl', () => {
  it('is empty when not configured', () => {
    expect(getAppUrl()).toBe('');
  });

  it('reads the URL from the JSON block without a trailing slash', () => {
    addConfigBlock('{"appUrl":"https://sesf.example.test///"}');
    expect(getAppUrl()).toBe('https://sesf.example.test');
  });

  it('keeps a path prefix and prefers window.__SESF__', () => {
    addConfigBlock('{"appUrl":"https://block.example.test"}');
    window.__SESF__ = { appUrl: 'https://window.example.test/sub/' };
    expect(getAppUrl()).toBe('https://window.example.test/sub');
  });

  it('ignores non-http URLs and non-strings', () => {
    window.__SESF__ = { appUrl: 'javascript:alert(1)' };
    expect(getAppUrl()).toBe('');
    window.__SESF__ = { appUrl: 7 };
    expect(getAppUrl()).toBe('');
  });
});
