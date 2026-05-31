import {defineConfig, devices} from '@playwright/test';

/**
 * Tier-2 smoke replays (see .claude/skills/smoke-report + tests/smoke/README.md).
 *
 * These drive the running dev app at https://localhost:8443 — they do NOT start it.
 * Preconditions: the docker dev stack is up + migrated, and (for brand-new frontend
 * modules) the frontend container has been restarted. Each spec seeds and tears down
 * its own data, so it is safe to run repeatedly and on a fresh database.
 */
export default defineConfig({
    testDir: './tests/smoke',
    testMatch: '**/*.spec.ts',
    fullyParallel: false,
    workers: 1,
    retries: 0,
    timeout: 90_000,
    reporter: 'list',
    use: {
        baseURL: process.env.SMOKE_BASE_URL ?? 'https://localhost:8443',
        ignoreHTTPSErrors: true,
        viewport: {width: 1440, height: 1000},
        screenshot: 'off',
        trace: 'retain-on-failure',
    },
    projects: [
        {name: 'chromium', use: {...devices['Desktop Chrome']}},
    ],
});
