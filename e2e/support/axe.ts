import { expect, type Page } from '@playwright/test';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
/** axe-core from the repo's node_modules (mounted read-only into the runner). */
const AXE_PATH = require.resolve('axe-core/axe.min.js');

interface AxeViolation {
  id: string;
  impact: 'minor' | 'moderate' | 'serious' | 'critical' | null;
  help: string;
  nodes: { target: string[]; html: string }[];
}

/** Runs axe (WCAG 2.0/2.1 A and AA) on the current page and fails on serious or critical violations. */
export async function expectNoSeriousA11yViolations(page: Page, label: string): Promise<void> {
  await page.addScriptTag({ path: AXE_PATH });
  const violations = await page.evaluate(async () => {
    const axe = (window as unknown as { axe: { run: (ctx: unknown, opts: unknown) => Promise<{ violations: unknown[] }> } }).axe;
    const result = await axe.run(document, { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'] } });
    return result.violations;
  }) as AxeViolation[];
  const serious = violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
  const report = serious.map((v) => `${v.impact} ${v.id}: ${v.help}\n  ${v.nodes.slice(0, 3).map((n) => `${n.target.join(' ')}  ${n.html.slice(0, 120)}`).join('\n  ')}`);
  expect(report, `axe serious/critical violations on ${label}`).toEqual([]);
}
