// ZARS Panel UI QA: every page by its #hash, desktop + phone, light + dark. Flags sideways scrolling (page or inside),
// JS errors and NaN / undefined text. Screens + flags in ~/zarsco/screens-qa-panel.
//   ZARSPANEL_HOME=/tmp/zp ZARSPANEL_ETC=/tmp/zp/etc ZARSPANEL_WWW=/tmp/zp/www ZARSPANEL_DRY=1 php -S 127.0.0.1:8085 -t public public/index.php   (owner admin / see below)
//   PANEL_PASS='...' node tests/ui-qa.cjs          LANG_CODE=hi checks the panel in Hindi (screens get a -hi name)
const PW = process.env.PW || 'playwright-core';
const { chromium } = require(PW);
const fs = require('fs');
const OUT = process.env.HOME + '/zarsco/screens-qa-panel/';
const LANG = process.env.LANG_CODE || 'en';
fs.mkdirSync(OUT, { recursive: true });
const BASE = process.env.PANEL_BASE || 'http://127.0.0.1:8085';
const PAGES = ['home', 'overview', 'sites', 'apps', 'databases', 'dns', 'ftp', 'mail', 'redis', 'docker', 'terminal', 'cron', 'backups', 'firewall', 'tools', 'plans', 'import', 'tickets', 'users', 'security', 'settings', 'uptime', 'migrate', 'cloudflare', 'tools/advisor', 'tools/history', 'tools/services', 'tools/journal', 'tools/time', 'tools/jails', 'tools/countries', 'tools/access', 'tools/rclone', 'tools/cluster', 'tools/imunify', 'tools/limits', 'tools/siteusers', 'billing'];

(async () => {
  const b = await chromium.launch({ executablePath: '/usr/bin/google-chrome' });
  const flags = []; let n = 0;
  for (const [w, h, size] of [[1280, 860, 'desk'], [390, 844, 'phone']]) for (const scheme of ['light', 'dark']) {
    const ctx = await b.newContext({ viewport: { width: w, height: h }, colorScheme: scheme });
    const p = await ctx.newPage(); let errs = [];
    p.on('pageerror', (e) => errs.push('JS ' + String(e).slice(0, 140)));
    p.on('console', (m) => { if (m.type() === 'error' && !/favicon|Failed to load resource/.test(m.text())) errs.push(m.text().slice(0, 140)) });
    await p.goto(BASE + '/'); await p.waitForTimeout(1500);
    await p.locator('input').first().fill(process.env.PANEL_USER || 'admin');
    const pw = p.locator('input[type=password]'); await pw.fill(process.env.PANEL_PASS || ''); await pw.press('Enter'); await p.waitForTimeout(2500);
    await p.evaluate(async (code) => {
      const me = await (await fetch('/api/me')).json();
      await fetch('/api/me/language', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': me.csrf }, body: JSON.stringify({ language: code }) });
    }, LANG);
    await p.reload(); await p.waitForTimeout(1500); // the account's language is read when the page loads
    for (const page of PAGES) {
      errs = [];
      await p.goto(BASE + '/#' + page); await p.waitForTimeout(1600);
      const info = await p.evaluate((LANG) => {
        const sw = document.documentElement.scrollWidth - innerWidth;
        const inner = [...document.querySelectorAll('main *')].filter((e) => { const cs = getComputedStyle(e); return /(auto|scroll)/.test(cs.overflowX) && e.scrollWidth > e.clientWidth + 2 && e.clientWidth > 50 }).slice(0, 2).map((e) => e.tagName + '.' + String(e.className).slice(0, 30));
        const english = LANG === 'en' ? 0 : [...document.querySelectorAll('main h1, main h2, main button, main label, .nav button')].filter((e) => /^[A-Za-z][a-z]+( [a-z]+)*$/.test(e.textContent.trim()) && !/^(Redis|Docker|Laravel application|Python|Whois|Zip)$/.test(e.textContent.trim())).map((e) => e.textContent.trim()).slice(0, 3);
        return { english, sw, inner, bad: (document.body.innerText.match(/\bNaN\b|\bundefined\b|\[object Object\]/g) || []).slice(0, 2), login: !!document.querySelector('.login input[type=password]') };
      }, LANG);
      const name = size + '-' + scheme + '-' + page.replace('/', '-') + (LANG === 'en' ? '' : '-' + LANG);
      await p.screenshot({ path: OUT + name + '.png', fullPage: true }); n++;
      const f = [];
      if (info.login) f.push('not signed in');
      if (info.sw > 1) f.push('page sideways ' + info.sw);
      if (info.inner.length) f.push('inside sideways ' + info.inner.join(','));
      if (info.bad.length) f.push('text ' + info.bad.join(','));
      if (info.english && info.english.length) f.push('still English: ' + info.english.join(', '));
      if (errs.length) f.push(errs.slice(0, 2).join(' / '));
      if (f.length) flags.push(name + ': ' + f.join('; '));
    }
    await ctx.close();
  }
  fs.writeFileSync(OUT + 'flags' + (LANG === 'en' ? '' : '-' + LANG) + '.txt', flags.join('\n') + '\n');
  console.log(n + ' screens, ' + flags.length + ' flags'); console.log(flags.join('\n'));
  await b.close();
})();
