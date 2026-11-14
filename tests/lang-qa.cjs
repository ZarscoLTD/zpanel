// Language switch check: the user menu picker turns the panel into Hindi and back to English, and the choice is kept
// on the account (a reload stays in Hindi). Same server as ui-qa.cjs:  PANEL_PASS='...' node tests/lang-qa.cjs
const PW = process.env.PW || 'playwright-core';
const { chromium } = require(PW);
const BASE = process.env.PANEL_BASE || 'http://127.0.0.1:8085';

(async () => {
  const b = await chromium.launch({ executablePath: '/usr/bin/google-chrome' });
  const p = await (await b.newContext({ viewport: { width: 1280, height: 860 } })).newPage();
  const errs = [];
  p.on('pageerror', (e) => errs.push(String(e)));
  const fail = (m) => { console.log('FAIL ' + m); process.exitCode = 1 };
  const navText = () => p.locator('.nav').innerText();
  await p.goto(BASE + '/'); await p.waitForTimeout(1200);
  if (!(await p.locator('.login .lang-pick select').count())) fail('no language picker on the sign-in page');
  await p.locator('input').first().fill(process.env.PANEL_USER || 'admin');
  const pw = p.locator('input[type=password]'); await pw.fill(process.env.PANEL_PASS || ''); await pw.press('Enter');
  await p.waitForTimeout(2000);
  // start from English
  await p.click('.user-btn'); await p.selectOption('.dropdown .lang-pick select', 'en'); await p.waitForTimeout(800)
  if (!(await navText()).includes('Websites')) fail('English menu expected')
  await p.selectOption('.dropdown .lang-pick select', 'hi'); await p.waitForTimeout(1200)
  const hi = await navText()
  if (!hi.includes('वेबसाइटें') || hi.includes('Websites')) fail('Hindi menu expected, got: ' + hi.slice(0, 80))
  if (await p.evaluate(() => document.documentElement.lang) !== 'hi') fail('html lang should be hi')
  await p.reload(); await p.waitForTimeout(2500)
  if (!(await navText()).includes('वेबसाइटें')) fail('Hindi should be kept after a reload')
  await p.click('.user-btn'); await p.selectOption('.dropdown .lang-pick select', 'en'); await p.waitForTimeout(1200)
  const en = await navText()
  if (!en.includes('Websites') || en.includes('वेबसाइटें')) fail('back to English expected, got: ' + en.slice(0, 80))
  if (errs.length) fail('JS errors: ' + errs.join(' / '))
  if (!process.exitCode) console.log('language switch OK')
  await b.close()
})()
