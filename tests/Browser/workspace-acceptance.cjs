'use strict';

const assert = require('node:assert/strict');
const { chromium } = require('playwright');

const base = 'http://newsroom-workspace-browser-app:8087';
const rootId = '1'.repeat(64);
const owner = 'a'.repeat(64);
const key = `event-workspace-state:v1:${owner}:reading-nook`;
const failures = [];

async function check(name, run) {
  if (process.env.WORKSPACE_CASE && !name.includes(process.env.WORKSPACE_CASE)) return;
  try {
    await run();
    console.log(`PASS ${name}`);
  } catch (error) {
    failures.push(name);
    console.error(`FAIL ${name}: ${error.message}`);
  }
}

async function newPage(browser, viewport) {
  const context = await browser.newContext({ viewport });
  // Mercure is deliberately absent in this closed synthetic network.
  await context.route('**/.well-known/mercure**', route => route.abort());
  const page = await context.newPage();
  page.setDefaultTimeout(30000);
  page.on('response', async response => {
    if (response.url().includes('stimulus') && response.url().includes('controllers')) {
      const text = await response.text().catch(() => '');
      console.log('REGISTRY:', [...text.matchAll(/"([^"]*workspace[^"]*)": controller_\d+/g)].map(match => match[1]).join(', '));
    }
  });
  page.on('pageerror', error => console.error(`PAGE ERROR: ${error.message}`));
  page.on('console', message => {
    if (message.type() === 'error' && !message.text().includes('ERR_FAILED')) console.error(`BROWSER ERROR: ${message.text()}`);
  });
  return { context, page };
}

const disclosure = (page, id) => page.locator(`details[data-event-workspace-disclosure="${id}"]`);
const rootDisclosure = page => disclosure(page, `nook-toc-${rootId}`);
const frame = details => details.locator(':scope > turbo-frame');
const rows = details => frame(details).locator(':scope > ol > li');

async function loadNook(page) {
  const response = await page.goto(`${base}/reading-nook`, { waitUntil: 'networkidle' });
  assert.equal(response.status(), 200);
  assert.equal(await page.locator('.reading-nook-page').count(), 1);
}

async function runViewport(browser, label, viewport) {
  const { context, page } = await newPage(browser, viewport);
  const requests = [];
  const discoveryRequests = [];
  page.on('request', request => {
    if (/\/reading-nook\/publication-indexes\/.*\/(?:toc|nested-toc)/.test(request.url())) requests.push(request.url());
    if (request.url().includes('/discovery')) discoveryRequests.push(request.method());
  });
  try {
    await check(`${label}: native lazy expansion, shared occurrences, cycle and partial data`, async () => {
      await loadNook(page);
      const root = rootDisclosure(page);
      assert.equal(await root.count(), 1);
      assert.equal(requests.length, 0, 'collapsed TOCs fetched before disclosure');
      if (label === 'desktop') {
        let focused = false;
        for (let i = 0; i < 150; ++i) {
          await page.keyboard.press('Tab');
          focused = await root.locator(':scope > summary').evaluate(element => element === document.activeElement);
          if (focused) break;
        }
        assert.ok(focused, 'root summary was not reachable by Tab');
        await page.keyboard.press('Enter');
        assert.equal(await root.locator(':scope > summary').evaluate(element => element === document.activeElement), true);
      } else {
        await root.locator(':scope > summary').click();
      }
      await frame(root).scrollIntoViewIfNeeded();
      await rows(root).first().waitFor();
      assert.equal(requests.length, 1, 'opening root must fetch only its fragment');
      const rootRows = rows(root);
      assert.equal(await rootRows.count(), 7);
      const first = rootRows.nth(0).locator(':scope > div > details');
      const second = rootRows.nth(1).locator(':scope > div > details');
      assert.notEqual(await first.getAttribute('data-event-workspace-disclosure'), await second.getAttribute('data-event-workspace-disclosure'));
      assert.match(await rootRows.nth(2).innerText(), /Restricted or unavailable index/);
      assert.equal(await rootRows.nth(2).locator('button').count(), 1);
      assert.match(await rootRows.nth(3).innerText(), /Restricted or unavailable index/);
      assert.doesNotMatch(await rootRows.nth(3).innerText(), /NEVER EXPOSE/);
      assert.match(await rootRows.nth(5).innerText(), /exact revision is not stored locally/);
      assert.equal(await rootRows.nth(5).locator('button').count(), 0);

      if (label === 'desktop') {
        await first.locator(':scope > summary').focus();
        await page.keyboard.press('Space');
        assert.equal(await first.locator(':scope > summary').evaluate(element => element === document.activeElement), true);
      } else {
        await first.locator(':scope > summary').click();
      }
      await frame(first).scrollIntoViewIfNeeded();
      await rows(first).first().waitFor();
      assert.equal(requests.length, 2);
      assert.equal(await second.evaluate(node => node.open), false);
      assert.match(await rows(first).first().innerText(), /Cycle detected/);
      assert.equal(await rows(first).first().locator('details').count(), 0);
      const leaf = rows(first).nth(1).locator('details');
      await leaf.locator(':scope > summary').click();
      await frame(leaf).scrollIntoViewIfNeeded();
      await frame(leaf).locator('nav').waitFor();
      assert.equal(requests.length, 3);
      await second.locator(':scope > summary').click();
      await frame(second).scrollIntoViewIfNeeded();
      await rows(second).first().waitFor();
      assert.equal(requests.length, 4);
      assert.equal(await first.evaluate(node => node.open), true);
      assert.match(await rows(second).first().innerText(), /Cycle detected/);

      const blocked = await page.request.get(`${base}/reading-nook/publication-indexes/${'5'.repeat(64)}/toc`);
      assert.equal(blocked.status(), 404, 'suppressed source should not be readable');
      assert.deepEqual(discoveryRequests, [], 'cold discovery must not start without a click');
      const post = page.waitForResponse(response => response.url().includes('/discovery') && response.request().method() === 'POST');
      await rootRows.nth(2).locator('button').click();
      const discoveryResponse = await post;
      assert.equal(discoveryResponse.status(), 200);
      assert.equal((await discoveryResponse.json()).status, 'loading');
      await rootRows.nth(2).locator('[role="status"]').getByText('Checking public relays…').waitFor();
      await page.waitForResponse(response => response.url().includes('/discovery') && response.request().method() === 'GET');
      assert.deepEqual(discoveryRequests.slice(0, 2), ['POST', 'GET']);

      let nested = rootRows.nth(6).locator(':scope > div > details');
      for (let depth = 1; depth <= 5; ++depth) {
        await nested.locator(':scope > summary').click();
        await frame(nested).scrollIntoViewIfNeeded();
        await rows(nested).first().waitFor();
        const next = rows(nested).first();
        if (depth === 5) {
          assert.match(await next.innerText(), /Maximum nested index depth reached/);
          assert.equal(await next.locator('details').count(), 0);
        } else {
          nested = next.locator(':scope > div > details');
        }
      }
    });

    await check(`${label}: saved disclosure reload and Back/Forward`, async () => {
      const root = rootDisclosure(page);
      await page.waitForFunction(k => JSON.parse(sessionStorage.getItem(k) || '{}').open?.includes(`nook-toc-${'1'.repeat(64)}`), key);
      const saved = JSON.parse(await page.evaluate(k => sessionStorage.getItem(k), key));
      assert.deepEqual(Object.keys(saved).sort(), ['open', 'scrollY']);
      assert.ok(saved.open.length <= 4);
      await page.reload({ waitUntil: 'networkidle' });
      await page.waitForFunction(id => document.querySelector(`details[data-event-workspace-disclosure="${id}"]`)?.open, `nook-toc-${rootId}`);
      await frame(rootDisclosure(page)).scrollIntoViewIfNeeded();
      await rows(rootDisclosure(page)).first().waitFor();
      assert.equal(await rows(rootDisclosure(page)).first().locator(':scope > div > details').evaluate(node => node.open), false,
        'not-yet-rendered nested disclosures are not restored');
      await page.locator('.reading-nook-tabs a[href*="section=reading_lists"]').click();
      await page.waitForURL(/section=reading_lists/);
      await page.goBack({ waitUntil: 'networkidle' });
      await page.waitForURL(/\/reading-nook$/);
      assert.equal(await rootDisclosure(page).evaluate(node => node.open), true);
      await page.goForward({ waitUntil: 'networkidle' });
      await page.waitForURL(/section=reading_lists/);
    });

    await check(`${label}: viewport fits without horizontal page overflow`, async () => {
      const widths = await page.evaluate(() => [document.documentElement.scrollWidth, document.documentElement.clientWidth]);
      assert.ok(widths[0] <= widths[1] + 1, `document width ${widths[0]} exceeds viewport ${widths[1]}`);
    });
  } finally {
    await context.close();
  }
}

async function members(browser) {
  const { context, page } = await newPage(browser, { width: 1440, height: 900 });
  try {
    await check('1,000-member fixture: 20 real paginated fragments', async () => {
      await loadNook(page);
      const details = disclosure(page, `nook-members-${'e'.repeat(64)}`);
      await details.locator(':scope > summary').click();
      const list = frame(details).locator('ol.event-member-list');
      await list.waitFor();
      let total = 0;
      const identities = new Set();
      for (let current = 1; current <= 20; ++current) {
        await page.waitForFunction(({ id, start }) => document.querySelector(`#${id} ol`)?.start === start, {
          id: `nook-members-${'e'.repeat(64)}`, start: (current - 1) * 50 + 1,
        });
        const count = await list.locator(':scope > li').count();
        assert.equal(count, 50, `page ${current}`);
        total += count;
        for (const identity of await list.locator(':scope > li').allTextContents()) identities.add(identity.trim());
        if (current < 20) await frame(details).locator('a[rel="next"]').click();
      }
      assert.equal(total, 1000);
      assert.equal(identities.size, 1000, 'all 1,000 rendered members must be distinct');
      assert.equal(await frame(details).locator('a[rel="next"]').count(), 0);
      assert.equal(await frame(details).locator('a[rel="prev"]').count(), 1);
    });
  } finally {
    await context.close();
  }
}

async function concurrency(browser) {
  const { context, page } = await newPage(browser, { width: 1440, height: 1100 });
  const held = [];
  const started = [];
  let releaseAll = false;
  await context.route('**/reading-nook/publication-indexes/**/toc', async route => {
    const url = route.request().url();
    started.push(url);
    if (!releaseAll) await new Promise(resolve => held.push({ resolve, url }));
    await route.continue().catch(() => {});
  });
  try {
    await check('five simultaneous lazy fragments: max four requests, closed queue and reopen', async () => {
      await loadNook(page);
      // Compact the page so all five native lazy frames intersect the viewport.
      await page.addStyleTag({ content: 'html { zoom: .25 !important }' });
      const ids = ['6', '7', '8', '9', 'a'].map(c => `nook-toc-${c.repeat(64)}`);
      await page.evaluate(ids => ids.forEach(id => {
        const element = document.querySelector(`details[data-event-workspace-disclosure="${id}"]`);
        if (!element) throw new Error(`Missing ${id}`);
        element.open = true;
      }), ids);
      await page.waitForFunction(() => document.querySelectorAll('details[open] turbo-frame[loading="lazy"]').length >= 5);
      await page.waitForTimeout(2500);
      assert.equal(started.length, 4, `expected exactly 4 held requests; observed ${started.length}`);
      const queuedId = ids.find(id => !started.some(url => url.includes(id.substring('nook-toc-'.length))));
      assert.ok(queuedId, 'could not identify queued disclosure');
      await disclosure(page, queuedId).evaluate(node => { node.open = false; });
      held.shift().resolve();
      await page.waitForTimeout(1500);
      assert.equal(started.length, 4, 'closed queued disclosure started');
      await disclosure(page, queuedId).evaluate(node => { node.open = true; });
      await page.waitForTimeout(1500);
      assert.equal(started.length, 5, 'reopened queued disclosure did not start');
      assert.equal(held.length, 4, 'reopening must not exceed four held requests');
      console.log('MEASURED maximum held concurrent fragment requests: 4; fifth deferred while closed');
    });
  } finally {
    releaseAll = true;
    held.forEach(item => item.resolve());
    await context.close();
  }
}

async function logout(browser) {
  const { context, page } = await newPage(browser, { width: 1440, height: 900 });
  try {
    await check('sign-out clears workspace storage and denies subsequent fixture access', async () => {
      await loadNook(page);
      await rootDisclosure(page).locator(':scope > summary').click();
      await rows(rootDisclosure(page)).first().waitFor();
      await page.waitForFunction(k => sessionStorage.getItem(k) !== null, key);
      const logoutLink = page.locator('a[href="/logout"]');
      assert.equal(await logoutLink.count(), 1);
      // Sidebar layout may hide the user menu; click its actual rendered link.
      await logoutLink.evaluate(element => element.click());
      await page.waitForURL(url => !url.pathname.startsWith('/reading-nook'));
      assert.equal(await page.evaluate(k => sessionStorage.getItem(k), key), null);
      const response = await page.goto(`${base}/reading-nook`);
      assert.notEqual(response.status(), 200);
    });
  } finally {
    await context.close();
  }
}

async function snapshotRevocation(browser) {
  const { context, page } = await newPage(browser, { width: 1440, height: 900 });
  let allowed = true;
  let requests = 0;
  try {
    await check('Turbo Back reauthorizes a non-cacheable viewer-dependent page', async () => {
      await loadNook(page);
      // Exercise Turbo itself in the fixture network. The drive templates'
      // actual no-cache head is covered separately by the Twig tests.
      const head = await page.evaluate(() => {
        const clone = document.head.cloneNode(true);
        clone.querySelectorAll('meta[name="turbo-cache-control"]').forEach(node => node.remove());
        return clone.innerHTML;
      });
      await context.route('**/__workspace-snapshot-test', async route => {
        requests += 1;
        const body = allowed
          ? '<p id="private-body">formerly authorized body</p>'
          : '<p id="access-revoked">access revoked</p>';
        await route.fulfill({
          status: 200,
          contentType: 'text/html',
          headers: { 'cache-control': 'private, no-store' },
          body: `<!doctype html><html><head>${head}<meta name="turbo-cache-control" content="no-cache"></head><body>${body}<a href="/reading-nook">leave</a></body></html>`,
        });
      });
      await page.evaluate(() => {
        window.snapshotRenderBodies = [];
        document.addEventListener('turbo:render', () => {
          window.snapshotRenderBodies.push(document.body.textContent);
        });
        window.Turbo.visit('/__workspace-snapshot-test');
      });
      await page.locator('#private-body').waitFor();
      await page.locator('a[href="/reading-nook"]').click();
      await page.waitForURL(/\/reading-nook$/);
      allowed = false;
      await page.evaluate(() => { window.snapshotRenderBodies = []; });
      await page.goBack();
      await page.locator('#access-revoked').waitFor();
      assert.equal(requests, 2, 'Back must request the page again after access changes');
      assert.equal(await page.locator('#private-body').count(), 0);
      const bodies = await page.evaluate(() => window.snapshotRenderBodies);
      assert.ok(bodies.every(body => !body.includes('formerly authorized body')),
        'the former body must not appear even transiently during a Turbo render');
    });
  } finally {
    await context.close();
  }
}

(async () => {
  const browser = await chromium.launch({ headless: true });
  try {
    await runViewport(browser, 'desktop', { width: 1440, height: 900 });
    await runViewport(browser, 'mobile', { width: 390, height: 844 });
    await members(browser);
    await concurrency(browser);
    await snapshotRevocation(browser);
    await logout(browser);
  } finally {
    await browser.close();
  }
  if (failures.length) {
    console.error(`${failures.length} scenario(s) failed: ${failures.join('; ')}`);
    process.exitCode = 1;
  }
})().catch(error => {
  console.error(error);
  process.exitCode = 1;
});
