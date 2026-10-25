/* eslint-disable vue/one-component-per-file -- small inline harness components */
import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { axe } from 'vitest-axe';
import { createMemoryHistory, createRouter } from 'vue-router';
import { defineComponent, h } from 'vue';
import BaseButton from '../BaseButton.vue';
import BaseCard from '../BaseCard.vue';
import BaseAlert from '../BaseAlert.vue';
import BaseSpinner from '../BaseSpinner.vue';
import SafeImage from '../SafeImage.vue';
import PlaceholderImage from '../PlaceholderImage.vue';

function testRouter() {
  const Empty = defineComponent({ render: () => null });
  return createRouter({ history: createMemoryHistory(), routes: [{ path: '/:p(.*)*', component: Empty }] });
}

describe('BaseButton', () => {
  it('is a plain button of the default variant by default', () => {
    render(BaseButton, { slots: { default: 'Save' } });
    const button = screen.getByRole('button', { name: 'Save' });
    expect(button.getAttribute('type')).toBe('button');
    expect(button.className).toContain('bg-default');
  });

  it.each([
    ['primary', 'bg-primary'],
    ['danger', 'bg-danger'],
    ['link', 'underline'],
  ] as const)('applies the %s variant', (variant, cls) => {
    render(BaseButton, { props: { variant }, slots: { default: 'Go' } });
    expect(screen.getByRole('button', { name: 'Go' }).className).toContain(cls);
  });

  it('applies sizes and block width', () => {
    render(BaseButton, { props: { size: 'lg', block: true }, slots: { default: 'Big' } });
    const cls = screen.getByRole('button', { name: 'Big' }).className;
    expect(cls).toContain('text-base');
    expect(cls).toContain('w-full');
  });

  it('is disabled, busy and announces the loading label while loading', () => {
    render(BaseButton, { props: { loading: true, loadingLabel: 'Saving' }, slots: { default: 'Save' } });
    const button = screen.getByRole('button', { name: /Save/ }) as HTMLButtonElement;
    expect(button.disabled).toBe(true);
    expect(button.getAttribute('aria-busy')).toBe('true');
    expect(screen.getByText('Saving').className).toContain('sr-only');
    expect(button.querySelector('svg')?.getAttribute('aria-hidden')).toBe('true');
  });

  it('emits clicks', async () => {
    const onClick = vi.fn();
    render(BaseButton, { attrs: { onClick }, slots: { default: 'Hit' } });
    await userEvent.click(screen.getByRole('button', { name: 'Hit' }));
    expect(onClick).toHaveBeenCalledTimes(1);
  });

  it('renders a link when given href, and a router link when given to', async () => {
    const router = testRouter();
    await router.push('/');
    render(
      defineComponent({
        render: () => [
          h(BaseButton, { href: '#/register' }, () => 'Register'),
          h(BaseButton, { to: '/events', variant: 'primary' }, () => 'Events'),
        ],
      }),
      { global: { plugins: [router] } },
    );
    expect(screen.getByRole('link', { name: 'Register' }).getAttribute('href')).toBe('#/register');
    expect(screen.getByRole('link', { name: 'Events' }).getAttribute('href')).toBe('/events');
  });

  it('has no detectable accessibility violations', async () => {
    const { container } = render(BaseButton, { props: { variant: 'primary' }, slots: { default: 'Save' } });
    expect(await axe(container)).toHaveNoViolations();
  });
});

describe('BaseCard', () => {
  it('labels the section with its title heading', () => {
    render(BaseCard, { props: { title: 'Garage', headingLevel: 3 }, slots: { default: 'Cars here', footer: 'Footer' } });
    const region = screen.getByRole('region', { name: 'Garage' });
    expect(region.textContent).toContain('Cars here');
    expect(screen.getByRole('heading', { level: 3, name: 'Garage' })).toBeTruthy();
    expect(screen.getByText('Footer')).toBeTruthy();
  });

  it('renders without a heading, as a plain div', () => {
    const { container } = render(BaseCard, { props: { as: 'div', padded: false }, slots: { default: 'Body' } });
    expect(container.querySelector('h2')).toBeNull();
    expect(container.firstElementChild?.tagName).toBe('DIV');
    expect(container.firstElementChild?.getAttribute('aria-labelledby')).toBeNull();
  });

  it('has no detectable accessibility violations', async () => {
    const { container } = render(BaseCard, { props: { title: 'Card' }, slots: { default: 'Body' } });
    expect(await axe(container)).toHaveNoViolations();
  });
});

describe('BaseAlert', () => {
  it('is an alert for errors', () => {
    render(BaseAlert, { slots: { default: 'Broken.' } });
    expect(screen.getByRole('alert').textContent).toContain('Broken.');
  });

  it.each(['success', 'info', 'warning'] as const)('is a status region for %s', (variant) => {
    render(BaseAlert, { props: { variant, title: 'Heads up' }, slots: { default: 'Body' } });
    const status = screen.getByRole('status');
    expect(status.getAttribute('aria-live')).toBe('polite');
    expect(status.textContent).toContain('Heads up');
  });

  it('can be dismissed', async () => {
    const { emitted } = render(BaseAlert, { props: { dismissible: true }, slots: { default: 'x' } });
    await userEvent.click(screen.getByRole('button', { name: 'Dismiss message' }));
    expect(emitted().dismiss).toHaveLength(1);
  });

  it('has no detectable accessibility violations', async () => {
    const { container } = render(BaseAlert, { props: { variant: 'success', dismissible: true }, slots: { default: 'Saved.' } });
    expect(await axe(container)).toHaveNoViolations();
  });
});

describe('BaseSpinner', () => {
  it('is decorative without a label', () => {
    const { container } = render(BaseSpinner);
    expect(screen.queryByRole('status')).toBeNull();
    expect(container.querySelector('svg')?.getAttribute('aria-hidden')).toBe('true');
  });

  it('is an announced status with a label', () => {
    render(BaseSpinner, { props: { label: 'Loading events', size: 'lg' } });
    expect(screen.getByRole('status').textContent).toContain('Loading events');
  });
});

describe('SafeImage and PlaceholderImage', () => {
  it('renders the image while it loads fine', () => {
    render(SafeImage, { props: { src: '/images/logo.jpg', alt: 'Odometer Gears' } });
    const img = screen.getByRole('img', { name: 'Odometer Gears' });
    expect(img.tagName).toBe('IMG');
    expect(img.getAttribute('loading')).toBe('lazy');
  });

  it('swaps a broken image for a tile with the name as text, not raw alt text', async () => {
    const { container, emitted } = render(SafeImage, { props: { src: '/images/missing.jpg', alt: 'Angry Ass' } });
    await fireEvent.error(container.querySelector('img') as HTMLImageElement);
    expect(container.querySelector('img')).toBeNull();
    const tile = screen.getByRole('img', { name: 'Angry Ass' });
    expect(tile.hasAttribute('data-placeholder')).toBe(true);
    expect(tile.textContent).toContain('Angry Ass');
    expect(emitted().error).toHaveLength(1);
  });

  it('shows the tile at once when there is no source, and retries when the source changes', async () => {
    const { container, rerender } = render(SafeImage, { props: { src: '', alt: 'Auto Rescue', placeholder: 'Auto Rescue Inc.' } });
    expect(container.querySelector('img')).toBeNull();
    expect(screen.getByText('Auto Rescue Inc.')).toBeTruthy();
    await rerender({ src: '/images/auto.jpg', alt: 'Auto Rescue' });
    expect(container.querySelector('img')).not.toBeNull();
  });

  it('a decorative placeholder is hidden from assistive technology', () => {
    const { container } = render(PlaceholderImage, { props: { decorative: true, label: 'x' } });
    expect(container.firstElementChild?.getAttribute('aria-hidden')).toBe('true');
    expect(screen.queryByRole('img')).toBeNull();
  });

  it('has no detectable accessibility violations in the fallback state', async () => {
    const { container } = render(SafeImage, { props: { src: null, alt: 'BMW of Midlothian' } });
    expect(await axe(container)).toHaveNoViolations();
  });
});
