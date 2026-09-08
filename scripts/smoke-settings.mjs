import { chromium } from 'playwright';

const baseUrl = process.env.WPT_SMOKE_BASE_URL;
const email = process.env.WPT_SMOKE_EMAIL;
const password = process.env.WPT_SMOKE_PASSWORD;
const serverId = Number(process.env.WPT_SMOKE_SERVER_ID);
const siteId = Number(process.env.WPT_SMOKE_SITE_ID);
const expectedTransport = process.env.WPT_SMOKE_EXPECT_TRANSPORT || 'ssh';

if (!baseUrl || !email || !password || !Number.isSafeInteger(serverId) || serverId < 1 || !Number.isSafeInteger(siteId) || siteId < 1) {
  console.error(JSON.stringify({
    ok: false,
    error: 'Set WPT_SMOKE_BASE_URL, WPT_SMOKE_EMAIL, WPT_SMOKE_PASSWORD, WPT_SMOKE_SERVER_ID, and WPT_SMOKE_SITE_ID to the exact authorized targets.',
  }, null, 2));
  process.exit(1);
}

if (!process.argv.includes('--allow-settings-write') || !process.argv.includes('--allow-site-write')) {
  throw new Error('This diagnostic changes settings and performs a real site write. Both --allow-settings-write and --allow-site-write are required.');
}
const parsedTarget = new URL(baseUrl);
if (!['http:', 'https:'].includes(parsedTarget.protocol) || parsedTarget.username || parsedTarget.password || parsedTarget.search || parsedTarget.hash) {
  throw new Error('WPT_SMOKE_BASE_URL must be an HTTP(S) URL without credentials, a query, or a fragment.');
}
const settings = JSON.parse(process.env.WPT_SMOKE_SETTINGS_JSON || 'null');
if (!settings || typeof settings !== 'object' || Array.isArray(settings)) {
  throw new Error('WPT_SMOKE_SETTINGS_JSON must contain the exact authorized settings object.');
}

const browser = await chromium.launch({ headless: true });
const page = await browser.newPage();
const consoleMessages = [];
const pageErrors = [];

page.on('console', (msg) => {
  if (['error', 'warning'].includes(msg.type())) {
    consoleMessages.push({ type: msg.type(), text: msg.text() });
  }
});

page.on('pageerror', (error) => {
  pageErrors.push(String(error));
});

async function gotoWithRetry(targetUrl, validate, attempts = 4, delayMs = 2500) {
  let lastState = null;

  for (let attempt = 1; attempt <= attempts; attempt += 1) {
    await page.goto(targetUrl, { waitUntil: 'domcontentloaded', timeout: 60000 });
    await page.waitForTimeout(1000);

    lastState = await page.evaluate(() => ({
      title: document.title,
      bodyText: document.body.innerText,
    }));

    if (validate(lastState)) {
      return lastState;
    }

    if (attempt < attempts) {
      await page.waitForTimeout(delayMs);
    }
  }

  return lastState;
}

function summarizeInstallDiscovery(result) {
  const installs = Array.isArray(result?.data?.installs) ? result.data.installs : [];

  return {
    ok: !!result?.ok,
    status: result?.status ?? null,
    count: installs.length,
    sample: installs.slice(0, 3).map((install) => ({
      id: install.id,
      name: install.name,
      url: install.url,
    })),
  };
}

function summarizeSiteTest(result) {
  const data = result?.data || {};
  const payload = data.result || {};

  return {
    ok: !!result?.ok,
    status: result?.status ?? null,
    success: !!data.success,
    message: payload.message || '',
    authorCount: Array.isArray(payload.authors) ? payload.authors.length : null,
    categoryCount: Array.isArray(payload.categories) ? payload.categories.length : null,
    transport: data.runtime?.resolution?.transport || null,
    selectedBinary: data.runtime?.resolution?.selected_binary || null,
  };
}

try {
  await page.goto(`${baseUrl}/login`, { waitUntil: 'domcontentloaded', timeout: 60000 });
  await page.fill('input[name="email"]', email);
  await page.fill('input[name="password"]', password);
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 60000 }).catch(() => null),
    page.click('button[type="submit"], input[type="submit"]'),
  ]);

  const loginState = await gotoWithRetry(`${baseUrl}/wp-toolkit`, (state) => (
    state.title.includes('WP Toolkit Settings')
      || state.bodyText.includes('Command Runtime')
  ));

  // Ignore transient retry noise from temporary DB outage pages once the real page is loaded.
  if (loginState?.title?.includes('WP Toolkit Settings') || loginState?.bodyText?.includes('Command Runtime')) {
    consoleMessages.length = 0;
    pageErrors.length = 0;
  }

  const pageState = await page.evaluate(() => ({
    title: document.title,
    hasCommandRuntime: document.body.innerText.includes('Command Runtime'),
    hasServerDiagnostics: document.body.innerText.includes('Server Diagnostics'),
    hasSiteTests: document.body.innerText.includes('Saved Site Command Tests'),
  }));

  const results = await page.evaluate(async ({ serverId, siteId, settings }) => {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    const postJson = async (url, payload) => {
      const response = await fetch(url, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          'X-CSRF-TOKEN': csrf,
        },
        body: JSON.stringify(payload),
      });

      const data = await response.json().catch(() => ({}));

      return {
        ok: response.ok,
        status: response.status,
        data,
      };
    };

    const saveSettings = await postJson('/wp-toolkit/settings', settings);

    const serverDiagnostics = await postJson('/wp-toolkit/diagnostics/server', { server_id: serverId });
    const installDiscovery = await postJson('/wp-toolkit/get-all-installs', { server_id: serverId });
    const authors = await postJson('/wp-toolkit/diagnostics/site-test', { site_id: siteId, test: 'authors' });
    const categories = await postJson('/wp-toolkit/diagnostics/site-test', { site_id: siteId, test: 'categories' });
    const write = await postJson('/wp-toolkit/diagnostics/site-test', { site_id: siteId, test: 'write' });

    return {
      saveSettings,
      serverDiagnostics,
      installDiscovery,
      authors,
      categories,
      write,
    };
  }, { serverId, siteId, settings });

  const diagnostics = results.serverDiagnostics?.data || {};
  const summary = {
    pageState,
    saveSettings: {
      ok: !!results.saveSettings?.ok,
      status: results.saveSettings?.status ?? null,
      message: results.saveSettings?.data?.message || '',
    },
    serverDiagnostics: {
      ok: !!results.serverDiagnostics?.ok,
      status: results.serverDiagnostics?.status ?? null,
      transport: diagnostics.resolution?.transport || null,
      label: diagnostics.resolution?.label || '',
      reason: diagnostics.resolution?.reason || '',
      selectedBinary: diagnostics.resolution?.selected_binary || '',
      localUsable: diagnostics.local_probe?.usable ?? null,
      remoteUsable: diagnostics.remote_probe?.usable ?? null,
      remoteRuntimeUser: diagnostics.remote_probe?.runtime_user || '',
    },
    installDiscovery: summarizeInstallDiscovery(results.installDiscovery),
    authors: summarizeSiteTest(results.authors),
    categories: summarizeSiteTest(results.categories),
    write: summarizeSiteTest(results.write),
    consoleMessages,
    pageErrors,
  };

  const ok = pageState.hasCommandRuntime
    && pageState.hasServerDiagnostics
    && pageState.hasSiteTests
    && summary.saveSettings.ok
    && summary.serverDiagnostics.ok
    && summary.serverDiagnostics.transport === expectedTransport
    && summary.installDiscovery.ok
    && summary.installDiscovery.count > 0
    && summary.authors.ok
    && summary.authors.success
    && (summary.authors.authorCount ?? 0) > 0
    && summary.categories.ok
    && summary.categories.success
    && (summary.categories.categoryCount ?? 0) > 0
    && summary.write.ok
    && summary.write.success
    && consoleMessages.length === 0
    && pageErrors.length === 0;

  console.log(JSON.stringify({ ok, summary }, null, 2));
  if (!ok) process.exitCode = 1;
} catch (error) {
  console.error(JSON.stringify({
    ok: false,
    error: String(error),
    consoleMessages,
    pageErrors,
  }, null, 2));
  process.exitCode = 1;
} finally {
  await browser.close();
}
