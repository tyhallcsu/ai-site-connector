// Headless audit of every AI Site Connector admin tab on the local dev site,
// at desktop (1280 px) and phone (375 px) widths. Run it through
// `bin/dev-site.sh audit [OUT_DIR]`, which supplies the site URL and admin
// credentials as environment variables and the playwright module path.
//
// Per tab it reports horizontal page overflow, visible form controls without
// an accessible name, duplicate id attributes, PHP warnings in the page,
// JavaScript errors and HTTP >= 400 responses. Screenshots and report.json
// go to OUT_DIR. Exits 1 when any tab has a finding.
'use strict';

const { chromium } = require('playwright');
const fs = require('node:fs');
const path = require('node:path');

const base = process.env.ASC_DEV_URL;
const user = process.env.ASC_DEV_ADMIN_USER;
const pass = process.env.ASC_DEV_ADMIN_PASSWORD;
const out = process.argv[2];
const tabs = ['onboarding', 'overview', 'connection', 'wizard', 'credentials', 'permissions', 'audit', 'api', 'diagnostics', 'export', 'docs'];

if (!base || !user || !pass || !out) {
  console.error('usage: bin/dev-site.sh audit [OUT_DIR]');
  process.exit(2);
}
fs.mkdirSync(out, { recursive: true });

function inspectPage() {
  const visible = (el) => !!(el.offsetWidth || el.offsetHeight || el.getClientRects().length);
  const wrap = document.querySelector('.ai-site-connector-wrap') || document.body;
  const named = (el) => {
    if (el.getAttribute('aria-label') || el.getAttribute('aria-labelledby') || el.title) return true;
    if (el.id && document.querySelector('label[for="' + CSS.escape(el.id) + '"]')) return true;
    return !!el.closest('label') || ['submit', 'button', 'reset'].includes(el.type);
  };
  const unlabeled = [...wrap.querySelectorAll('input:not([type=hidden]), select, textarea')]
    .filter(visible).filter((el) => !named(el))
    .map((el) => el.tagName.toLowerCase() + '[name=' + (el.name || '') + ']');
  const ids = [...document.querySelectorAll('[id]')].map((el) => el.id);
  const duplicateIds = [...new Set(ids.filter((id, i) => ids.indexOf(id) !== i))];
  return {
    overflowX: document.documentElement.scrollWidth - window.innerWidth,
    unlabeled,
    duplicateIds,
    phpNoise: /(Warning|Notice|Deprecated|Fatal error):/.test(document.body.innerText),
  };
}

async function auditViewport(browser, label, viewport, report) {
  const context = await browser.newContext({ viewport });
  const page = await context.newPage();
  let events = [];
  page.on('console', (m) => { if (m.type() === 'error') events.push('console: ' + m.text().slice(0, 200)); });
  page.on('pageerror', (e) => events.push('pageerror: ' + String(e).slice(0, 200)));
  page.on('response', (r) => { if (r.status() >= 400) events.push('http ' + r.status() + ' ' + r.url()); });

  await page.goto(base + '/wp-login.php');
  await page.fill('#user_login', user);
  await page.fill('#user_pass', pass);
  await Promise.all([page.waitForNavigation(), page.click('#wp-submit')]);
  if (!page.url().includes('/wp-admin')) throw new Error('wp-admin login failed');

  for (const tab of tabs) {
    events = [];
    const response = await page.goto(base + '/wp-admin/tools.php?page=ai-site-connector&tab=' + tab, { waitUntil: 'networkidle' });
    const facts = await page.evaluate(inspectPage);
    await page.screenshot({ path: path.join(out, label + '-' + tab + '.png'), fullPage: true });
    const findings = [];
    if (response.status() !== 200) findings.push('HTTP ' + response.status());
    if (facts.overflowX > 0) findings.push('page overflows by ' + facts.overflowX + 'px');
    if (facts.unlabeled.length) findings.push(facts.unlabeled.length + ' unlabeled control(s)');
    if (facts.duplicateIds.length) findings.push('duplicate ids: ' + facts.duplicateIds.join(', '));
    if (facts.phpNoise) findings.push('PHP warning text in page');
    if (events.length) findings.push(events.length + ' JS/HTTP error(s)');
    report.push({ viewport: label, tab, findings, ...facts, events });
    console.log((label + ' ').padEnd(8) + tab.padEnd(12) + (findings.join('; ') || 'ok'));
  }
  await context.close();
}

(async () => {
  const browser = await chromium.launch({ headless: true });
  const report = [];
  try {
    await auditViewport(browser, 'desktop', { width: 1280, height: 900 }, report);
    await auditViewport(browser, 'phone', { width: 375, height: 812 }, report);
  } finally {
    await browser.close();
  }
  fs.writeFileSync(path.join(out, 'report.json'), JSON.stringify(report, null, 2));
  const failing = report.filter((r) => r.findings.length).length;
  console.log(failing ? failing + ' tab view(s) with findings; details in ' + out : 'all tabs clean');
  process.exit(failing ? 1 : 0);
})().catch((e) => {
  console.error(e);
  process.exit(2);
});
