import { vi } from 'vitest';
import type { JudgingApi, SaveResult, SheetData } from '../api';

const rating = (id: number, quarter = false) => ({
  type: 'panel',
  title: '',
  elements: [
    { type: 'rating', name: `q_${id}`, title: `Question ${id}`, isRequired: true, rateMin: 1, rateMax: 10, rateStep: 1 },
    ...(quarter ? [{ type: 'rating', name: `qtr_${id}`, title: 'Quarter points', rateMin: 0, rateMax: 0.75, rateStep: 0.25 }] : []),
    { type: 'comment', name: `comment_${id}`, title: 'Comments' },
    { type: 'file', name: `file_${id}`, title: 'Images', allowMultiple: true, storeDataAsText: false, waitForUpload: false },
  ],
});

export function sheet(showPage = 1): SheetData {
  return {
    showPage,
    form: [
      { name: 'page1', title: 'Exterior', elements: [rating(1), rating(2, true)] },
      { name: 'page2', title: 'Interior', elements: [rating(3)] },
    ],
  };
}

export const ok: SaveResult = { success: true, savedQuestionIds: [], firstIncompletePage: null, uploadedFiles: {} };

export function fakeApi(overrides: Partial<JudgingApi> = {}): JudgingApi & { save: ReturnType<typeof vi.fn>; upload: ReturnType<typeof vi.fn> } {
  return {
    load: vi.fn(async () => sheet()),
    save: vi.fn(async () => ok),
    upload: vi.fn(async (field: string, file: File) => ({ name: 's.jpg', serverFile: `stored_${file.name}`, content: `/images/judging/1/${field}/s.jpg`, type: 'image/jpeg' })),
    ...overrides,
  } as JudgingApi & { save: ReturnType<typeof vi.fn>; upload: ReturnType<typeof vi.fn> };
}
