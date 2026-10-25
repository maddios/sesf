import { describe, it, expect } from 'vitest';
import { groupSponsorsByTier } from '../sponsorTiers';
import type { EventSponsor } from '../../../api/events';

function sponsor(id: number, typeId: number | null): EventSponsor {
  return { id, name: `S${id}`, url: '', logoUrl: '', description: '', typeId };
}

describe('groupSponsorsByTier', () => {
  it('groups by tier in M, Gold, Silver, Bronze order and drops empty tiers', () => {
    const groups = groupSponsorsByTier([sponsor(1, 4), sponsor(2, 1), sponsor(3, 4), sponsor(4, 3)]);
    expect(groups.map((g) => [g.heading, g.sponsors.map((s) => s.id)])).toEqual([
      ['M Class', [2]],
      ['Silver Class', [4]],
      ['Bronze Class', [1, 3]],
    ]);
  });

  it('keeps sponsors of unknown or missing tiers in a final group', () => {
    const groups = groupSponsorsByTier([sponsor(1, 2), sponsor(2, 9), sponsor(3, null)]);
    expect(groups.map((g) => g.heading)).toEqual(['Gold Class', 'Other sponsors']);
    expect(groups[1]?.sponsors.map((s) => s.id)).toEqual([2, 3]);
  });

  it('returns no groups for no sponsors', () => {
    expect(groupSponsorsByTier([])).toEqual([]);
  });
});
