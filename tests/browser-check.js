// Headless browser check of the page against the mock Claude API (optional; needs Node and
// Playwright). tests/smoke.sh starts the servers; or run it yourself:
//   APP_URL=http://127.0.0.1:8092/ node tests/browser-check.js [screenshot-folder]
// It fails on any console error (except the one 503 it causes on purpose), and checks:
// a reply with product cards, the cart action,
// an order lookup, the WhatsApp tab, a handoff ticket in the staff inbox, the language
// switch, the busy error with its retry button, and the stop button.
const { chromium, devices } = require('playwright');

const APP = process.env.APP_URL || 'http://127.0.0.1:8092/';
const SHOTS = process.argv[2] || '';
let failed = 0;
const ok = (cond, label) => { console.log((cond ? '  ok   ' : '  FAIL ') + label); if (!cond) failed++; };

async function lastBot(page) {
  return page.locator('#log .msg.bot').last();
}
async function sendText(page, text) {
  await page.fill('#q', text);
  await page.click('#sendBtn');
  await waitIdle(page);
}
async function waitIdle(page) {
  // busy: the composer is disabled; the reply is final when no message is pending
  await page.waitForFunction(() => !document.querySelector('#q').disabled, null, { timeout: 15000 });
}

(async () => {
  const browser = await chromium.launch();
  const errors = [];
  const watch = (page) => {
    page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
    page.on('pageerror', (e) => errors.push(String(e)));
  };

  // ---- desktop, Arabic ----------------------------------------------------------------
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 860 }, deviceScaleFactor: SHOTS ? 2 : 1, locale: 'ar-SA' });
  const page = await ctx.newPage();
  watch(page);
  await page.goto(APP);
  await page.waitForSelector('#log .msg.bot .pc');
  await page.waitForFunction(() => document.querySelector('#dot').classList.contains('on'), null, { timeout: 5000 });
  ok(await page.locator('#dot.on').count() === 1, 'status dot is green after the health check');
  ok(await page.locator('#log .msg.bot').first().locator('.pc').count() === 3, 'welcome message shows 3 product cards');
  ok(await page.getAttribute('html', 'dir') === 'rtl', 'page starts in Arabic, right to left');

  await page.locator('#chips .chip').first().click();   // the gift starter
  await waitIdle(page);
  let bot = await lastBot(page);
  ok(await bot.locator('.pc').count() === 3, 'gift question gets a reply with 3 product cards');
  ok((await bot.locator('.step').count()) === 1 && /بحث في المنتجات/.test(await bot.locator('.step').innerText()), 'the search step is shown');
  ok(!/\[\[/.test(await bot.locator('.txt').innerText()), 'markers are hidden from the reply text');
  ok(await page.locator('#chips .chip').count() === 3, 'quick replies from the reply are shown');

  await page.locator('#chips .chip').first().click();   // "add Taif rose"
  await waitIdle(page);
  ok(await page.locator('#cartN').innerText() === '1', 'add-to-cart action updates the cart badge');

  await sendText(page, 'وين طلبي رقم 1042؟');
  bot = await lastBot(page);
  ok(/1042/.test(await bot.innerText()) && /تم الشحن/.test(await bot.locator('.step').innerText()), 'order 1042 lookup is answered with its status step');
  if (SHOTS) {
    await page.locator('#log .msg.bot').nth(1).scrollIntoViewIfNeeded();
    await page.screenshot({ path: SHOTS + '/desktop.png' });
  }

  ok(errors.length === 0, 'no console errors in the normal flow' + (errors.length ? ': ' + errors.join(' | ') : ''));

  // busy error: explained, with a retry button. Chrome logs the deliberate 503 as a
  // "Failed to load resource" line; that one expected line is not counted.
  const mark = errors.length;
  await sendText(page, 'MOCK_529');
  bot = await lastBot(page);
  ok(/مشغولة/.test(await bot.innerText()) && await bot.locator('.retry').count() === 1, 'busy error is explained and offers a retry button');
  for (let i = errors.length - 1; i >= mark; i--) {
    if (/Failed to load resource.*\b503\b/.test(errors[i])) errors.splice(i, 1);
  }

  // stop button while the server is still thinking
  await page.fill('#q', 'MOCK_SLOW');
  await page.click('#sendBtn');
  await page.waitForTimeout(400);
  ok(await page.getAttribute('#sendBtn', 'aria-label') === 'إيقاف', 'send button turns into a stop button while waiting');
  const before = await page.locator('#log .msg.bot').count();
  await page.click('#sendBtn');
  await waitIdle(page);
  ok(await page.locator('#log .msg.bot').count() === before - 1, 'stop removes the pending reply and frees the composer');

  // WhatsApp tab: its own chat, separate from the website chat
  await page.fill('#q', 'draft for the website');
  await page.click('#tabWa');
  ok(await page.locator('#chatView.wamode .wahead').isVisible() && await page.locator('.wanote').isVisible(), 'WhatsApp tab shows its header and preview note');
  ok(await page.locator('#log .msg.me').count() === 0 && await page.locator('#log .msg.bot').count() === 1, 'WhatsApp chat starts fresh: no website messages, only its welcome');
  ok(await page.locator('#cartN').isHidden(), 'WhatsApp chat has its own empty cart');
  ok(await page.inputValue('#q') === '', 'the website draft does not follow into WhatsApp');
  await sendText(page, 'أبغى أكلم موظف');
  bot = await lastBot(page);
  ok(await bot.locator('.ho').count() === 1 && /T-1001/.test(await bot.innerText()), 'handoff shows the ticket card with T-1001');
  ok(/1/.test(await page.locator('#tabStaff .cnt').innerText()), 'staff tab shows one open ticket');
  await page.click('#tabStaff');
  const ticket = page.locator('#tickets .tk').first();
  ok(await ticket.count() === 1 && /T-1001/.test(await ticket.innerText()) && /واتساب/.test(await ticket.innerText()), 'ticket T-1001 from WhatsApp is in the staff inbox');
  ok(/العميل يبغى يكلم موظف/.test(await ticket.innerText()), 'ticket carries the summary for staff');

  // back on the website: its chat, cart and draft are still there, without the WhatsApp messages
  await page.click('#tabChat');
  const webText = await page.locator('#log').innerText();
  ok(await page.locator('#log .msg.me').count() === 5 && !/أبغى أكلم موظف/.test(webText), 'website chat is unchanged and has none of the WhatsApp messages');
  ok(await page.locator('#cartN').innerText() === '1', 'website cart still holds its item');
  ok(await page.inputValue('#q') === 'draft for the website', 'website draft is restored');
  await page.fill('#q', '');

  // both chats can wait for a reply at the same time
  await page.fill('#q', 'MOCK_SLOW');
  await page.click('#sendBtn');
  await page.click('#tabWa');
  ok(!(await page.isDisabled('#q')), 'while the website waits, the WhatsApp composer stays free');
  await sendText(page, 'Do you ship to Turkey?');
  ok(/Turkey/.test(await (await lastBot(page)).innerText()), 'WhatsApp gets its own reply meanwhile');
  await page.click('#tabChat');
  await waitIdle(page);
  ok(/Happy to help/.test(await (await lastBot(page)).innerText()) && !/Turkey/.test(await page.locator('#log').innerText()), 'the slow website reply lands in the website chat only');

  // language switch
  await page.click('#langBtn');
  ok(await page.getAttribute('html', 'dir') === 'ltr' && await page.getAttribute('html', 'lang') === 'en', 'language switch flips the page to English, left to right');
  ok(await page.locator('#name').innerText() === 'Ghaim Perfumes', 'store name is translated');
  await sendText(page, 'A gift perfume for my wife, budget 300 SAR');
  bot = await lastBot(page);
  ok(/gift picks/.test(await bot.innerText()) && await bot.locator('.pc').count() === 3, 'English question gets an English reply with cards');
  await page.click('#langBtn');
  await ctx.close();

  // ---- phone ----------------------------------------------------------------------------
  const phone = await browser.newContext({ ...devices['iPhone 13'], locale: 'ar-SA' });
  const p2 = await phone.newPage();
  watch(p2);
  await p2.goto(APP);
  await p2.waitForSelector('#log .msg.bot .pc');
  await p2.locator('#chips .chip').first().click();
  await waitIdle(p2);
  ok(await (await lastBot(p2)).locator('.pc').count() === 3, 'phone: reply with product cards');
  const overflow = await p2.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
  ok(!overflow, 'phone: no horizontal page scroll');
  if (SHOTS) {
    await p2.screenshot({ path: SHOTS + '/phone.png' });
    await p2.click('#tabWa');
    await sendText(p2, 'وين طلبي رقم 1042؟');
    await p2.screenshot({ path: SHOTS + '/phone-whatsapp.png' });
  }
  await phone.close();
  await browser.close();

  ok(errors.length === 0, 'no console errors' + (errors.length ? ': ' + errors.join(' | ') : ''));
  console.log(failed ? `browser check: ${failed} failed` : 'browser check: all passed');
  process.exit(failed ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
