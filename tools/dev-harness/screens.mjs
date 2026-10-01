#!/usr/bin/env node
// Full-page screenshots of the main pages at three viewports.
//
//   BASE_URL=http://127.0.0.1:8010 node tools/dev-harness/screens.mjs <outDir> [filter]
//
// Prereqs (see README): production assets built, public/hot absent, `php artisan serve` running.
// Playwright is NOT a repo dependency: it is resolved from $PLAYWRIGHT_MODULE_DIR, the global npm
// root, or the repo's node_modules. Browser: the preinstalled Chromium in $PLAYWRIGHT_BROWSERS_PATH.
import { createRequire } from 'node:module';
import { execSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';

const outDir = process.argv[2];
const filter = process.argv[3] ? new RegExp(process.argv[3]) : null;
if (!outDir) {
  console.error('usage: node tools/dev-harness/screens.mjs <outDir> [regexFilterOnName]');
  process.exit(2);
}
const BASE_URL = (process.env.BASE_URL || 'http://127.0.0.1:8010').replace(/\/$/, '');
process.env.PLAYWRIGHT_BROWSERS_PATH ||= '/opt/pw-browsers';

function loadPlaywright() {
  const dirs = [process.env.PLAYWRIGHT_MODULE_DIR, process.cwd()];
  try { dirs.push(execSync('npm root -g', { encoding: 'utf8' }).trim()); } catch {}
  for (const d of dirs.filter(Boolean)) {
    try { return createRequire(path.join(d, 'noop.js'))('playwright'); } catch {}
    try { return createRequire(path.join(d, 'node_modules', 'noop.js'))('playwright'); } catch {}
  }
  throw new Error('playwright not found: `npm i -g playwright@<version matching /opt/pw-browsers chromium build>` or set PLAYWRIGHT_MODULE_DIR');
}
const { chromium } = loadPlaywright();

const VIEWPORTS = {
  desktop: { viewport: { width: 1440, height: 900 } },
  laptop: { viewport: { width: 1050, height: 800 } }, // known bug: no navigation between 1024 and 1071px
  mobile: { viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, deviceScaleFactor: 2 },
};

const PAGES = [
  ...['/dashboard', '/work', '/actions', '/conflict', '/journal', '/messages', '/new-york',
      '/new-york/bank', '/career/police', '/settings', '/leaderboard', '/new-york/cityhall']
    .map((p) => ({ user: 'police', path: p })),
  { user: 'corporation', path: '/career/corporate' },
];

const slug = (p) => p.replace(/^\//, '').replace(/\//g, '_') || 'root';

async function login(context, user) {
  const req = context.request;
  await req.get(`${BASE_URL}/dev-login`);
  const xsrf = (await context.cookies(BASE_URL)).find((c) => c.name === 'XSRF-TOKEN');
  if (!xsrf) throw new Error('no XSRF-TOKEN cookie after GET /dev-login (is APP_ENV=local?)');
  const res = await req.post(`${BASE_URL}/dev-login`, {
    form: { email: `p_${user}@test.local`, password: 'password' },
    headers: { 'X-XSRF-TOKEN': decodeURIComponent(xsrf.value), Accept: 'text/html' },
    maxRedirects: 0,
  });
  const loc = res.headers()['location'] || '';
  if (res.status() !== 302 || !/dashboard/.test(loc)) {
    throw new Error(`login as p_${user} failed: ${res.status()} -> ${loc}`);
  }
}

fs.mkdirSync(outDir, { recursive: true });
const browser = await chromium.launch();
const results = [];
try {
  for (const [vpName, vpOpts] of Object.entries(VIEWPORTS)) {
    const contexts = {};
    for (const pg of PAGES) {
      const name = `${vpName}__${pg.user}__${slug(pg.path)}`;
      if (filter && !filter.test(name)) continue;
      if (!contexts[pg.user]) {
        contexts[pg.user] = await browser.newContext({ ...vpOpts, baseURL: BASE_URL });
        await login(contexts[pg.user], pg.user);
      }
      const page = await contexts[pg.user].newPage();
      const errors = [];
      page.on('pageerror', (e) => errors.push(e.message));
      let status = 0;
      try {
        const resp = await page.goto(pg.path, { waitUntil: 'networkidle', timeout: 30000 });
        status = resp ? resp.status() : 0;
      } catch (e) {
        errors.push(`goto: ${e.message.split('\n')[0]}`);
      }
      await page.waitForTimeout(1200); // card-flip animation settle
      const file = path.join(outDir, `${name}.png`);
      await page.screenshot({ path: file, fullPage: true });
      const finalUrl = page.url().replace(BASE_URL, '');
      results.push({ name, status, finalUrl, errors: errors.length });
      console.log(`${String(status).padEnd(4)} ${name.padEnd(46)} ${finalUrl}${errors.length ? `  [${errors.length} page errors: ${errors[0].slice(0, 120)}]` : ''}`);
      await page.close();
    }
    for (const c of Object.values(contexts)) await c.close();
  }
} finally {
  await browser.close();
}
fs.writeFileSync(path.join(outDir, 'index.json'), JSON.stringify(results, null, 2));
