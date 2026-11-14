// Website "Tools" tab and the recycle bin (0.11.0): desktop + phone, light + dark, any language (LANG_CODE). Flags sideways scrolling
// and JS errors. Same dry server as ui-qa.cjs:  PANEL_PASS='...' node tests/site-tools-qa.cjs
const PW = process.env.PW || 'playwright-core';
const { chromium } = require(PW);
const fs = require('fs');
const OUT = process.env.HOME + '/zarsco/screens-qa-panel/';
const BASE = process.env.PANEL_BASE || 'http://127.0.0.1:8085';
const LANG = process.env.LANG_CODE || 'en';
const PARTS = ['WordPress', 'Laravel', 'Background programs', 'Staging copy', 'Speed and protection'];

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const b = await chromium.launch({ executablePath: '/usr/bin/google-chrome' });
  const flags = []; let n = 0;
  for (const [w, h, size] of [[1280, 860, 'desk'], [390, 844, 'phone']]) for (const scheme of ['light', 'dark']) {
    const p = await (await b.newContext({ viewport: { width: w, height: h }, colorScheme: scheme })).newPage();
    const errs = [];
    p.on('pageerror', (e) => errs.push(String(e).slice(0, 140)));
    await p.goto(BASE + '/'); await p.waitForTimeout(1200);
    await p.locator('input').first().fill(process.env.PANEL_USER || 'admin');
    const pw = p.locator('input[type=password]'); await pw.fill(process.env.PANEL_PASS || ''); await pw.press('Enter'); await p.waitForTimeout(2000);
    await p.evaluate(async (code) => {
      const me = await (await fetch('/api/me')).json();
      const h = { 'Content-Type': 'application/json', 'X-CSRF-Token': me.csrf };
      await fetch('/api/me/language', { method: 'POST', headers: h, body: JSON.stringify({ language: 'en' }) }); // English labels to click, language set at the end
      const sites = await (await fetch('/api/sites')).json();
      if (!sites.sites.length) await fetch('/api/sites', { method: 'POST', headers: h, body: JSON.stringify({ domain: 'qa-shop.example.com', type: 'static' }) });
    }, LANG);
    const shot = async (name) => {
      const info = await p.evaluate(() => ({ sw: document.documentElement.scrollWidth - innerWidth,
        inner: [...document.querySelectorAll('main *, .modal *')].filter((e) => { const cs = getComputedStyle(e); return /(auto|scroll)/.test(cs.overflowX) && e.scrollWidth > e.clientWidth + 2 && e.clientWidth > 50 && e.tagName !== 'PRE' }).length }));
      const file = size + '-' + scheme + '-site-' + name + (LANG === 'en' ? '' : '-' + LANG);
      await p.screenshot({ path: OUT + file + '.png', fullPage: true }); n++;
      if (info.sw > 1) flags.push(file + ': page sideways ' + info.sw);
      if (info.inner) flags.push(file + ': inside sideways');
    };
    await p.goto(BASE + '/#sites'); await p.waitForTimeout(1500);
    await p.getByRole('button', { name: 'Manage' }).first().click(); await p.waitForTimeout(1200);
    if (LANG !== 'en') { await p.evaluate(async (code) => { const me = await (await fetch('/api/me')).json(); await fetch('/api/me/language', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': me.csrf }, body: JSON.stringify({ language: code }) }) }, LANG) }
    await p.getByRole('tab', { name: 'Tools', exact: true }).click(); await p.waitForTimeout(800);
    for (const part of PARTS) {
      await p.getByRole('tab', { name: part, exact: true }).click(); await p.waitForTimeout(900);
      await shot(part.toLowerCase().replace(/ /g, '-'));
    }
    await p.getByRole('tab', { name: 'Files', exact: true }).click(); await p.waitForTimeout(900);
    await p.getByRole('button', { name: 'Recycle bin', exact: true }).click(); await p.waitForTimeout(900);
    await shot('recycle-bin');
    if (errs.length) flags.push(size + '-' + scheme + ': ' + errs.join(' / '));
    await p.context().close();
  }
  console.log(n + ' screens, ' + flags.length + ' flags'); console.log(flags.join('\n'));
  await b.close();
})();
