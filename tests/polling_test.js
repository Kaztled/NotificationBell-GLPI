const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(`${__dirname}/../public/js/bell.v3.js`, 'utf8');
const flush = () => new Promise(resolve => setImmediate(resolve));

function harness() {
    let now = 1000000;
    let timerId = 0;
    const timers = new Map();
    const calls = [];
    const listeners = {};
    const math = Object.create(Math);
    math.random = () => 0;
    class ClockDate extends Date { static now() { return now; } }
    const listen = (name, callback) => { listeners[name] = callback; };
    const context = {
        document: {
            currentScript: {src: 'https://glpi.test/plugins/notificationbell/js/bell.v3.js'},
            readyState: 'loading', hidden: false, documentElement: {lang: 'en'},
            addEventListener: listen, querySelector: () => ({content: 'token'}),
            querySelectorAll: () => [], getElementById: () => null,
        },
        window: {
            location: {origin: 'https://glpi.test'}, localStorage: {getItem: () => null, removeItem() {}},
            addEventListener: listen,
            setTimeout(callback, delay) { const id = ++timerId; timers.set(id, {callback, at: now + delay}); return id; },
            clearTimeout(id) { timers.delete(id); },
        },
        navigator: {onLine: true}, Date: ClockDate, Math: math,
        URL, URLSearchParams, Intl, AbortController,
        fetch(url, options) {
            return new Promise((resolve, reject) => {
                calls.push({url, options, resolve});
                options.signal.addEventListener('abort', () => reject(new Error('Request timed out')), {once: true});
            });
        },
    };
    vm.runInNewContext(source.replace(/\}\)\(\);\s*$/, 'globalThis.testBell = {request, state, startPolling};})();'), context);
    return {
        ...context.testBell, context, calls, timers,
        event: name => listeners[name](),
        async advance(ms) {
            const target = now + ms;
            for (;;) {
                const next = [...timers].filter(([, timer]) => timer.at <= target).sort((a, b) => a[1].at - b[1].at)[0];
                if (!next) break;
                const [id, timer] = next;
                now = timer.at;
                timers.delete(id);
                timer.callback();
                await flush();
            }
            now = target;
            await flush();
        },
        async reply(status = 200, retryAfter = null) {
            calls.at(-1).resolve({
                ok: status === 200, status,
                headers: {get: name => name === 'Retry-After' ? retryAfter : 'application/json'},
                json: async () => ({group_id: 9, groups: [{id: 9, name: 'Group'}], notifications: [{id: 1}], unread: 1}),
            });
            await flush();
        },
    };
}

(async () => {
    const h = harness();
    h.startPolling();
    await h.advance(59999);
    assert.equal(h.calls.length, 0);
    await h.advance(1);
    assert.equal(h.calls.length, 1, 'A visible page must periodically request notifications.');
    assert.equal(h.calls[0].url.searchParams.get('view'), 'preview', 'Background polls must request the small preview.');
    await h.advance(10000);
    assert.equal(h.calls.length, 1, 'Slow requests must not overlap.');
    await h.reply();
    assert.equal(h.state.unread, 1, 'Periodic responses must update the badge without opening the bell.');
    await h.advance(59999);
    assert.equal(h.calls.length, 1);
    await h.advance(1);
    assert.equal(h.calls.length, 2);
    h.context.document.hidden = true;
    h.event('visibilitychange');
    await h.reply();
    await h.advance(3600000);
    assert.equal(h.calls.length, 2, 'Hidden pages must not send background traffic.');
    h.context.document.hidden = false;
    h.event('visibilitychange');
    await h.advance(0);
    assert.equal(h.calls.length, 3, 'Returning to an overdue visible page must refresh once.');
    await h.reply();
    h.context.navigator.onLine = false;
    h.event('offline');
    await h.advance(3600000);
    assert.equal(h.calls.length, 3, 'Offline pages must not keep retrying.');
    h.context.navigator.onLine = true;
    h.event('online');
    await h.advance(0);
    assert.equal(h.calls.length, 4);
    await h.reply();

    h.state.modalOpen = true;
    const write = h.request('mark_all_read');
    await flush();
    assert.equal(h.calls[4].options.method, 'POST');
    assert.equal(h.calls[4].options.body.get('view'), 'history');
    assert.equal(h.timers.size, 1, 'A mutation must suspend the poll timer, leaving only its request deadline.');
    await h.reply();
    await write;
    h.event('pagehide');
    await h.advance(3600000);
    assert.equal(h.calls.length, 5, 'A page in the back-forward cache must stop polling.');
    h.event('pageshow');
    await h.advance(0);
    assert.equal(h.calls.length, 6);
    await h.reply(401);
    assert.equal(h.state.notifications.length, 0);
    await h.advance(3600000);
    assert.equal(h.calls.length, 6, 'Expired sessions must stop polls permanently until reload.');

    const b = harness();
    b.startPolling();
    await b.advance(60000);
    for (const delay of [120000, 240000, 480000, 900000, 900000]) {
        const before = b.calls.length;
        await b.reply(500);
        await b.advance(delay - 1);
        assert.equal(b.calls.length, before, 'Service failures must back off instead of retrying every minute.');
        await b.advance(1);
        assert.equal(b.calls.length, before + 1);
    }
    await b.reply();
    const recovered = b.calls.length;
    await b.advance(60000);
    assert.equal(b.calls.length, recovered + 1, 'Success must restore the normal interval.');
    await b.reply(429, '1800');
    const limited = b.calls.length;
    await b.advance(1799999);
    assert.equal(b.calls.length, limited, 'Retry-After must take precedence over the local backoff.');
    await b.advance(1);
    assert.equal(b.calls.length, limited + 1);
    await b.reply(401);

    const t = harness();
    t.startPolling();
    await t.advance(60000);
    await t.advance(15000);
    assert.equal(t.calls[0].options.signal.aborted, true, 'A hung request must be aborted after 15 seconds.');
    assert.equal(t.calls.length, 1);
    await t.advance(119999);
    assert.equal(t.calls.length, 1, 'Timeouts must back off too.');
    await t.advance(1);
    assert.equal(t.calls.length, 2);
    await t.reply(401);
    console.log('Notification Bell periodic refresh, pause, backoff, timeout and no-overlap tests passed.');
})().catch(error => { console.error(error); process.exitCode = 1; });
