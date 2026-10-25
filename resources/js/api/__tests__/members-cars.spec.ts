import { describe, it, expect, afterEach } from 'vitest';
import { vi } from 'vitest';
import { ApiError } from '../client';
import {
  asRecordOf,
  fetchCarFilters,
  fetchCarList,
  fetchCarProfile,
  toBool,
  toCarProfile,
  toCarRow,
  toCarSummary,
  toImageUrl,
  toNumber,
  toRoutePath,
  toText,
} from '../cars';
import {
  fetchMemberFilters,
  fetchMemberList,
  fetchMemberProfile,
  sendMemberMessage,
  toMemberProfile,
  toMemberRow,
} from '../members';
import {
  CAR_FILTERS,
  CAR_PROFILE_OWNER,
  CAR_PROFILE_PUBLIC,
  CAR_ROW,
  GUEST_ROW,
  MEMBER_FILTERS,
  MEMBER_PROFILE_OWNER,
  MEMBER_PROFILE_PUBLIC,
  MEMBER_ROW,
  fetchWith,
  jsonResponse,
  pageBody,
  requested,
} from '../../pages/members/__tests__/fixtures';

afterEach(() => {
  vi.unstubAllGlobals();
});

describe('shared normalisers', () => {
  it('coerces text, numbers and booleans from legacy values', () => {
    expect(toText('  x ')).toBe('x');
    expect(toText(4)).toBe('4');
    expect(toText(Number.NaN)).toBe('');
    expect(toText(null)).toBe('');
    expect(toNumber('12')).toBe(12);
    expect(toNumber('abc')).toBeNull();
    expect(toNumber(true)).toBeNull();
    expect(toBool('1')).toBe(true);
    expect(toBool('true')).toBe(true);
    expect(toBool(0)).toBe(false);
    expect(asRecordOf([1])).toEqual({});
  });

  it('turns hash profile URLs into router paths and drops unsafe values', () => {
    expect(toRoutePath('#/ShawnB/u44')).toBe('/ShawnB/u44');
    expect(toRoutePath('/car/78')).toBe('/car/78');
    expect(toRoutePath('//evil.example')).toBe('');
    expect(toRoutePath('javascript:alert(1)')).toBe('');
    expect(toRoutePath(undefined)).toBe('');
  });

  it('allows only legacy image paths and https images', () => {
    expect(toImageUrl('images/cars/3.jpg')).toBe('/images/cars/3.jpg');
    expect(toImageUrl('/images/cars/3.jpg')).toBe('/images/cars/3.jpg');
    expect(toImageUrl('https://cdn.example/x.png')).toBe('https://cdn.example/x.png');
    expect(toImageUrl('images/../secret.txt')).toBe('');
    expect(toImageUrl('http://insecure.example/x.png')).toBe('');
    expect(toImageUrl('')).toBe('');
  });
});

describe('member normalisers', () => {
  it('maps a guest row and a member row, and rejects rows without an id', () => {
    expect(toMemberRow(GUEST_ROW)).toMatchObject({ id: 12, fullName: 'Shawn B', cityState: 'MO', profilePath: '/ShawnB/u12', hasImage: true });
    expect(toMemberRow(MEMBER_ROW)).toMatchObject({ id: 44, fullName: 'Kai Xu', cityState: 'Tampa, FL', hasImage: false });
    expect(toMemberRow({ fullName: 'No id' })).toBeNull();
  });

  it('falls back to the first name and last initial when no full name is sent', () => {
    expect(toMemberRow({ id: 3, firstNameLastInitial: 'Al B' })?.fullName).toBe('Al B');
  });

  it('normalises a member profile and drops events without an id', () => {
    const profile = toMemberProfile({
      ...MEMBER_PROFILE_OWNER,
      event_list: { num_events: '2', events: [{ id: 9, showName: 'Show', cars: [{ id: 2, name: 'B' }] }, { nope: true }] },
    });
    expect(profile.isOwner).toBe(true);
    expect(profile.fullName).toBe('Kai Xu');
    expect(profile.carCount).toBe(4);
    expect(profile.eventCount).toBe(2);
    expect(profile.events).toHaveLength(1);
    expect(profile.events[0]?.cars[0]?.id).toBe(2);
    expect(profile.allowPrivateMessage).toBe(true);
  });

  it('degrades missing profile sections to empty values', () => {
    const profile = toMemberProfile({});
    expect(profile).toMatchObject({ isOwner: false, fullName: '', carCount: 0, cars: [], eventCount: 0, events: [], allowPrivateMessage: false });
    expect(toMemberProfile({ member_info: { imageURL: 'images/users/1.jpg' } }).hasImage).toBe(true);
    expect(toMemberProfile({ event_list: { events: [{ id: 1, carShow: { name: 'From show' } }] } }).events[0]?.showName).toBe('From show');
    expect(toMemberProfile({ event_list: { events: [{ id: 1, carShow: null }] } }).events[0]?.show).toBeNull();
  });

  it('loads filters, the directory list and the profile with the expected query strings', async () => {
    const fn = fetchWith((url, init) => {
      if (url.pathname === '/members/list-filters') {
        return jsonResponse(200, [...MEMBER_FILTERS, { type: 'dropdown', name: 'x', label: 'x', data: [{ id: 0 }, { id: 3, name: 'Three' }], value: 5 }]);
      }
      if (url.pathname === '/members/users') {
        return jsonResponse(200, pageBody([GUEST_ROW, { nope: true }], { last: 4, page: 2, total: 80 }));
      }
      if (url.pathname === '/member/profile') {
        return jsonResponse(200, MEMBER_PROFILE_PUBLIC);
      }
      if (url.pathname === '/member/message') {
        expect(init.method).toBe('POST');
        return jsonResponse(200, '');
      }
      return jsonResponse(404, {});
    });

    const filters = await fetchMemberFilters();
    expect(filters[0]?.data.map((o) => o.name)).toEqual(['Southeast Sharkfest 2026', 'Southeast Sharkfest 2025']);
    expect(filters[1]?.type).toBe('textbox');
    expect(filters[2]).toMatchObject({ type: 'dropdown', value: '5' });
    expect(filters[2]?.data).toEqual([{ id: 3, name: 'Three' }]);

    const list = await fetchMemberList({ showId: 36, name: 'Sh', page: 2, limit: 20 });
    expect(list).toMatchObject({ last: 4, page: 2, total: 80, perPage: 20 });
    expect(list.data).toHaveLength(1);
    expect(requested(fn)).toContain('/members/users?limit=20&page=2&show_id=36&name=Sh');

    await fetchMemberList({ page: 1, limit: 25 });
    expect(requested(fn)).toContain('/members/users?limit=25&page=1');

    const profile = await fetchMemberProfile(44);
    expect(profile.fullName).toBe('Kai X');
    expect(requested(fn)).toContain('/member/profile?user_id=44');

    await sendMemberMessage(44, 'Hi', 'There');
    expect(fn.mock.calls.at(-1)?.[1]?.body).toBe(JSON.stringify({ user_id: 44, subject: 'Hi', message: 'There' }));
  });

  it('returns empty filters and fills missing list metadata from the request', async () => {
    fetchWith((url) => (url.pathname === '/members/list-filters' ? jsonResponse(200, { not: 'a list' }) : jsonResponse(200, { data: 'x' })));
    expect(await fetchMemberFilters()).toEqual([]);
    expect(await fetchMemberList({ page: 3, limit: 20 })).toEqual({ last: 1, page: 3, perPage: 20, data: [], total: 0 });
  });

  it('rejects an unknown member with ApiError 404', async () => {
    fetchWith(() => jsonResponse(404, { message: 'Not found.' }));
    await expect(fetchMemberProfile(9)).rejects.toBeInstanceOf(ApiError);
  });
});

describe('car normalisers', () => {
  it('maps a car row with its owner and rejects rows without an id', () => {
    expect(toCarRow(CAR_ROW)).toMatchObject({ id: 78, nickname: 'Sharky', profilePath: '/car/78', bodyId: 2, owner: { fullName: 'Shawn B' } });
    expect(toCarRow({ name: 'x' })).toBeNull();
    expect(toCarRow({ id: 1, user: null })?.owner).toBeNull();
  });

  it('maps an owner with a profile image and falls back to the initial when there is no full name', () => {
    expect(toCarRow({ id: 1, user: { firstNameLastInitial: 'Al B', profileImageURL: 'images/u.jpg' } })?.owner).toMatchObject({
      fullName: 'Al B',
      hasImage: true,
      imageUrl: '/images/u.jpg',
    });
  });

  it('maps a car summary and skips shows without a name', () => {
    expect(toCarSummary({ id: 'x' })).toBeNull();
    expect(toCarSummary({ id: 5, body: 'Coupe', shows: [{ name: 'S', profileURL: '#/s', imageURL: 'images/s.jpg' }, { name: '' }] })).toMatchObject({
      id: 5,
      body: 'Coupe',
      shows: [{ name: 'S', profilePath: '/s', imageUrl: '/images/s.jpg' }],
    });
    expect(toCarSummary({ id: 6 })?.shows).toEqual([]);
  });

  it('maps the owner view (id, body and type objects, judging link) and the public view (type.type)', () => {
    const owner = toCarProfile(CAR_PROFILE_OWNER, 78);
    expect(owner).toMatchObject({ isOwner: true, id: 78, nickname: 'Sharky', bodyName: 'Coupe', typeName: 'Sport', owner: null });
    expect(owner.shows[0]?.judgingLink).toBe('#/SoutheastSharkfest2026/judging');

    const publicView = toCarProfile(CAR_PROFILE_PUBLIC, 78);
    expect(publicView).toMatchObject({ isOwner: false, id: 78, bodyName: 'Coupe', typeName: 'Sport' });
    expect(publicView.owner).toMatchObject({ fullName: 'Shawn B', profilePath: '/ShawnB/u12' });
    expect(publicView.shows[0]?.judgingLink).toBeNull();
    expect(toCarProfile({ shows: [{ name: '' }], judgingLink: '' }, 1).shows).toEqual([]);
  });

  it('loads filters, the car list and the car profile with the expected query strings', async () => {
    const fn = fetchWith((url) => {
      if (url.pathname === '/cars/list-filters') {
        return jsonResponse(200, CAR_FILTERS);
      }
      if (url.pathname === '/cars/list') {
        return jsonResponse(200, pageBody([CAR_ROW], { last: 2, page: 1, total: 21 }));
      }
      if (url.pathname === '/car/profile') {
        return jsonResponse(200, CAR_PROFILE_PUBLIC);
      }
      return jsonResponse(404, {});
    });

    const filters = await fetchCarFilters();
    expect(filters.map((f) => f.name)).toEqual(['show_id', 'body_id']);

    const list = await fetchCarList({ showId: 36, bodyId: 2, page: 1, limit: 20 });
    expect(list.data[0]?.id).toBe(78);
    expect(requested(fn)).toContain('/cars/list?limit=20&page=1&show_id=36&body_id=2');
    await fetchCarList({ page: 2, limit: 25 });
    expect(requested(fn)).toContain('/cars/list?limit=25&page=2');

    const profile = await fetchCarProfile(78);
    expect(profile.bodyName).toBe('Coupe');
    expect(requested(fn)).toContain('/car/profile?car_id=78');
  });

  it('returns empty filters for a non-list body and rejects an unknown car with ApiError 404', async () => {
    fetchWith((url) => (url.pathname === '/cars/list-filters' ? jsonResponse(200, {}) : jsonResponse(404, {})));
    expect(await fetchCarFilters()).toEqual([]);
    await expect(fetchCarProfile(1)).rejects.toMatchObject({ status: 404 });
  });

  it('keeps unknown filter types as dropdowns and drops option rows without an id', async () => {
    fetchWith(() => jsonResponse(200, [{ type: 'weird', name: 'n', label: 'l', data: [{ id: 'x' }, { id: 2, name: 'Two' }], value: null }]));
    const [filter] = await fetchCarFilters();
    expect(filter).toMatchObject({ type: 'dropdown', value: null, data: [{ id: 2, name: 'Two' }] });
  });
});
