import { createApp } from 'vue';
import 'survey-core/survey-core.css';
import JudgingApp from './JudgingApp.vue';
import { createJudgingApi } from './api';

interface JudgingConfig {
  dataUrl: string;
  submitUrl: string;
}

const config = JSON.parse(document.getElementById('judging-config')?.textContent ?? '{}') as JudgingConfig;

createApp(JudgingApp, { api: createJudgingApi(config.dataUrl, config.submitUrl) }).mount('#judging-app');
