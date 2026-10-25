import { describe, it, expect, afterEach } from 'vitest';
import { APP_CONFIG_ELEMENT_ID, getForumUrl } from './config';

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
});
