import { test, expect } from '@playwright/test';
// Run only against a local demo database; these tests create demo lead/call records.
async function login(page, email, password) {
    await page.goto('/login');
    await page.getByLabel('Email address').fill(email);
    await page.getByLabel('Password', { exact: true }).fill(password);
    await page.getByRole('button', { name: 'Sign in' }).click();
    await expect(page).toHaveURL(/dashboard/);
    await expect(page.locator('.demo-banner')).toBeVisible();
}
test('owner creates a lead, imports CSV, checks all pages and mobile navigation', async ({ page }) => {
    const errors = [];
    page.on('pageerror', e => errors.push(e.message));
    await login(page, 'owner@telecrm.test', 'DemoOwner!2026');
    await expect(page.getByRole('heading', { name: 'A good day starts with a clear view.' })).toBeVisible();
    await page.screenshot({ path: 'test-results/dashboard-desktop.png', fullPage: true });
    await page.getByRole('link', { name: 'Add lead', exact: true }).click();
    const phone = '98' + String(Date.now()).slice(-8);
    await page.getByLabel('Full name').fill('Browser Test Lead');
    await page.getByLabel('Indian phone number').fill(phone);
    await page.getByLabel('Assigned employee').selectOption({ label: 'Anjali Menon' });
    await page.getByRole('button', { name: 'Create lead' }).click();
    await expect(page.getByRole('heading', { name: 'Browser Test Lead', exact: true })).toBeVisible();
    await page.getByLabel('Discussion notes').fill('Browser workflow verified.');
    await page.getByRole('button', { name: 'Save note' }).click();
    await expect(page.getByText('Browser workflow verified.', { exact: true })).toBeVisible();
    for (const path of ['/leads', '/calls', '/follow-ups', '/employees', '/settings', '/account', '/leads/import']) {
        const response = await page.goto(path);
        expect(response.status()).toBe(200);
        await expect(page.locator('main h1')).toBeVisible();
    }
    await page.locator('input[type=file]').setInputFiles({ name: 'test.csv', mimeType: 'text/csv', buffer: Buffer.from(`name,phone,source\nImported Browser Lead,97${String(Date.now()).slice(-8)},Browser\nInvalid,+14155552671,Test\n`) });
    await page.getByRole('button', { name: 'Import leads', exact: true }).click();
    await expect(page.getByText('1 imported · 1 skipped')).toBeVisible();
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/dashboard');
    await page.getByRole('button', { name: 'Toggle navigation' }).click();
    await page.getByRole('navigation').getByRole('link', { name: 'Leads', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'All leads' })).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBeTruthy();
    await page.screenshot({ path: 'test-results/leads-mobile.png', fullPage: true });
    expect(errors).toEqual([]);
});
test('employee starts and completes a simulated call, then schedules a follow-up', async ({ page }) => {
    await login(page, 'anjali@telecrm.test', 'DemoEmployee!2026');
    await page.goto('/leads');
    await page.locator('tbody a.person').first().click();
    await page.getByRole('button', { name: 'Start demo call' }).click();
    await expect(page.getByRole('heading', { name: 'Demo call in progress' })).toBeVisible();
    await page.getByLabel('Simulated talk time (seconds)').fill('143');
    await page.getByRole('button', { name: 'Finish demo call' }).click();
    await expect(page.getByText('2m 23s').first()).toBeVisible();
    await page.getByLabel('Date and time', { exact: true }).fill('2030-10-20T15:30');
    await page.getByLabel('What needs to happen?').fill('Browser test callback');
    await page.getByRole('button', { name: 'Schedule follow-up' }).click();
    await expect(page.getByText('Follow-up scheduled.', { exact: true })).toBeVisible();
    await page.getByRole('link', { name: 'Update stage' }).click();
    await page.getByLabel('Lead stage').selectOption('Qualified');
    await page.getByRole('button', { name: 'Save changes' }).click();
    await expect(page.locator('.lead-summary')).toContainText('Qualified');
    await page.screenshot({ path: 'test-results/lead-detail.png', fullPage: true });
});
