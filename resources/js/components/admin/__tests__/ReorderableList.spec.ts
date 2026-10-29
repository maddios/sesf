import { describe, it, expect } from 'vitest';
import { fireEvent, render, screen, within } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { axe } from 'vitest-axe';
import ReorderableList from '../ReorderableList.vue';

const items = [
  { id: 1, name: 'Gold' },
  { id: 2, name: 'Silver' },
  { id: 3, name: 'Bronze' },
];

function renderList(props: Record<string, unknown> = {}) {
  return render(ReorderableList, { props: { items, itemLabel: (item: { id: number | string }) => (item as unknown as { name: string }).name, label: 'Tiers', ...props } });
}

function names(): string[] {
  return within(screen.getByRole('list', { name: 'Tiers' }))
    .getAllByRole('listitem')
    .map((li) => li.textContent?.replace(/[⋮↑↓]/g, '').trim() ?? '');
}

describe('ReorderableList', () => {
  it('moves rows with the buttons, emits the new id order, keeps focus on the moved row and announces it', async () => {
    const user = userEvent.setup();
    const { emitted, container } = renderList();
    expect(await axe(container)).toHaveNoViolations();
    expect((screen.getByRole('button', { name: 'Move Gold up' }) as HTMLButtonElement).disabled).toBe(true);

    await user.click(screen.getByRole('button', { name: 'Move Gold down' }));
    expect(names()).toEqual(['Silver', 'Gold', 'Bronze']);
    expect(emitted().reorder).toEqual([[[2, 1, 3]]]);
    expect(document.activeElement).toBe(screen.getByRole('button', { name: 'Move Gold down' }));
    expect(screen.getByRole('status').textContent).toContain('Gold moved to position 2 of 3.');

    await user.click(screen.getByRole('button', { name: 'Move Bronze up' }));
    expect(names()).toEqual(['Silver', 'Bronze', 'Gold']);
  });

  it('moves with Alt+ArrowUp / Alt+ArrowDown', async () => {
    const user = userEvent.setup();
    const { emitted } = renderList();
    screen.getByRole('button', { name: 'Move Silver up' }).focus();
    await user.keyboard('{Alt>}{ArrowUp}{/Alt}');
    expect(names()).toEqual(['Silver', 'Gold', 'Bronze']);
    // At the top, "up" is disabled, so focus lands on the row's other button.
    expect(document.activeElement).toBe(screen.getByRole('button', { name: 'Move Silver down' }));
    await user.keyboard('{Alt>}{ArrowDown}{/Alt}');
    expect(names()).toEqual(['Gold', 'Silver', 'Bronze']);
    await user.keyboard('{ArrowDown}');
    expect(emitted().reorder).toHaveLength(2);
  });

  it('reorders by drag and drop', async () => {
    const { emitted } = renderList();
    const rows = within(screen.getByRole('list', { name: 'Tiers' })).getAllByRole('listitem');
    await fireEvent.dragStart(rows[0]!, { dataTransfer: { setData: () => undefined, effectAllowed: '' } });
    await fireEvent.dragOver(rows[2]!);
    await fireEvent.drop(rows[2]!);
    await fireEvent.dragEnd(rows[0]!);
    expect(names()).toEqual(['Silver', 'Bronze', 'Gold']);
    expect(emitted().reorder).toEqual([[[2, 3, 1]]]);
  });

  it('does nothing when disabled and follows new items from the parent', async () => {
    const { emitted, rerender } = renderList({ disabled: true });
    expect((screen.getByRole('button', { name: 'Move Gold down' }) as HTMLButtonElement).disabled).toBe(true);
    const rows = within(screen.getByRole('list', { name: 'Tiers' })).getAllByRole('listitem');
    await fireEvent.dragOver(rows[1]!);
    await fireEvent.drop(rows[1]!);
    expect(emitted().reorder).toBeUndefined();
    await rerender({ items: [items[2]!, items[0]!], disabled: false });
    expect(names()).toEqual(['Bronze', 'Gold']);
  });
});
