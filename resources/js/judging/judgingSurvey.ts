import { Model, type PageModel, type Question, type SurveyModel } from 'survey-core';
import { errorMessages, UploadAborted, type FieldValues, type JudgingApi, type SheetData } from './api';
import { AutosaveQueue } from './autosave';
import { resizeForUpload } from './images';
import { addPhotoButtons } from './photoButtons';
import { UploadTracker } from './uploads';

export type StatusKind = 'saving' | 'saved' | 'error' | 'info';
export type StatusSink = (kind: StatusKind, message: string) => void;

const SCORE_FIELD = /^(q|qtr|comment)_(\d+)$/;
export const SAVE_SLOW_MS = 5000;
const UPLOADING = 'Photos are still uploading. Wait until they finish, or stop photo uploads if the connection is poor. Your scores are already saving separately.';

interface StoredPhoto {
  file: { name: string; type: string };
  content: string;
}
const FILE_FIELD = /^file_(\d+)$/;

const SURVEY_SETTINGS = {
  autoFocusFirstQuestion: false,
  completedHtml: '<h3>Saved</h3><p>Your judging is stored. You can close this tab or leave it open.</p>',
  showPreviewBeforeComplete: true,
  showQuestionNumbers: 'off',
  widthMode: 'responsive',
  showProgressBar: true,
  progressBarType: 'pages',
  progressBarShowPageNumbers: true,
  checkErrorsMode: 'onComplete',
};

interface PageElement {
  name?: string;
  type?: string;
  isRequired?: boolean;
  elements?: PageElement[];
}

function fieldsOf(sheet: SheetData, pageName: string): PageElement[] {
  const page = sheet.form.find((p) => p.name === pageName);
  return (page?.elements ?? []).flatMap((element: PageElement) => element.elements ?? [element]);
}

function isPageComplete(survey: SurveyModel, sheet: SheetData, page: PageModel): boolean {
  return fieldsOf(sheet, page.name).every((field) => {
    if (field.isRequired !== true || !field.name?.startsWith('q_')) {
      return true;
    }
    const value: unknown = survey.getValue(field.name);
    return value !== null && value !== undefined && value !== '';
  });
}

export function firstIncompletePage(survey: SurveyModel, sheet: SheetData): PageModel | undefined {
  return survey.pages.find((page) => !isPageComplete(survey, sheet, page));
}

/** q_, qtr_ and comment_ values of one question, as the server expects them. */
function questionValues(survey: SurveyModel, questionId: string): FieldValues {
  const values: FieldValues = {};
  for (const prefix of ['q_', 'qtr_', 'comment_']) {
    const name = prefix + questionId;
    if (survey.getQuestionByName(name) !== null) {
      values[name] = survey.getValue(name) ?? null;
    }
  }
  return values;
}

function pageValues(survey: SurveyModel, sheet: SheetData, page: PageModel): FieldValues {
  const values: FieldValues = {};
  for (const field of fieldsOf(sheet, page.name)) {
    if (field.name !== undefined && field.type !== 'file') {
      values[field.name] = survey.getValue(field.name) ?? null;
    }
  }
  return values;
}

/**
 * The judging scoresheet model (legacy public/js/judging/index.js): every score or comment change
 * is autosaved, the page's values are saved again when moving on, photos upload as they are picked
 * and removed photos are deleted on the server. Forward navigation is blocked while the current
 * page has an unscored question, past the first incomplete page, or while photos are uploading.
 */
export function createJudgingSurvey(sheet: SheetData, api: JudgingApi, status: StatusSink, tracker = new UploadTracker()): SurveyModel {
  const survey = new Model({ ...SURVEY_SETTINGS, pages: sheet.form });

  const report = (error: unknown): void => status('error', errorMessages(error).join(' '));
  const save = async (data: FieldValues) => {
    status('saving', 'Saving…');
    const slow = setTimeout(() => status('info', 'Realtime save delayed. Weak connection.'), SAVE_SLOW_MS);
    try {
      const result = await api.save(data);
      status('saved', 'All changes saved.');
      return result;
    } finally {
      clearTimeout(slow);
    }
  };
  const autosave = new AutosaveQueue(save, report);

  /** Uploads each file with progress; resolves with the stored ones (failed and stopped files are left out). */
  const uploadPhotos = async (fieldName: string, files: File[]): Promise<{ stored: StoredPhoto[]; errors: string[] }> => {
    const results = await Promise.all(
      files.map(async (file): Promise<StoredPhoto | string | null> => {
        const { id, signal } = tracker.start(file);
        try {
          const stored = await api.upload(fieldName, await resizeForUpload(file), {
            signal,
            onProgress: (loaded, total) => tracker.progress(id, loaded, total),
          });
          tracker.finish(id);
          return { file: { name: stored.serverFile, type: stored.type }, content: stored.content };
        } catch (error) {
          if (error instanceof UploadAborted) {
            tracker.finish(id);
            return null;
          }
          tracker.fail(id);
          return errorMessages(error).join(' ');
        }
      }),
    );
    const stored = results.filter((result): result is StoredPhoto => typeof result === 'object' && result !== null);
    const errors = results.filter((result): result is string => typeof result === 'string');
    if (errors.length > 0) {
      status('error', errors.join(' '));
    } else if (stored.length > 0) {
      status('saved', 'Photos saved.');
    } else {
      status('info', 'Photo uploads stopped.');
    }
    return { stored, errors };
  };

  const addPickedPhotos = async (question: Question, files: File[]) => {
    const { stored } = await uploadPhotos(question.name, files);
    if (stored.length > 0) {
      const current = Array.isArray(question.value) ? (question.value as unknown[]) : [];
      survey.setValue(question.name, [...current, ...stored.map((photo) => ({ name: photo.file.name, type: photo.file.type, content: photo.content }))]);
    }
  };

  const start = survey.getPageByName(`page${sheet.showPage}`);
  if (start !== null) {
    survey.currentPage = start;
  }

  survey.onValueChanged.add((sender, options) => {
    const match = SCORE_FIELD.exec(options.name);
    if (match !== null) {
      autosave.queue(questionValues(sender, match[2]), match[1] === 'comment');
    }
  });

  survey.onCurrentPageChanging.add((sender, options) => {
    const { oldCurrentPage: from, newCurrentPage: to } = options;
    if (from === null || to === null || to.num <= from.num) {
      return;
    }
    const blocked = (message: string) => {
      options.allow = false;
      status('info', message);
    };
    if (tracker.active > 0) {
      blocked(UPLOADING);
      return;
    }
    if (!isPageComplete(sender, sheet, from)) {
      blocked(`Please finish all required questions on ${from.title || 'this page'} before continuing.`);
      return;
    }
    const firstIncomplete = firstIncompletePage(sender, sheet);
    if (firstIncomplete !== undefined && to.num > firstIncomplete.num) {
      blocked(`Complete ${firstIncomplete.title || 'the earlier section'} before skipping to a later section.`);
      return;
    }
    autosave.queue(pageValues(sender, sheet, from), false);
  });

  survey.onCompleting.add((_sender, options) => {
    if (tracker.active > 0) {
      options.allow = false;
      status('info', UPLOADING);
    }
  });

  survey.onComplete.add((sender) => {
    const page = sender.pages[sender.pages.length - 1];
    if (page !== undefined) {
      autosave.queue(pageValues(sender, sheet, page), false);
    }
    void autosave.flush();
  });

  survey.onUploadFiles.add((_sender, options) => {
    void uploadPhotos(options.question.name, options.files).then(({ stored, errors }) => options.callback(stored, errors.length > 0 ? errors : undefined));
  });

  survey.onAfterRenderQuestion.add((_sender, options) => {
    if (options.question.getType() === 'file') {
      addPhotoButtons(options.htmlElement, (options.question as Question & { allowMultiple?: boolean }).allowMultiple === true, (files) => {
        void addPickedPhotos(options.question, files);
      });
    }
  });

  survey.onClearFiles.add((_sender, options) => {
    const match = FILE_FIELD.exec(options.question.name);
    const values = Array.isArray(options.value) ? (options.value as Array<{ name?: unknown }>) : [];
    const names = options.fileName === null ? values.map((v) => String(v.name)) : [options.fileName];
    if (match === null || names.length === 0) {
      options.callback('success');
      return;
    }
    save({ [`delete_file_${match[1]}`]: names }).then(
      () => options.callback('success'),
      (error: unknown) => {
        report(error);
        options.callback('error');
      },
    );
  });

  return survey;
}
