const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { test, expect } = require('@playwright/test');

const projectRoot = path.resolve(__dirname, '../..');
const screenshotRoot = path.join(projectRoot, 'release-screenshots');
const saveIds = new Map();
const pageErrors = new WeakMap();

test.describe.configure({ mode: 'serial' });

test.beforeEach(async ({ page }) => {
  const errors = { http: [], page: [] };
  pageErrors.set(page, errors);
  page.on('response', (response) => {
    if (response.status() >= 500) {
      errors.http.push(`${response.status()} ${response.url()}`);
    }
  });
  page.on('pageerror', (error) => errors.page.push(error.message));
});

test.afterEach(async ({ page }) => {
  const errors = pageErrors.get(page);
  expect(errors?.http ?? []).toEqual([]);
  expect(errors?.page ?? []).toEqual([]);
});

test.beforeAll(async ({ browser }, testInfo) => {
  const saveId = saveIdFor(testInfo.project.name);
  saveIds.set(testInfo.project.name, saveId);
  removeOwnedSave(saveId);

  execFileSync('php', [path.join(projectRoot, 'scripts/ci/create-browser-career.php'), saveId], {
    cwd: projectRoot,
    env: process.env,
    stdio: 'inherit',
  });
});

test.afterAll(async ({}, testInfo) => {
  const saveId = saveIds.get(testInfo.project.name);
  if (saveId !== undefined) {
    removeOwnedSave(saveId);
  }
});

test('Career entry and creation form produce a usable Career Home', async ({ page }, testInfo) => {
  const saveId = saveIds.get(testInfo.project.name);

  await page.goto('/?page=new&step=identity');
  await expect(page.getByRole('heading', { name: 'Identity' })).toBeVisible();
  await expect(page.getByLabel('Save name')).toBeVisible();
  await expect(page.getByLabel('Player name')).toBeVisible();
  await expect(page.getByLabel('Nationality')).toBeVisible();

  await page.goto('/?page=menu');
  await expect(page.getByRole('heading', { name: 'Load Career' })).toBeVisible();
  const saveCard = page.locator('article.save-card').filter({ hasText: saveId });
  await expect(saveCard).toHaveCount(1);
  await expect(saveCard).toBeVisible();
  await saveCard.getByRole('link', { name: 'Load Career' }).click();

  await expect(page.getByRole('main')).toBeVisible();
  await expect(page.getByRole('heading', { level: 1 })).toContainText('P3-019 Browser Player');
  await expect(page.getByRole('navigation', { name: 'Career navigation' })).toBeVisible();
  await expect(page.getByRole('navigation', { name: 'Career navigation' }).getByRole('link', { name: 'Training', exact: true })).toBeVisible();
  await expect(page.getByRole('navigation', { name: 'Career navigation' }).getByRole('link', { name: 'Profile', exact: true })).toBeVisible();
  await expect(page.getByRole('navigation', { name: 'Career navigation' }).getByRole('link', { name: 'Career History', exact: true })).toBeVisible();
  await expect(page.getByText('NEXT UP', { exact: true })).toBeVisible();
  await expect(page.locator('[data-career-identity]')).toBeVisible();
  await expect(page.locator('a[aria-current="page"]')).toHaveText('Career Home');
  await expectNoPageOverflow(page);
  await assertResponsiveWidths(page, `/?page=home&save=${encodeURIComponent(saveId)}`);
  await saveReleaseScreenshot(page, testInfo, 'career-home');
});

test('Training remains labelled, interactive, and returns to Home', async ({ page }, testInfo) => {
  const saveId = saveIds.get(testInfo.project.name);

  await page.goto(`/?page=home&save=${encodeURIComponent(saveId)}`);
  await page.getByRole('navigation', { name: 'Career navigation' }).getByRole('link', { name: 'Training', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'Shape the next block' })).toBeVisible();
  await page.getByLabel('Training focus').selectOption('passing');
  await page.getByRole('button', { name: 'Save training focus' }).click();
  await expect(page.getByRole('status')).toContainText('Training focus updated.');
  await expect(page.getByLabel('Training focus')).toHaveValue('passing');
  await expectNoPageOverflow(page);
  await saveReleaseScreenshot(page, testInfo, 'training');

  await page.getByRole('link', { name: 'Career Home', exact: true }).click();
  await expect(page.getByRole('heading', { level: 1 })).toContainText('P3-019 Browser Player');
});

test('Pre-Match and post-Match keep the player loop reachable', async ({ page }, testInfo) => {
  const saveId = saveIds.get(testInfo.project.name);

  await page.goto(`/?page=home&save=${encodeURIComponent(saveId)}`);
  await page.getByRole('button', { name: /Continue/ }).click();
  await expect(page).toHaveURL(/page=matchday/);
  await expect(page.getByText('PRE-MATCH', { exact: false }).first()).toBeVisible();
  await expect(page.getByText('PLAYER STATUS', { exact: true })).toBeVisible();
  await expect(page.getByText('FIXTURE CONTEXT', { exact: true })).toBeVisible();
  await expect(page.getByText('NEXT STEP', { exact: true })).toBeVisible();
  await expect(page.locator('.scoreline')).toContainText('vs');
  await expect(page.getByRole('button', { name: 'Advance to Match' })).toBeVisible();
  await expectNoPageOverflow(page);
  await saveReleaseScreenshot(page, testInfo, 'pre-match');

  await page.getByRole('button', { name: 'Advance to Match' }).click();
  await expect(page).toHaveURL(/page=matchday/);
  await expect(page.getByText('FINAL RESULT', { exact: false }).first()).toBeVisible();
  await expect(page.locator('[data-match-result]')).toBeVisible();
  await expect(page.getByText('PLAYER STATUS', { exact: true })).toBeVisible();
  await expect(page.getByText('YOUR MATCH', { exact: true })).toBeVisible();
  await expect(page.getByText('CAREER IMPACT', { exact: true })).toBeVisible();
  await expect(page.locator('.post-match-next')).toContainText('Next step');
  await expect(page.getByRole('link', { name: 'Career Home', exact: true }).first()).toBeVisible();
  await expectNoPageOverflow(page);
  await saveReleaseScreenshot(page, testInfo, 'post-match');

  await page.getByRole('link', { name: 'Career Home', exact: true }).first().click();
  await expect(page).toHaveURL(/page=home/);
  await expect(page.getByText('RECENT RESULT', { exact: true })).toBeVisible();
  await expect(page.getByRole('link', { name: 'Review Match', exact: true })).toBeVisible();
  await expectNoPageOverflow(page);
  await saveReleaseScreenshot(page, testInfo, 'career-home-after-match');

  await page.getByRole('navigation', { name: 'Career navigation' }).getByRole('link', { name: 'Profile', exact: true }).click();
  await expect(page.getByRole('heading', { level: 1 })).toContainText('P3-019 Browser Player');
  await expect(page.locator('[data-career-identity]')).toBeVisible();
  await expect(page.getByText('CURRENT PLAYER', { exact: true })).toBeVisible();
  await expect(page.getByText('DEVELOPMENT SIGNAL', { exact: true })).toBeVisible();
  await expectNoPageOverflow(page);
  await saveReleaseScreenshot(page, testInfo, 'profile');

  await page.getByRole('navigation', { name: 'Career navigation' }).getByRole('link', { name: 'Career History', exact: true }).click();
  await expect(page.getByRole('heading', { name: 'P3-019 Browser Player' })).toBeVisible();
  await expect(page.locator('[data-career-identity]')).toBeVisible();
  await expect(page.getByText('CAREER STORY', { exact: true })).toBeVisible();
  await expect(page.getByText('CAREER ARC', { exact: true })).toBeVisible();
  await expect(page.getByText('SEASON HISTORY', { exact: true })).toBeVisible();
  await expect(page.getByText('DEVELOPMENT HISTORY', { exact: true })).toBeVisible();
  await expect(page.getByText('CAREER HIGHLIGHTS', { exact: true })).toBeVisible();
  await expect(page.locator('[data-season-status="current"]')).toBeVisible();
  await expectNoPageOverflow(page);
  await saveReleaseScreenshot(page, testInfo, 'career-history');
});

test('Missing Career recovery is bounded and actionable', async ({ page }) => {
  await page.goto('/?page=home&save=p3019-browser-missing');
  await expect(page).toHaveURL(/page=menu/);
  await expect(page.getByRole('status')).toContainText('That saved career could not be found.');
  await expect(page.getByRole('link', { name: 'New Career' })).toBeVisible();
  await expect(page.locator('body')).not.toContainText(/SQL|SQLite|PDOException|\/data\/|stack trace/i);
});

async function expectNoPageOverflow(page) {
  const dimensions = await page.evaluate(() => ({
    clientWidth: document.documentElement.clientWidth,
    scrollWidth: document.documentElement.scrollWidth,
  }));
  expect(dimensions.scrollWidth, 'the page itself must not overflow horizontally').toBeLessThanOrEqual(dimensions.clientWidth + 1);
}

async function assertResponsiveWidths(page, url) {
  const originalViewport = page.viewportSize();
  for (const viewport of [
    { width: 360, height: 800 },
    { width: 390, height: 844 },
    { width: 412, height: 915 },
  ]) {
    await page.setViewportSize(viewport);
    await page.goto(url);
    await expectNoPageOverflow(page);
    await expect(page.getByRole('main')).toBeVisible();
  }
  await page.setViewportSize(originalViewport);
  await page.goto(url);
}

async function saveReleaseScreenshot(page, testInfo, surface) {
  fs.mkdirSync(screenshotRoot, { recursive: true });
  await page.screenshot({
    path: path.join(screenshotRoot, `${surface}-${testInfo.project.name}.png`),
    fullPage: true,
  });
}

function saveIdFor(projectName) {
  const runId = sanitize(process.env.GOAL_BROWSER_RUN_ID || process.env.GITHUB_RUN_ID || 'local');
  return `p3019-browser-${runId}-${sanitize(projectName)}`.slice(0, 63);
}

function sanitize(value) {
  return String(value).replace(/[^a-zA-Z0-9_-]/g, '-');
}

function removeOwnedSave(saveId) {
  if (!saveId.startsWith('p3019-browser-')) {
    throw new Error(`Refusing to clean a non-browser save: ${saveId}`);
  }
  for (const suffix of ['', '-wal', '-shm']) {
    const file = path.join(projectRoot, 'game', 'saves', `${saveId}.sqlite${suffix}`);
    if (fs.existsSync(file)) {
      fs.unlinkSync(file);
    }
  }
}
