import {execSync} from 'node:child_process';
import {mkdirSync} from 'node:fs';
import {dirname, resolve} from 'node:path';
import {fileURLToPath} from 'node:url';
import {expect, Page, test} from '@playwright/test';

const here = dirname(fileURLToPath(import.meta.url));

/**
 * Tier-2 reproducible smoke replay for offline payment reversal.
 *
 * Deterministic by construction: it seeds its own account → event → offline
 * order (via `php artisan smoke:reversal-fixture`), drives the Manage-Order
 * payments panel to record a cash payment and then reverse it, asserts the
 * resulting states, captures a screenshot per step, and tears the fixture down.
 *
 * It uses semantic locators + auto-waiting expects (never refs or fixed sleeps),
 * and asserts on roles/text — dates and the random order public_id vary per run,
 * so this validates the same *states*, not identical pixels.
 *
 * Run: `npm run test:smoke` (dev stack must be up + migrated).
 * Then rebuild the HTML report from the captured shots:
 *   node ../ops/smoke/build-report.mjs \
 *     --manifest ../ops/smoke/payment-reversal/manifest.json \
 *     --out ../ops/smoke/payment-reversal/report.html
 */

const COMPOSE = '../docker/development/docker-compose.dev.yml';
const SHOTS = resolve(here, '../../../ops/smoke/payment-reversal/shots');

interface Fixture {
    email: string;
    password: string;
    eventId: number;
    orderPublicId: string;
}

function artisanFixture(args = ''): string {
    return execSync(
        `docker compose -f ${COMPOSE} exec -T backend php artisan smoke:reversal-fixture ${args}`,
        {encoding: 'utf-8'},
    );
}

let fixture: Fixture;

test.beforeAll(() => {
    mkdirSync(SHOTS, {recursive: true});
    const out = artisanFixture();
    const line = out.split('\n').find((l) => l.startsWith('FIXTURE_JSON='));
    if (!line) {
        throw new Error(`Seeder did not emit FIXTURE_JSON. Output:\n${out}`);
    }
    fixture = JSON.parse(line.replace('FIXTURE_JSON=', '').trim());
});

test.afterAll(() => {
    artisanFixture('--down');
});

async function shot(page: Page, name: string) {
    await page.screenshot({path: resolve(SHOTS, name)});
}

async function login(page: Page) {
    await page.goto('/auth/login');
    await page.getByRole('textbox', {name: 'Email'}).fill(fixture.email);
    await page.locator('input[type="password"]').fill(fixture.password);
    await page.getByRole('button', {name: 'Log in'}).click();
    await page.waitForURL(/\/manage\//, {timeout: 30_000});
}

test('record a cash payment then reverse it, restoring the balance', async ({page}) => {
    await login(page);

    // Open the seeded order's Manage-Order drawer.
    await page.goto(`/manage/event/${fixture.eventId}/orders`);
    await page.getByText(fixture.orderPublicId).click();

    // Expand the Payments panel — outstanding balance, empty ledger.
    await page.getByRole('button', {name: /Payments/}).click();
    await expect(page.getByText('Outstanding')).toBeVisible();
    await page.getByText('Comp remaining').scrollIntoViewIfNeeded();
    await shot(page, '01-panel-initial.png');

    // Record a $1.57 cash payment (amount defaults to the outstanding balance).
    await page.getByRole('textbox', {name: /Reference/}).fill('receipt #DEV-12');
    await shot(page, '02-record-cash-filled.png');
    await page.getByRole('button', {name: 'Record payment'}).click();

    // Order settles; the new ledger row exposes a per-row reverse action.
    await expect(page.getByText(/settled/i)).toBeVisible();
    const reverseAction = page.getByRole('button', {name: 'Reverse this payment'});
    await expect(reverseAction).toBeVisible();
    await reverseAction.scrollIntoViewIfNeeded();
    await shot(page, '03-settled-with-ledger-row.png');

    // Open the reverse modal — reason required.
    await reverseAction.click();
    const reasonBox = page.getByRole('textbox', {name: 'Reason'});
    await expect(reasonBox).toBeVisible();
    await shot(page, '04-reverse-modal-empty.png');

    await reasonBox.fill('Wrong amount entered — cash was never collected, correcting the ledger.');
    await shot(page, '05-reverse-modal-reason.png');
    await page.getByRole('button', {name: 'Reverse payment'}).click();

    // Reversal booked: original badged Reversed, balance restored to outstanding.
    // exact:true targets the badge, not the "Payment reversed" success toast.
    await expect(page.getByText('Reversed', {exact: true})).toBeVisible();
    await expect(page.getByText('Outstanding')).toBeVisible();
    await shot(page, '06-after-reversal.png');
});
