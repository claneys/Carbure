// End-to-end tests of the portal, against the real API (src/api.php) and a MariaDB
// database recreated before each test. See README.md.
const os = require('os');
const path = require('path');
const { defineConfig, devices } = require('@playwright/test');

const port = Number(process.env.E2E_PORT || 8091);
const baseURL = process.env.E2E_BASE_URL || `http://127.0.0.1:${port}`;
// Temporary files of the run (state of the fake woob), shared by the server and the tests:
// one directory per port, so that another run on the same machine (other port, or the
// PHPUnit suites) does not see them
process.env.CARBURE_TEST_TMP ||= path.join(os.tmpdir(), `carbure-e2e-${port}`);
// Push service of the server: the fake one that the specs start on this port
// (fake-push-relay.js); nothing listens there otherwise (push service unreachable)
process.env.E2E_PUSH_PORT ||= String(port + 10);

module.exports = defineConfig({
    testDir: './specs',
    // One database for all the tests: they run one after the other
    workers: 1,
    fullyParallel: false,
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 1 : 0,
    timeout: 30_000,
    expect: { timeout: 5_000 },
    // HTML report in playwright-report/ (npx playwright show-report)
    reporter: [['list'], ['html', { open: 'never' }]],
    use: {
        baseURL,
        locale: 'fr-FR',
        timezoneId: 'Europe/Paris',
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
    },
    projects: [
        { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
        // The contrasts again in the dark theme, chosen by the system preference (the
        // default "system" choice of a profile)
        {
            name: 'chromium-dark',
            testMatch: /accessibility\.spec\.js/,
            use: { ...devices['Desktop Chrome'], colorScheme: 'dark' },
        },
    ],
    // PHP built-in server with the e2e router (PHP with mysqli, see README.md)
    webServer: process.env.E2E_BASE_URL ? undefined : {
        command: `${process.env.PHP_BIN || 'php'} -S 127.0.0.1:${port} -t ../.. server/router.php`,
        url: `${baseURL}/portal/`,
        reuseExistingServer: !process.env.CI,
        timeout: 30_000,
        env: {
            APP_ENV: 'e2e',
            CARBURE_TEST_TMP: process.env.CARBURE_TEST_TMP,
            CARBURE_PUSH_URL: `http://127.0.0.1:${process.env.E2E_PUSH_PORT}`,
            // The bank synchronization streams its progress: several requests at a time
            PHP_CLI_SERVER_WORKERS: '4',
            E2E_DB_HOST: process.env.E2E_DB_HOST || '127.0.0.1',
            E2E_DB_PORT: process.env.E2E_DB_PORT || '3306',
            E2E_DB_USER: process.env.E2E_DB_USER || 'root',
            E2E_DB_PASSWORD: process.env.E2E_DB_PASSWORD || '',
        },
    },
});
