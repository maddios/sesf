import { describe, it, expect, vi, beforeEach } from 'vitest';
import { createPinia, setActivePinia } from 'pinia';
import { createMemoryHistory } from 'vue-router';
import {
  applyPostLoginRedirect,
  firstQueryString,
  isSafeInternalPath,
  resolvePostLoginRedirect,
} from '../postLogin';
import { createAppRouter } from '../index';
import { useAuthStore } from '../../stores/auth';
import { stubFetch } from '../../test/helpers';

const FORUM = 'https://forum.example.test/';

describe('resolvePostLoginRedirect', () => {
  it.each<[string, string | undefined, unknown]>([
    ['no value goes home', undefined, { kind: 'route', path: '/' }],
    ['empty string goes home', '', { kind: 'route', path: '/' }],
    ['forum goes to the forum', 'forum', { kind: 'page', url: FORUM }],
    ['waiver goes by full page load', '/waiver/12', { kind: 'page', url: '/waiver/12' }],
    ['judging goes by full page load', '/judging/spring-e3', { kind: 'page', url: '/judging/spring-e3' }],
    ['judging prefix alone is a hash route', '/judging/', { kind: 'route', path: '/judging/' }],
    ['judging answers go by full page load', '/judging_answers/IOgXHXRZVkIlpct3D', { kind: 'page', url: '/judging_answers/IOgXHXRZVkIlpct3D' }],
    ['judging draw goes by full page load', '/judging_draw/12', { kind: 'page', url: '/judging_draw/12' }],
    ['member page is a hash route', '/fan/u7', { kind: 'route', path: '/fan/u7' }],
    ['query string is kept', '/members?tab=cars&x=1', { kind: 'route', path: '/members?tab=cars&x=1' }],
    ['percent-encoded text is allowed', '/cars%20list', { kind: 'route', path: '/cars%20list' }],
    ['absolute https URL goes home', 'https://evil.com', { kind: 'route', path: '/' }],
    ['absolute https URL with waiver path goes home', 'https://evil.com/waiver/1', { kind: 'route', path: '/' }],
    ['protocol-relative URL goes home', '//evil.com', { kind: 'route', path: '/' }],
    ['triple slash goes home', '///evil.com', { kind: 'route', path: '/' }],
    ['backslash trick goes home', '/\\evil.com', { kind: 'route', path: '/' }],
    ['javascript scheme goes home', 'javascript:alert(1)', { kind: 'route', path: '/' }],
    ['double slash inside a waiver path goes home', '/waiver//evil.com', { kind: 'route', path: '/' }],
    ['leading space goes home', ' /members', { kind: 'route', path: '/' }],
    ['trailing newline goes home', '/members\n', { kind: 'route', path: '/' }],
    ['relative path goes home', 'members', { kind: 'route', path: '/' }],
    ['markup goes home', '/<script>', { kind: 'route', path: '/' }],
  ])('%s', (_label, input, expected) => {
    expect(resolvePostLoginRedirect(input, FORUM)).toEqual(expected);
  });

  it('forum goes home when no forum URL is configured', () => {
    expect(resolvePostLoginRedirect('forum', '')).toEqual({ kind: 'route', path: '/' });
  });
});

describe('isSafeInternalPath', () => {
  it.each([
    ['/', true],
    ['/members', true],
    ['https://evil.com', false],
    ['//evil.com', false],
    ['/a//b', false],
    ['/\\evil.com', false],
    ['javascript:alert(1)', false],
  ])('%s -> %s', (value, expected) => {
    expect(isSafeInternalPath(value)).toBe(expected);
  });
});

describe('firstQueryString', () => {
  it('returns strings and the first of a repeated parameter', () => {
    expect(firstQueryString('/a')).toBe('/a');
    expect(firstQueryString(['/a', '/b'])).toBe('/a');
  });

  it('ignores empty arrays, null and non-strings', () => {
    expect(firstQueryString([])).toBeUndefined();
    expect(firstQueryString(null)).toBeUndefined();
    expect(firstQueryString(3)).toBeUndefined();
  });
});

describe('applyPostLoginRedirect', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    stubFetch();
  });

  it('navigates the hash router for a route target', async () => {
    const router = createAppRouter(createMemoryHistory(), () => useAuthStore());
    await applyPostLoginRedirect(router, { kind: 'route', path: '/members' });
    expect(router.currentRoute.value.path).toBe('/members');
  });

  it('calls the full-page navigator for a page target and leaves the router alone', async () => {
    const router = createAppRouter(createMemoryHistory(), () => useAuthStore());
    const navigate = vi.fn();
    await applyPostLoginRedirect(router, { kind: 'page', url: '/waiver/3' }, navigate);
    expect(navigate).toHaveBeenCalledWith('/waiver/3');
    expect(router.currentRoute.value.path).toBe('/');
  });
});
