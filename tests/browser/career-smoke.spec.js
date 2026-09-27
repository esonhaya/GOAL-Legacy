const fs = require('node:fs');
const path = require('node:path');
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

  const context = await browser.newContext({
    baseURL: testInfo.project.use.baseURL,
    viewport: testInfo.project.use.viewport,
  });
  try {
    await createCareer(context, saveId);
  } finally {
    await context.close();
  }
});

test.afterAll(async ({}, testInfo) => {
  const saveId = saveIds.get(testInfo.project.name);
  if (saveId !== undefined) {
    removeOwnedSave(saveId);
  }
});

test('Career entry and creation produce a usable Career Home', async ({ page }, testInfo) => {
  const saveId = saveIds.get(testInfo.project.name);

  await page.goto('/?page=menu');
  await expect(page.getByRole('heading', { name: 'Load Career' })).toBeVisible();
  await expect(page.getByText(saveId, { exact: true })).toBeVisible();
  await page.locator('article.save-card').filter({ hasText: saveId }).getByRole('link', { name: 'Load Career' }).click();

  await expect(page.getByRole('main')).toBeVisible();
  await expect(page.getByRole('heading', { level: 1 })).toContainText('P3-019 Browser Player');
  await expect(page.getByRole('navigation', { name: 'Career navigation' })).toBeVisible();
  await expect(page.getByRole('link', { name: 'Training', exact: true })).toBeVisible();
  await expect(page.locator('a[aria-current="page"]')).toHaveText('Career Home');
  await expectNoPageOverflow(page);
  await saveReleaseScreenshot(page, testInfo, 'career-home');
});

test('Training remains labelled, interactive, and returns to Home', async ({ page }, testInfo) => {
  const saveId = saveIds.get(testInfo.project.name);

  await page.goto(`/?page=home&save=${encodeURIComponent(saveId)}`);
  await page.getByRole('link', { name: 'Training', exact: true }).click();
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
  await expect(page.getByText('PRE-MATCH', { exact: true })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Advance to Match' })).toBeVisible();
  await expectNoPageOverflow(page);

  await page.getByRole('button', { name: 'Advance to Match' }).click();
  await expect(page).toHaveURL(/page=matchday/);
  await expect(page.getByText('YOUR MATCH', { exact: true })).toBeVisible();
  await expect(page.getByRole('link', { name: 'Career Home', exact: true }).first()).toBeVisible();
  await expectNoPageOverflow(page);
  await saveReleaseScreenshot(page, testInfo, 'post-match');
});

test('Missing Career recovery is bounded and actionable', async ({ page }) => {
  await page.goto('/?page=home&save=p3019-browser-missing');
  await expect(page).toHaveURL(/page=menu/);
  await expect(page.getByRole('status')).toContainText('That saved career could not be found.');
  await expect(page.getByRole('link', { name: 'New Career' })).toBeVisible();
  await expect(page.locator('body')).not.toContainText(/SQL|SQLite|PDOException|\/data\/|stack trace/i);
});

async function createCareer(context, saveId) {
  const page = await context.newPage();

  await page.goto('/?page=new&step=identity');
  await expect(page.getByRole('heading', { name: 'Identity' })).toBeVisible();
  await page.getByLabel('Save name').fill(saveId);
  await page.getByLabel('Player name').fill('P3-019 Browser Player');
  await page.getByLabel('Nationality').selectOption({ index: 0 });
  await page.getByRole('button', { name: 'Continue', exact: true }).click();

  await expect(page.getByRole('heading', { name: 'Body' })).toBeVisible();
  await page.getByLabel('Height').fill('180');
  await page.getByLabel('Weight').fill('75');
  await page.getByRole('button', { name: 'Continue', exact: true }).click();

  await expect(page.getByRole('heading', { name: 'Avatar Creator' })).toBeVisible();
  await page.getByRole('button', { name: 'Confirm appearance' }).click();

  await expect(page.getByRole('heading', { name: 'Football Profile' })).toBeVisible();
  await page.getByLabel('Position').selectOption('CM');
  await page.getByLabel('Preferred foot').selectOption('right');
  await page.getByLabel('Development path').selectOption('regular');
  await page.getByRole('button', { name: 'Review profile' }).click();

  await expect(page.getByRole('heading', { name: 'Review' })).toBeVisible();
  await page.getByRole('button', { name: 'Enter Youth Camp' }).click();
  await expect(page.getByRole('heading', { name: 'Youth Camp' })).toBeVisible();
  await page.getByRole('button', { name: 'Join this Club' }).first().click();
  await expect(page).toHaveURL(new RegExp(`page=home.*save=${saveId}`));
  await page.close();
}

async function expectNoPageOverflow(page) {
  const dimensions = await page.evaluate(() => ({
    clientWidth: document.documentElement.clientWidth,
    scrollWidth: document.documentElement.scrollWidth,
  }));
  expect(dimensions.scrollWidth, 'the page itself must not overflow horizontally').toBeLessThanOrEqual(dimensions.clientWidth + 1);
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
