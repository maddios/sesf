import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/vue';
import { axe } from 'vitest-axe';
import PrivacyPolicyPage from '../PrivacyPolicyPage.vue';

describe('PrivacyPolicyPage', () => {
  it('has a page title and the legal sections as headings', () => {
    render(PrivacyPolicyPage);
    expect(screen.getByRole('heading', { level: 1, name: 'Privacy Policy' })).toBeTruthy();
    const headings = screen.getAllByRole('heading', { level: 2 }).map((h) => h.textContent?.trim());
    expect(headings).toEqual([
      'What information do we collect?',
      'What do we use your information for?',
      'Do we use cookies?',
      'Do we disclose any information to outside parties?',
      'Third party links',
      'California Online Privacy Protection Act Compliance',
      'Childrens Online Privacy Protection Act Compliance',
      'Online Privacy Policy Only',
      'Your Consent',
      'Release of Rights For Video and Publications',
      'Changes to our Privacy Policy',
      'Contacting Us',
    ]);
  });

  it('keeps the legacy wording, including its original spelling', () => {
    render(PrivacyPolicyPage);
    const text = document.body.textContent ?? '';
    const distinctive = [
      'We collect information from you when you register on our site, place an order, subscribe to our newsletter, respond to a survey or fill out a form.',
      'Your information, whether public or private, will not be sold, exchanged, transferred, or given to any other company for any reason whatsoever, without your consent',
      'unclick "Recieve e-mails from Southeast Sharkfest."',
      'we do not collect any information from anyone under 13 years of age.',
      'and am providing permissions as outlined in this secion.',
      'By using our site, you consent to our privacy policy.',
      'kai@bmwsharkfest.org',
    ];
    for (const sentence of distinctive) {
      expect(text).toContain(sentence);
    }
  });

  it('renders the cookie, release and contact details as their own paragraphs and lists', () => {
    render(PrivacyPolicyPage);
    expect(screen.getAllByRole('list')).toHaveLength(1);
    expect(screen.getAllByRole('listitem')).toHaveLength(5);
    expect(document.querySelectorAll('blockquote')).toHaveLength(2);
    expect(screen.getByText('www.bmwsharkfest.org', { exact: false })).toBeTruthy();
  });

  it('has no detectable accessibility violations', async () => {
    const { container } = render(PrivacyPolicyPage);
    expect(await axe(container)).toHaveNoViolations();
  });
});
