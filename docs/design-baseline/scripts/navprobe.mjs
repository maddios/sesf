import { chromium } from 'playwright';
const b = await chromium.launch();
const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true });
const p = await ctx.newPage();
await p.goto('http://localhost:9980/#/', { waitUntil: 'domcontentloaded' });
await p.waitForTimeout(4000);
console.log(JSON.stringify(await p.evaluate(() => [...document.querySelectorAll('nav button, nav a, .navbar button, header button, [class*=toggle], [class*=hamburger], [aria-label*=enu]')].map(e => ({ tag: e.tagName, cls: String(e.className).slice(0, 80), aria: e.getAttribute('aria-label'), vis: e.getBoundingClientRect().width > 0, txt: e.innerText.trim().slice(0, 30) })).slice(0, 20)), null, 1));
await b.close();
