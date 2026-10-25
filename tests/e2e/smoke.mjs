/**
 * Browser smoke test: signs in, then opens every dashboard page in light and dark mode at
 * desktop and phone widths. Fails on console errors, failed API calls, a missing page
 * heading, or horizontal overflow.
 *
 * Needs a running, seeded app:  php artisan serve  then  npm run test:e2e
 * Set BASE_URL to test another address.
 */
import { chromium } from 'playwright-core';

const BASE_URL = (process.env.BASE_URL ?? 'http://127.0.0.1:8000').replace(/\/$/, '');
const VIEWPORTS = { desktop: { width: 1280, height: 800 }, mobile: { width: 390, height: 844 } };
const THEMES = ['light', 'dark'];
const PAGES = ['/', '/events', '/retention', '/funnels', '/players', '/settings'];

const browser = await chromium.launch();
const failures = [];

async function signIn(context) {
    const page = await context.newPage();
    await page.goto(`${BASE_URL}/login`);
    await page.fill('input[type=email]', 'admin@example.com');
    await page.fill('input[type=password]', 'password');
    await page.click('button[type=submit]');
    await page.waitForURL(`${BASE_URL}/`);

    // Pick a real player so the profile page is covered too.
    const games = await page.evaluate(async () => (await fetch('/api/admin/games', { headers: { Accept: 'application/json' } })).json());
    const gameId = games.data.sort((a, b) => b.players_count - a.players_count)[0].id;
    const list = await page.evaluate(
        async (id) => (await fetch(`/api/admin/games/${id}/players`, { headers: { Accept: 'application/json' } })).json(),
        gameId,
    );
    await page.close();

    return list.data[0].id;
}

async function check(context, path, label) {
    const page = await context.newPage();
    const problems = [];

    page.on('console', (message) => message.type() === 'error' && problems.push(`console: ${message.text()}`));
    page.on('pageerror', (error) => problems.push(`page error: ${error.message}`));
    page.on('response', (response) => {
        if (response.url().includes('/api/') && response.status() >= 400) {
            problems.push(`HTTP ${response.status()} ${response.url()}`);
        }
    });

    await page.goto(`${BASE_URL}${path}`);
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(300);

    if (!(await page.locator('h1').first().isVisible())) {
        problems.push('no page heading');
    }

    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    if (overflow > 0) {
        problems.push(`horizontal overflow of ${overflow}px`);
    }

    await page.close();

    for (const problem of problems) {
        failures.push(`${label} ${path}: ${problem}`);
    }
    console.log(`${problems.length ? '✗' : '✓'} ${label} ${path}`);
}

try {
    for (const [size, viewport] of Object.entries(VIEWPORTS)) {
        for (const theme of THEMES) {
            const context = await browser.newContext({ viewport, colorScheme: theme });
            const playerId = await signIn(context);

            for (const path of [...PAGES, `/players/${playerId}`]) {
                await check(context, path, `[${size} ${theme}]`);
            }

            await context.close();
        }
    }
} finally {
    await browser.close();
}

if (failures.length) {
    console.error(`\n${failures.length} problem(s):\n${failures.join('\n')}`);
    process.exit(1);
}

console.log('\nAll pages rendered cleanly.');
