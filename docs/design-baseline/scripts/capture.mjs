// Visual + token baseline of the LEGACY site (anonymous, GET-only).
// Runs inside mcr.microsoft.com/playwright:v1.60.0-noble with --network host.
import { chromium } from 'playwright';
import fs from 'node:fs';

const BASE = 'http://localhost:9980';
const OUT = '/out';
const DESKTOP = { width: 1280, height: 900 };
const MOBILE = { width: 390, height: 844 };

const PAGES = [
  ['01-home', '/#/'],
  ['02-events', '/#/events'],
  ['03-login', '/#/login'],
  ['04-forgot-password', '/#/forgot-password'],
  ['05-register', '/#/register'],
  ['06-privacy-policy', '/#/privacy-policy'],
  ['07-members', '/#/members'],
  ['08-cars', '/#/cars'],
  ['09-gallery', '/#/gallery'],
  ['10-event-2026-info', '/#/SoutheastSharkfest2026/e36'],
  ['11-event-2026-judging', '/#/SoutheastSharkfest2026/e36/judging'],
  ['12-event-2026-schedule', '/#/SoutheastSharkfest2026/e36/schedule'],
  ['13-event-2026-directions', '/#/SoutheastSharkfest2026/e36/directions'],
  ['14-event-2026-whos-coming', '/#/SoutheastSharkfest2026/e36/members'],
  ['15-event-2026-sponsors', '/#/SoutheastSharkfest2026/e36/sponsors'],
  ['16-event-2026-cars', '/#/SoutheastSharkfest2026/e36/cars'],
  ['17-event-2025-past', '/#/SoutheastSharkfest2025/e35'],
  ['18-member-profile', '/#/ShawnB/u44'],
  ['19-car-profile', '/#/car/78'],
];

const PROPS = ['font-family', 'font-size', 'font-weight', 'line-height', 'letter-spacing', 'text-transform',
  'color', 'background-color', 'background-image', 'border-top-width', 'border-top-style', 'border-top-color',
  'border-bottom-width', 'border-bottom-color', 'border-left-width', 'border-radius', 'padding-top', 'padding-right',
  'padding-bottom', 'padding-left', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left', 'height',
  'min-height', 'width', 'max-width', 'box-shadow', 'text-decoration-line', 'outline-style', 'outline-width',
  'outline-color', 'opacity', 'display', 'transition'];

const NAV_PROBE = '<nav class="navbar navbar-default"><div class="container-fluid"><div class="navbar-header"><a class="navbar-brand" href="#/">Brand</a></div></div></nav>';
const TABLE = '<table class="table"><thead><tr><th>Head</th></tr></thead><tbody><tr><td>Cell</td></tr></tbody></table>';
const PAG = '<ul class="pagination"><li><a href="#/x">1</a></li></ul>';
const PAG_ACTIVE = '<ul class="pagination"><li class="active"><span>2</span></li></ul>';
const TABS = '<ul class="nav nav-tabs"><li><a href="#/x">Tab</a></li></ul>';
const TABS_ACTIVE = '<ul class="nav nav-tabs"><li class="active"><a href="#/x">Tab</a></li></ul>';
const ACC = '<div class="panel-group"><div class="panel panel-default"><div class="panel-heading"><h4 class="panel-title"><a href="#/x">Accordion</a></h4></div><div class="panel-collapse"><div class="panel-body">x</div></div></div></div>';
const MODAL = '<div class="modal-content"><div class="modal-header"><h4 class="modal-title">Modal</h4></div><div class="modal-body">Body</div><div class="modal-footer"><button class="btn btn-default" type="button">Close</button></div></div>';
const PANEL = '<div class="panel panel-default"><div class="panel-heading">Heading</div><div class="panel-body">Body text</div></div>';

const ITEMS = [
  { n: 'h1', sel: 'h1', html: '<h1>Heading One</h1>' },
  { n: 'h2', sel: 'h2', html: '<h2>Heading Two</h2>' },
  { n: 'h3', sel: 'h3', html: '<h3>Heading Three</h3>' },
  { n: 'h4', sel: 'h4', html: '<h4>Heading Four</h4>' },
  { n: 'link', sel: 'p a[href], main a[href], .container a[href]', html: '<p><a href="#/x">Inline link</a></p>', hover: true },
  { n: 'navbar', sel: '.navbar', html: NAV_PROBE },
  { n: 'navbar-brand', sel: '.navbar-brand', html: NAV_PROBE, tokSel: '.navbar-brand', hover: true },
  { n: 'nav-link', sel: '.navbar-nav > li > a', html: '<nav class="navbar navbar-default"><ul class="nav navbar-nav"><li><a href="#/x">Link</a></li></ul></nav>', tokSel: '.navbar-nav a', hover: true },
  { n: 'nav-link-active', sel: '.navbar-nav > li.active > a', html: '<nav class="navbar navbar-default"><ul class="nav navbar-nav"><li class="active"><a href="#/x">Active</a></li></ul></nav>', tokSel: '.navbar-nav a', hover: true },
  { n: 'dropdown-menu', sel: '.dropdown-menu', html: '<ul class="dropdown-menu" style="display:block"><li><a href="#/x">Item</a></li></ul>' },
  { n: 'dropdown-item', sel: '.dropdown-menu > li > a', html: '<ul class="dropdown-menu" style="display:block"><li><a href="#/x">Item</a></li></ul>', tokSel: '.dropdown-menu a', hover: true },
  { n: 'btn-primary', sel: '.btn-primary', html: '<button class="btn btn-primary" type="button">Primary</button>', hover: true, focus: true },
  { n: 'btn-default', sel: '.btn-default', html: '<button class="btn btn-default" type="button">Default</button>', hover: true, focus: true },
  { n: 'btn-secondary', sel: '.btn-secondary', html: '<button class="btn btn-secondary" type="button">Secondary</button>', hover: true, focus: true },
  { n: 'btn-danger', sel: '.btn-danger', html: '<button class="btn btn-danger" type="button">Danger</button>', hover: true, focus: true },
  { n: 'btn-success', sel: '.btn-success', html: '<button class="btn btn-success" type="button">Success</button>', hover: true, focus: true },
  { n: 'btn-info', sel: '.btn-info', html: '<button class="btn btn-info" type="button">Info</button>', hover: true, focus: true },
  { n: 'btn-warning', sel: '.btn-warning', html: '<button class="btn btn-warning" type="button">Warning</button>', hover: true, focus: true },
  { n: 'btn-link', sel: '.btn-link', html: '<button class="btn btn-link" type="button">Link button</button>', hover: true },
  { n: 'input-text', sel: 'input.form-control[type=text], input[type=text]', html: '<input class="form-control" type="text" placeholder="Placeholder">', focus: true },
  { n: 'input-email', sel: 'input.form-control[type=email], input[type=email]', html: '<input class="form-control" type="email">', focus: true },
  { n: 'input-password', sel: 'input[type=password]', html: '<input class="form-control" type="password">', focus: true },
  { n: 'select', sel: 'select.form-control, select', html: '<select class="form-control"><option>One</option></select>', focus: true },
  { n: 'textarea', sel: 'textarea', html: '<textarea class="form-control"></textarea>', focus: true },
  { n: 'label', sel: 'label', html: '<label>Label</label>' },
  { n: 'alert-success', sel: '.alert-success', html: '<div class="alert alert-success">Success text <a href="#/x">link</a></div>' },
  { n: 'alert-danger', sel: '.alert-danger', html: '<div class="alert alert-danger">Danger text <a href="#/x">link</a></div>' },
  { n: 'alert-info', sel: '.alert-info', html: '<div class="alert alert-info">Info text <a href="#/x">link</a></div>' },
  { n: 'alert-warning', sel: '.alert-warning', html: '<div class="alert alert-warning">Warning text <a href="#/x">link</a></div>' },
  { n: 'panel', sel: '.panel', html: PANEL, tokSel: '.panel' },
  { n: 'panel-heading', sel: '.panel-heading', html: PANEL, tokSel: '.panel-heading' },
  { n: 'panel-body', sel: '.panel-body', html: PANEL, tokSel: '.panel-body' },
  { n: 'card', sel: '.card', html: '<div class="card"><div class="card-body">Body</div></div>' },
  { n: 'well', sel: '.well', html: '<div class="well">Well</div>' },
  { n: 'thumbnail', sel: '.thumbnail', html: '<div class="thumbnail"><div class="caption"><h4>Caption</h4></div></div>' },
  { n: 'table', sel: 'table', html: TABLE },
  { n: 'table-th', sel: 'table th', html: TABLE, tokSel: 'th' },
  { n: 'table-td', sel: 'table td', html: TABLE, tokSel: 'td' },
  { n: 'pagination-link', sel: '.pagination > li > a', html: PAG, tokSel: '.pagination a', hover: true },
  { n: 'pagination-active', sel: '.pagination > li.active > span, .pagination > li.active > a', html: PAG_ACTIVE, tokSel: '.pagination li.active > *' },
  { n: 'tab-link', sel: '.nav-tabs > li > a', html: TABS, tokSel: '.nav-tabs a', hover: true },
  { n: 'tab-active', sel: '.nav-tabs > li.active > a', html: TABS_ACTIVE, tokSel: '.nav-tabs li.active a', hover: true },
  { n: 'accordion-heading', sel: '.panel-group .panel-heading', html: ACC, tokSel: '.panel-heading', hover: true },
  { n: 'modal-content', sel: '.modal-content', html: MODAL, tokSel: '.modal-content' },
  { n: 'footer', sel: 'footer', html: '<footer>Footer text</footer>' },
  { n: 'footer-link', sel: 'footer a', html: '<footer><a href="#/x">Footer link</a></footer>', tokSel: 'footer a', hover: true },
  { n: 'container', sel: '.container', html: '<div class="container">Container</div>' },
  { n: 'hero', sel: '.jumbotron, [class*="hero"], [class*="banner"]', html: null },
];

const TOKEN_PAGES = ['/#/', '/#/events', '/#/login', '/#/register', '/#/forgot-password', '/#/members', '/#/cars',
  '/#/gallery', '/#/SoutheastSharkfest2026/e36', '/#/SoutheastSharkfest2026/e36/sponsors', '/#/car/78',
  '/#/ShawnB/u44', '/#/privacy-policy'];

async function settle(page) {
  await page.waitForLoadState('networkidle', { timeout: 15000 }).catch(() => {});
  await page.waitForFunction(() => {
    const s = document.querySelector('#loading');
    return !s || getComputedStyle(s).display === 'none' || s.offsetParent === null;
  }, null, { timeout: 20000 }).catch(() => {});
  await page.waitForTimeout(1000);
}

async function open(page, url) {
  await page.goto(BASE + url, { waitUntil: 'domcontentloaded', timeout: 30000 });
  await settle(page);
}

async function pageCheck(page, url) {
  return page.evaluate((url) => {
    const vis = (e) => { const r = e.getBoundingClientRect(); const c = getComputedStyle(e); return r.width > 0 && r.height > 0 && c.visibility !== 'hidden' && c.display !== 'none'; };
    const errs = [...document.querySelectorAll('body *')]
      .filter(e => [...e.childNodes].some(n => n.nodeType === 3 && /Something went wrong/i.test(n.textContent)))
      .map(e => ({ visible: vis(e), tag: e.tagName.toLowerCase(), cls: String(e.className).slice(0, 60) }));
    const heads = [...document.querySelectorAll('h1,h2')].filter(vis).map(e => e.innerText.trim().slice(0, 80)).slice(0, 4);
    return { url, title: document.title, visibleHeadings: heads, errorTextNodes: errs, bodyChars: document.body.innerText.length };
  }, url);
}

// ---------- token helpers (run in page) ----------
const findReal = (sel) => {
  document.querySelectorAll('[data-tok]').forEach(e => e.removeAttribute('data-tok'));
  document.getElementById('__probe')?.remove();
  const el = [...document.querySelectorAll(sel)].find(e => {
    const r = e.getBoundingClientRect(); const c = getComputedStyle(e);
    return r.width > 0 && r.height > 0 && c.visibility !== 'hidden' && c.display !== 'none';
  });
  if (!el) return false;
  el.setAttribute('data-tok', 't');
  return true;
};

const injectProbe = ({ html, tokSel }) => {
  document.querySelectorAll('[data-tok]').forEach(e => e.removeAttribute('data-tok'));
  document.getElementById('__probe')?.remove();
  const d = document.createElement('div');
  d.id = '__probe';
  d.style.cssText = 'position:fixed;top:90px;left:20px;width:640px;background:#fff;padding:20px;z-index:2147483000;';
  d.innerHTML = html;
  document.body.appendChild(d);
  const el = tokSel ? d.querySelector(tokSel) : d.firstElementChild;
  if (!el) return false;
  el.setAttribute('data-tok', 't');
  return true;
};

const clearProbe = () => {
  document.querySelectorAll('[data-tok]').forEach(e => e.removeAttribute('data-tok'));
  document.getElementById('__probe')?.remove();
};

const readTok = (props) => {
  const el = document.querySelector('[data-tok="t"]');
  if (!el) return null;
  const c = getComputedStyle(el); const r = el.getBoundingClientRect();
  const o = {};
  for (const p of props) o[p] = c.getPropertyValue(p);
  o._rect = { w: Math.round(r.width), h: Math.round(r.height) };
  o._tag = el.tagName.toLowerCase() + (typeof el.className === 'string' && el.className.trim() ? '.' + el.className.trim().split(/\s+/).join('.') : '');
  return o;
};

function diff(base, other) {
  const d = {};
  for (const k of Object.keys(other)) {
    if (k.startsWith('_')) continue;
    if (other[k] !== base[k]) d[k] = { from: base[k], to: other[k] };
  }
  return d;
}

async function tokenForItem(page, item, sourceLabel) {
  const tokSel = item.tokSel;
  const out = { source: sourceLabel };
  const base = await page.evaluate(readTok, PROPS);
  out.base = base;
  out.base._note = undefined;
  if (item.hover) {
    try {
      await page.locator('[data-tok="t"]').first().hover({ timeout: 3000 });
      await page.waitForTimeout(450);
      const h = await page.evaluate(readTok, PROPS);
      out.hover = diff(base, h);
    } catch (e) { out.hover = { error: e.message.split('\n')[0] }; }
    await page.mouse.move(0, 0);
    await page.waitForTimeout(450);
  }
  if (item.focus) {
    try {
      await page.locator('[data-tok="t"]').first().focus({ timeout: 3000 });
      await page.waitForTimeout(300);
      const f = await page.evaluate(readTok, PROPS);
      out.focus = diff(base, f);
    } catch (e) { out.focus = { error: e.message.split('\n')[0] }; }
    await page.evaluate(() => document.activeElement && document.activeElement.blur && document.activeElement.blur());
  }
  return out;
}

const GLOBAL_FN = (props) => {
  const pick = (sel) => [...document.querySelectorAll(sel)].find(x => { const r = x.getBoundingClientRect(); return r.width > 0 && r.height > 0; }) || null;
  const sty = (e) => { if (!e) return null; const c = getComputedStyle(e); const o = {}; for (const p of props) o[p] = c.getPropertyValue(p); const r = e.getBoundingClientRect(); o._rect = { w: Math.round(r.width), h: Math.round(r.height) }; return o; };
  const mq = []; const fontFaces = []; const sheets = [];
  for (const ss of document.styleSheets) {
    const href = ss.href || 'inline';
    let rules;
    try { rules = ss.cssRules; } catch (e) { sheets.push({ href, accessible: false }); continue; }
    sheets.push({ href, accessible: true, ruleCount: rules.length });
    const walk = (list) => {
      for (const r of list) {
        if (r instanceof CSSMediaRule) { mq.push({ href, media: r.conditionText || r.media.mediaText }); walk(r.cssRules); }
        else if (r instanceof CSSFontFaceRule) fontFaces.push({ href, family: r.style.getPropertyValue('font-family'), weight: r.style.getPropertyValue('font-weight'), style: r.style.getPropertyValue('font-style'), src: r.style.getPropertyValue('src').slice(0, 400) });
        else if (r.cssRules && !(r instanceof CSSMediaRule)) { try { walk(r.cssRules); } catch (e) { } }
      }
    };
    walk(rules);
  }
  const mqDistinct = [...new Set(mq.map(m => m.media))];
  const widths = new Set();
  for (const m of mqDistinct) for (const mm of m.matchAll(/(min|max)-width:\s*([\d.]+)(px|em|rem)/g)) widths.add(mm[1] + '-width ' + mm[2] + mm[3]);
  const visible = (e) => { const r = e.getBoundingClientRect(); return r.width > 0 && r.height > 0; };
  const images = [...document.images].filter(i => i.getBoundingClientRect().width >= 40).slice(0, 40).map(i => {
    const r = i.getBoundingClientRect();
    return { src: i.currentSrc || i.src, alt: i.alt, natural: [i.naturalWidth, i.naturalHeight], rendered: [Math.round(r.width), Math.round(r.height)], inNavbar: !!i.closest('.navbar'), inFooter: !!i.closest('footer') };
  });
  const bgImages = [...document.querySelectorAll('body *')].map(e => {
    const bi = getComputedStyle(e).backgroundImage; const r = e.getBoundingClientRect();
    return bi && bi !== 'none' && r.width >= 600 ? { cls: String(e.className).slice(0, 80), bg: bi.slice(0, 200), rendered: [Math.round(r.width), Math.round(r.height)], yFromTop: Math.round(r.top + scrollY) } : null;
  }).filter(Boolean).slice(0, 15);
  const navItems = [...new Set([...document.querySelectorAll('.navbar a')].filter(visible).map(a => a.innerText.trim().replace(/\s+/g, ' ') + ' -> ' + (a.getAttribute('href') || '')))].slice(0, 40);
  const footer = pick('footer');
  return {
    body: sty(document.body),
    html: sty(document.documentElement),
    navbar: sty(pick('.navbar')),
    navbarBrand: sty(pick('.navbar-brand')),
    footer: sty(footer),
    footerText: footer ? footer.innerText.trim().replace(/\n+/g, ' | ').slice(0, 1200) : null,
    footerLinks: footer ? [...footer.querySelectorAll('a')].filter(visible).map(a => a.innerText.trim() + ' -> ' + a.getAttribute('href')).slice(0, 40) : [],
    navItems,
    stylesheets: sheets,
    mediaQueries: mqDistinct,
    mediaWidths: [...widths].sort(),
    fontFaceRules: fontFaces,
    fontsLoaded: [...document.fonts].map(f => ({ family: f.family, weight: f.weight, style: f.style, status: f.status })),
    images,
    backgroundImages: bgImages,
    bootstrapHints: { btnDefault: !!document.querySelector('.btn-default'), panelHeading: !!document.querySelector('.panel-heading'), navbarNav: !!document.querySelector('.navbar-nav') },
  };
};

async function captureTokens(browser) {
  const ctx = await browser.newContext({ viewport: DESKTOP });
  const page = await ctx.newPage();
  const tokens = { _meta: { base: BASE, viewport: '1280x900', capturedAt: new Date().toISOString(), notes: 'components: source=page:<route> means a real visible element was measured; source=probe means a temporary injected sample (position:fixed overlay, removed after measurement). hover/focus = computed-style diff vs base.' }, components: {} };
  await open(page, '/#/');
  tokens.global = await page.evaluate(GLOBAL_FN, PROPS);

  const pending = new Set(ITEMS.map(i => i.n));
  const byName = Object.fromEntries(ITEMS.map(i => [i.n, i]));
  for (const url of TOKEN_PAGES) {
    if (pending.size === 0) break;
    try {
      await open(page, url);
      for (const n of [...pending]) {
        const item = byName[n];
        if (!item.sel) continue;
        const ok = await page.evaluate(findReal, item.sel).catch(() => false);
        if (!ok) continue;
        tokens.components[n] = await tokenForItem(page, item, 'page:' + url);
        pending.delete(n);
      }
      await page.evaluate(clearProbe);
    } catch (e) { console.error('token page failed', url, e.message.split('\n')[0]); }
  }
  // probes for anything not present on any page
  await open(page, '/#/');
  for (const n of pending) {
    const item = byName[n];
    if (!item.html) { tokens.components[n] = { source: 'not found on any captured page' }; continue; }
    await page.evaluate(injectProbe, { html: item.html, tokSel: item.tokSel || null });
    tokens.components[n] = await tokenForItem(page, item, 'probe (' + (item.html.includes('display:block') ? 'display:block forced' : 'injected sample') + ')');
    await page.evaluate(clearProbe);
  }
  // A few inline checks: heading and body on a typical content page
  await ctx.close();
  return tokens;
}

async function main() {
  const browser = await chromium.launch();
  const results = { shots: [], failures: [], pageChecks: [] };

  for (const [vpName, vp] of [['desktop', DESKTOP], ['mobile', MOBILE]]) {
    const ctx = await browser.newContext({ viewport: vp, deviceScaleFactor: 1, isMobile: vpName === 'mobile', hasTouch: vpName === 'mobile' });
    const page = await ctx.newPage();
    for (const [name, url] of PAGES) {
      try {
        await open(page, url);
        await page.screenshot({ path: `${OUT}/shots/${vpName}/${name}.png`, fullPage: true, animations: 'disabled' });
        results.shots.push(`${vpName}/${name}.png`);
        if (vpName === 'desktop') results.pageChecks.push({ name, ...(await pageCheck(page, url)) });
      } catch (e) {
        results.failures.push(`${vpName}/${name} (${url}): ${e.message.split('\n')[0]}`);
      }
    }
    if (vpName === 'desktop') {
      try {
        await open(page, '/#/');
        const nav = page.locator('.navbar a[href="#events"]').first();
        await nav.hover({ timeout: 5000 });
        await page.waitForTimeout(500);
        const nb = await page.locator('.navbar').first().boundingBox().catch(() => null);
        const clip = nb ? { x: 0, y: 0, width: 1280, height: Math.min(900, Math.round(nb.y + nb.height + 12)) } : { x: 0, y: 0, width: 1280, height: 160 };
        await page.screenshot({ path: `${OUT}/shots/desktop/20-hover-nav-item.png`, clip, animations: 'disabled' });
        results.shots.push('desktop/20-hover-nav-item.png');
        await page.mouse.move(0, 0);
      } catch (e) { results.failures.push('hover nav: ' + e.message.split('\n')[0]); }
      try {
        await open(page, '/#/login');
        await page.locator('input[type=email]').first().focus({ timeout: 5000 });
        await page.waitForTimeout(400);
        await page.screenshot({ path: `${OUT}/shots/desktop/21-focus-login-input.png`, animations: 'disabled' });
        results.shots.push('desktop/21-focus-login-input.png');
        await page.locator('button[type=submit], input[type=submit]').first().focus({ timeout: 5000 });
        await page.waitForTimeout(400);
        await page.screenshot({ path: `${OUT}/shots/desktop/22-focus-login-button.png`, animations: 'disabled' });
        results.shots.push('desktop/22-focus-login-button.png');
      } catch (e) { results.failures.push('focus login: ' + e.message.split('\n')[0]); }
    } else {
      try {
        await open(page, '/#/');
        await page.locator('.navbar-toggle, .navbar-toggler, button[data-toggle="collapse"]').first().click({ timeout: 5000 });
        await page.waitForTimeout(900);
        await page.screenshot({ path: `${OUT}/shots/mobile/20-mobile-nav-open.png`, animations: 'disabled' });
        results.shots.push('mobile/20-mobile-nav-open.png');
      } catch (e) { results.failures.push('mobile nav: ' + e.message.split('\n')[0]); }
    }
    await ctx.close();
  }

  const tokens = await captureTokens(browser);
  tokens._meta.pageChecks = results.pageChecks;
  tokens._meta.failures = results.failures;
  tokens._meta.shotCount = results.shots.length;
  fs.writeFileSync(`${OUT}/tokens.json`, JSON.stringify(tokens, null, 2));
  fs.writeFileSync(`${OUT}/scripts/capture-result.json`, JSON.stringify({ shots: results.shots, failures: results.failures, pageChecks: results.pageChecks }, null, 2));
  console.log('shots', results.shots.length, 'failures', results.failures.length);
  console.log(results.failures.join('\n'));
  await browser.close();
}

main().catch(e => { console.error('FATAL', e); process.exit(1); });
