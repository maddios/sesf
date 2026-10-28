import type { EventSponsor } from '../../api/events';

export interface SponsorTier {
  key: string;
  heading: string;
  sponsors: EventSponsor[];
}

/** Tier headings in legacy order and wording (BMW paint names Silber, Bronzit), keyed by App\Enums SponsorType ids. */
const TIERS: Array<{ typeId: number; heading: string }> = [
  { typeId: 1, heading: '///M Class' },
  { typeId: 2, heading: 'Gold Class' },
  { typeId: 3, heading: 'Silber Class' },
  { typeId: 4, heading: 'Bronzit Class' },
];

/**
 * Groups sponsors into tiers (///M, Gold, Silber, Bronzit) in that order. Empty tiers are dropped. Sponsors with an
 * unknown or missing type go into a last "Other sponsors" group so none disappear.
 */
export function groupSponsorsByTier(sponsors: EventSponsor[]): SponsorTier[] {
  const groups: SponsorTier[] = [];
  for (const tier of TIERS) {
    const members = sponsors.filter((sponsor) => sponsor.typeId === tier.typeId);
    if (members.length > 0) {
      groups.push({ key: `tier-${tier.typeId}`, heading: tier.heading, sponsors: members });
    }
  }
  const known = new Set(TIERS.map((tier) => tier.typeId));
  const other = sponsors.filter((sponsor) => sponsor.typeId === null || !known.has(sponsor.typeId));
  if (other.length > 0) {
    groups.push({ key: 'tier-other', heading: 'Other sponsors', sponsors: other });
  }
  return groups;
}
