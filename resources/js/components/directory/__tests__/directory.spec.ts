import { describe, it, expect } from 'vitest';
import { render, screen, fireEvent, within } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { axe } from 'vitest-axe';
import DirectoryFilters, { type FilterField } from '../DirectoryFilters.vue';
import MemberCard from '../MemberCard.vue';
import CarCard from '../CarCard.vue';
import { toCarRow, type CarRow } from '../../../api/cars';
import { toMemberRow, GENERIC_AVATAR_URL, type MemberRow } from '../../../api/members';
import { CAR_ROW, GUEST_ROW, MEMBER_ROW, mountWithRouter } from '../../../pages/members/__tests__/fixtures';
import type { DirectoryParams } from '../../../composables/directory/directoryQuery';

const memberRow = (raw: unknown): MemberRow => toMemberRow(raw) as MemberRow;
const carRow = (raw: unknown): CarRow => toCarRow(raw) as CarRow;

const VALUES: DirectoryParams = { show_id: '', name: '', body_id: '', page: 1 };

const FIELDS: FilterField[] = [
  { kind: 'select', name: 'show_id', label: 'Attended show', options: [{ id: 36, name: 'Show 36' }] },
  { kind: 'text', name: 'name', label: 'Member name', placeholder: 'Start typing' },
];

describe('DirectoryFilters', () => {
  it('emits a select change as a query patch', async () => {
    const user = userEvent.setup();
    const { emitted } = render(DirectoryFilters, { props: { fields: FIELDS, values: VALUES, label: 'Filter members' } });
    await user.selectOptions(screen.getByLabelText('Attended show'), '36');
    expect(emitted('change')).toEqual([[{ show_id: '36' }]]);
  });

  it('emits the trimmed text value on submit only', async () => {
    const user = userEvent.setup();
    const { emitted } = render(DirectoryFilters, { props: { fields: FIELDS, values: VALUES, label: 'Filter members' } });
    const input = screen.getByLabelText('Member name');
    await user.type(input, '  Kai ');
    expect(emitted('change')).toBeUndefined();
    await user.click(screen.getByRole('button', { name: 'Search' }));
    expect(emitted('change')).toEqual([[{ name: 'Kai' }]]);
  });

  it('keeps the text draft in sync with the stored value', async () => {
    const { rerender } = render(DirectoryFilters, { props: { fields: FIELDS, values: VALUES, label: 'Filter members' } });
    await rerender({ fields: FIELDS, values: { ...VALUES, name: 'Shawn' }, label: 'Filter members' });
    expect((screen.getByLabelText('Member name') as HTMLInputElement).value).toBe('Shawn');
  });

  it('renders a labelled search form with the option list and no axe violations', async () => {
    const { container } = render(DirectoryFilters, { props: { fields: FIELDS, values: VALUES, label: 'Filter members' } });
    expect(screen.getByRole('search', { name: 'Filter members' })).toBeTruthy();
    expect(within(screen.getByLabelText('Attended show')).getByRole('option', { name: 'Show 36' })).toBeTruthy();
    expect(await axe(container)).toHaveNoViolations();
  });
});

describe('MemberCard', () => {
  it('renders a guest row with the masked name and state and links to the profile', async () => {
    const { container } = await mountWithRouter(MemberCard, { path: '/', props: { member: memberRow(GUEST_ROW) } });
    expect(screen.getByRole('link', { name: 'Shawn B' }).getAttribute('href')).toBe('/ShawnB/u12');
    expect(screen.getByText('MO')).toBeTruthy();
    expect(container.querySelector('img')?.getAttribute('src')).toBe('/images/users/12.jpg');
  });

  it('renders a member row with the full name and city, state', async () => {
    await mountWithRouter(MemberCard, { path: '/', props: { member: memberRow(MEMBER_ROW) } });
    expect(screen.getByRole('link', { name: 'Kai Xu' })).toBeTruthy();
    expect(screen.getByText('Tampa, FL')).toBeTruthy();
  });

  it('falls back to the generic avatar when there is no photo, and again when the photo breaks', async () => {
    const { container } = await mountWithRouter(MemberCard, {
      path: '/',
      props: { member: memberRow({ ...GUEST_ROW, has_image: false, imageURL: '' }) },
    });
    expect(container.querySelector('img')?.getAttribute('src')).toBe(GENERIC_AVATAR_URL);

    const broken = await mountWithRouter(MemberCard, { path: '/', props: { member: memberRow(GUEST_ROW) } });
    const img = broken.container.querySelector('img') as HTMLImageElement;
    await fireEvent.error(img);
    expect(broken.container.querySelector('img')?.getAttribute('src')).toBe(GENERIC_AVATAR_URL);
  });

  it('shows plain text without a link when there is no profile path', async () => {
    render(MemberCard, { props: { member: { ...memberRow(MEMBER_ROW)!, profilePath: '' } }, global: {} });
    expect(screen.queryByRole('link')).toBeNull();
    expect(screen.getByRole('heading', { level: 2, name: 'Kai Xu' })).toBeTruthy();
  });
});

describe('CarCard', () => {
  it('links the title to the car profile and shows the owner', async () => {
    await mountWithRouter(CarCard, { path: '/', props: { car: carRow(CAR_ROW) } });
    expect(screen.getByRole('link', { name: 'Sharky' }).getAttribute('href')).toBe('/car/78');
    expect(screen.getByText('1987 M635CSi')).toBeTruthy();
    expect(screen.getByText('Owner: Shawn B')).toBeTruthy();
  });

  it('falls back to year and model, then to the id, and uses the generic image when broken', async () => {
    await mountWithRouter(CarCard, { path: '/', props: { car: carRow({ ...CAR_ROW, nickname: '', user: null }) } });
    expect(screen.getByRole('link', { name: '1987 M635CSi' })).toBeTruthy();
    expect(screen.queryByText(/Owner:/)).toBeNull();

    const blank = await mountWithRouter(CarCard, {
      path: '/',
      props: { car: carRow({ ...CAR_ROW, nickname: '', year: '', model: '', imageURL: '' }) },
    });
    expect(screen.getByRole('link', { name: 'Car 78' })).toBeTruthy();
    expect(blank.container.querySelector('img')?.getAttribute('src')).toBe(GENERIC_AVATAR_URL);

    const broken = await mountWithRouter(CarCard, { path: '/', props: { car: carRow(CAR_ROW) } });
    await fireEvent.error(broken.container.querySelector('img') as HTMLImageElement);
    expect(broken.container.querySelector('img')?.getAttribute('src')).toBe(GENERIC_AVATAR_URL);
  });
});
