import { describe, expect, it, vi } from 'vitest';
import type { QuestionFileModel } from 'survey-core';
import { UploadAborted, type SaveResult, type ServerFile } from '../api';
import { createJudgingSurvey, firstIncompletePage, SAVE_SLOW_MS } from '../judgingSurvey';
import { UploadTracker } from '../uploads';
import { fakeApi, sheet } from './fixtures';

const tick = () => new Promise((resolve) => setTimeout(resolve, 0));

describe('createJudgingSurvey', () => {
  it('opens on the server resume page', () => {
    const survey = createJudgingSurvey(sheet(2), fakeApi(), vi.fn());
    expect(survey.currentPage.name).toBe('page2');
  });

  it('autosaves a score with its quarter and comment', async () => {
    const api = fakeApi();
    const status = vi.fn();
    const survey = createJudgingSurvey(sheet(), api, status);

    survey.setValue('q_2', 7);
    await tick();

    expect(api.save).toHaveBeenCalledWith({ q_2: 7, qtr_2: null, comment_2: null });
    expect(status).toHaveBeenLastCalledWith('saved', 'All changes saved.');
  });

  it('blocks moving on while the page has an unscored question', () => {
    const status = vi.fn();
    const survey = createJudgingSurvey(sheet(), fakeApi(), status);

    survey.setValue('q_1', 5);
    survey.nextPage();
    expect(survey.currentPage.name).toBe('page1');
    expect(status).toHaveBeenLastCalledWith('info', expect.stringContaining('Exterior'));

    survey.setValue('q_2', 6);
    survey.nextPage();
    expect(survey.currentPage.name).toBe('page2');
    expect(firstIncompletePage(survey, sheet())?.name).toBe('page2');
  });

  it('blocks skipping past the first incomplete page', () => {
    const status = vi.fn();
    const survey = createJudgingSurvey(sheet(2), fakeApi(), status);

    survey.currentPage = survey.pages[0];
    expect(survey.currentPage.name).toBe('page1');
    survey.setValue('q_1', 5);
    survey.setValue('q_2', 5);
    survey.setValue('q_1', null);
    survey.currentPage = survey.pages[1];
    expect(survey.currentPage.name).toBe('page1');
  });

  it('uploads photos under their server names and deletes removed ones', async () => {
    const api = fakeApi();
    const survey = createJudgingSurvey(sheet(), api, vi.fn());
    const question = survey.getQuestionByName('file_1') as QuestionFileModel;
    const callback = vi.fn();

    survey.onUploadFiles.fire(survey, { question, name: 'file_1', files: [new File(['x'], 'p.png', { type: 'text/plain' })], callback });
    await tick();
    await tick();

    expect(api.upload).toHaveBeenCalledWith('file_1', expect.any(File), expect.objectContaining({ signal: expect.any(AbortSignal) }));
    expect(callback).toHaveBeenCalledWith([{ file: { name: 'stored_p.png', type: 'image/jpeg' }, content: '/images/judging/1/file_1/s.jpg' }], undefined);

    const cleared = vi.fn();
    survey.onClearFiles.fire(survey, { question, name: 'file_1', fileName: 'stored_p.png', value: [], callback: cleared });
    await tick();
    expect(api.save).toHaveBeenCalledWith({ delete_file_1: ['stored_p.png'] });
    expect(cleared).toHaveBeenCalledWith('success');
  });

  it('reports a failed upload to SurveyJS and the status line', async () => {
    const status = vi.fn();
    const api = fakeApi({ upload: vi.fn().mockRejectedValue(new Error('offline')) });
    const survey = createJudgingSurvey(sheet(), api, status);
    const callback = vi.fn();

    survey.onUploadFiles.fire(survey, { question: survey.getQuestionByName('file_1') as QuestionFileModel, name: 'file_1', files: [new File(['x'], 'p.txt', { type: 'text/plain' })], callback });
    await tick();
    await tick();

    expect(callback).toHaveBeenCalledWith([], [expect.stringMatching(/check your connection/)]);
    expect(status).toHaveBeenLastCalledWith('error', expect.stringMatching(/check your connection/));
  });

  it('adds the photo buttons to file questions and appends picked photos', async () => {
    const api = fakeApi();
    const survey = createJudgingSurvey(sheet(), api, vi.fn());
    const question = survey.getQuestionByName('file_1');
    const htmlElement = document.createElement('div');

    survey.onAfterRenderQuestion.fire(survey, { question, htmlElement });
    const gallery = htmlElement.querySelectorAll('input')[1];
    Object.defineProperty(gallery, 'files', { value: [new File(['x'], 'door.png', { type: 'text/plain' })] });
    gallery.dispatchEvent(new Event('change'));
    await tick();
    await tick();

    expect([...htmlElement.querySelectorAll('button')].map((b) => b.textContent)).toEqual(['Take photo', 'Choose from gallery']);
    expect(survey.getValue('file_1')).toEqual([{ name: 'stored_door.png', type: 'image/jpeg', content: '/images/judging/1/file_1/s.jpg' }]);
  });

  it('reports upload progress to the tracker and blocks moving on until uploads finish', async () => {
    let finish: () => void = () => undefined;
    const tracker = new UploadTracker();
    const status = vi.fn();
    const api = fakeApi({
      upload: vi.fn((_field: string, _file: File, options?: { onProgress?: (loaded: number, total: number) => void }) => {
        options?.onProgress?.(3, 4);
        return new Promise<ServerFile>((resolve) => (finish = () => resolve({ name: 's.jpg', serverFile: 's.jpg', content: '/s.jpg', type: 'image/jpeg' })));
      }),
    });
    const survey = createJudgingSurvey(sheet(), api, status, tracker);
    survey.setValue('q_1', 5);
    survey.setValue('q_2', 5);

    survey.onUploadFiles.fire(survey, { question: survey.getQuestionByName('file_1') as QuestionFileModel, name: 'file_1', files: [new File(['abcd'], 'a.txt', { type: 'text/plain' })], callback: vi.fn() });
    await tick();
    expect(tracker.state()).toMatchObject({ count: 1, percent: 75 });

    survey.nextPage();
    expect(survey.currentPage.name).toBe('page1');
    expect(status).toHaveBeenLastCalledWith('info', expect.stringContaining('stop photo uploads'));

    finish();
    await tick();
    expect(tracker.active).toBe(0);
    survey.nextPage();
    expect(survey.currentPage.name).toBe('page2');
  });

  it('treats stopped uploads as neither stored nor failed', async () => {
    const status = vi.fn();
    const survey = createJudgingSurvey(sheet(), fakeApi({ upload: vi.fn().mockRejectedValue(new UploadAborted()) }), status);
    const callback = vi.fn();

    survey.onUploadFiles.fire(survey, { question: survey.getQuestionByName('file_1') as QuestionFileModel, name: 'file_1', files: [new File(['x'], 'a.txt', { type: 'text/plain' })], callback });
    await tick();
    await tick();

    expect(callback).toHaveBeenCalledWith([], undefined);
    expect(status).toHaveBeenLastCalledWith('info', 'Photo uploads stopped.');
  });

  it('warns when a save is slow', async () => {
    vi.useFakeTimers();
    const status = vi.fn();
    const survey = createJudgingSurvey(sheet(), fakeApi({ save: vi.fn(() => new Promise<SaveResult>(() => undefined)) }), status);

    survey.setValue('q_1', 5);
    await vi.advanceTimersByTimeAsync(SAVE_SLOW_MS);

    expect(status).toHaveBeenLastCalledWith('info', 'Realtime save delayed. Weak connection.');
    vi.useRealTimers();
  });
});
