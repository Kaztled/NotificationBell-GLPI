const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const source = fs.readFileSync(`${__dirname}/../public/js/bell.v3.js`, 'utf8');
const calls = [];
const context = {
    document: {
        currentScript: {src: 'https://glpi.test/plugins/notificationbell/js/bell.js'},
        readyState: 'loading',
        documentElement: {lang: 'es'},
        addEventListener() {},
        querySelector: () => ({content: 'token'}),
        querySelectorAll: () => [],
        getElementById: () => null,
    },
    window: {
        location: {origin: 'https://glpi.test'},
        localStorage: {getItem: () => null, removeItem() {}},
        setTimeout, clearTimeout,
    },
    AbortController,
    URL,
    URLSearchParams,
    Intl,
    fetch(url, options) {
        return new Promise(resolve => calls.push({url: String(url), options, resolve}));
    },
};

// Expose only the request boundary; keep the production script unchanged.
vm.runInNewContext(source.replace(/\}\)\(\);\s*$/, 'globalThis.testBell = {request, state, tr};})();'), context);
const {request, state, tr} = context.testBell;
const reply = (call, groupId, unread) => call.resolve({
    ok: true,
    headers: {get: () => 'application/json'},
    json: async () => ({group_id: groupId, unread, groups: [], notifications: [], i18n: {notifications: 'Notificaciones', newCount: '%d nuevas'}}),
});
const flush = () => new Promise(resolve => setImmediate(resolve));

(async () => {
    const oldList = request();
    await flush();
    state.groupId = 11;
    reply(calls[0], 9, 4);
    await oldList;
    assert.equal(state.groupId, 11, 'An old group response must not replace the selection.');

    const currentList = request();
    await flush();
    assert.match(calls[1].url, /group_id=11/);
    reply(calls[1], 11, 2);
    await currentList;
    assert.equal(state.unread, 2);
    assert.equal(tr('notifications'), 'Notificaciones');
    assert.equal(tr('newCount', 2), '2 nuevas');

    const mutation = request('mark_all_read');
    const poll = request();
    await flush();
    assert.equal(calls.length, 3, 'Polling must wait while a write is in flight.');
    assert.equal(calls[2].options.method, 'POST');
    assert.equal(calls[2].options.headers['X-Glpi-Csrf-Token'], 'token');
    assert.equal(calls[2].options.redirect, 'error', 'Session expiry must not fetch a login redirect.');
    reply(calls[2], 11, 0);
    await mutation;
    await flush();
    assert.equal(calls.length, 4);
    reply(calls[3], 11, 0);
    await poll;
    assert.equal(state.unread, 0);

    state.groupId = 99;
    state.groups = [{id: 99, name: 'Revoked group'}];
    state.notifications = [{id: 42, body: 'Previously authorized excerpt'}];
    const revokedGroup = request();
    await flush();
    calls[4].resolve({ok: false, status: 403});
    await flush();
    assert.equal(state.groupId, 0);
    assert.equal(state.notifications.length, 0, 'A revoked group must discard cached excerpts before requesting a fallback inbox.');
    assert.equal(state.groups.length, 0);
    assert.equal(state.unread, 0);
    assert.doesNotMatch(calls[5].url, /group_id=/);
    reply(calls[5], 9, 1);
    await revokedGroup;
    assert.equal(state.groupId, 9);
    assert.equal(state.unread, 1);

    state.notifications = [{id: 43, body: 'Cached excerpt'}];
    const failedFallback = request();
    const fallbackFailure = assert.rejects(failedFallback);
    await flush();
    calls[6].resolve({ok: false, status: 403});
    await flush();
    calls[7].resolve({ok: false, status: 500});
    await fallbackFailure;
    assert.equal(state.notifications.length, 0, 'A failed fallback must not restore revoked excerpts.');
    assert.equal(state.unread, 0);

    const expired = request();
    await flush();
    calls[8].resolve({ok: false, status: 401});
    await assert.rejects(expired);
    assert.equal(state.unread, 0, 'Session expiry must remove cached activity.');
    assert.equal(state.notifications.length, 0);
    assert.equal(state.groups.length, 0);
    await assert.rejects(request('mark_all_read'));
    await assert.rejects(request());
    assert.equal(calls.length, 9, 'Expired sessions must stop further reads and writes until reload.');

    console.log('Notification Bell ordering, CSRF and session expiry tests passed.');
})().catch(error => { console.error(error); process.exitCode = 1; });
