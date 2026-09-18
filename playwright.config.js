import { defineConfig } from '@playwright/test';
export default defineConfig({
    testDir: './tests/browser',
    workers: 1,
    use: {
        baseURL: process.env.CRM_TEST_URL || 'http://127.0.0.1:8000',
        channel: 'chrome',
        viewport: { width: 1440, height: 1080 },
        screenshot: 'only-on-failure',
    },
});
