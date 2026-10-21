(() => {
    'use strict';

    // Resolve the API relative to GLPI's registered asset URL, including subdirectory installs.
    const script = document.currentScript;
    if (!script || !script.src) return;
    if (window.notificationbellLoaded) return;
    window.notificationbellLoaded = true;
    const endpoint = new URL('../front/notifications.php', script.src);
    if (endpoint.origin !== window.location.origin) return;
    const POLL_INTERVAL = 60000;
    const MAX_RETRY_DELAY = 900000;
    const defaults = {
        all: 'All', resolved: 'Resolved', attention: 'Attention', responses: 'Replies',
        notifications: 'Notifications', recent: 'Recent notifications', viewAll: 'View all',
        sharedInbox: 'Shared group inbox', sharedInboxGroup: 'Shared inbox · %s',
        close: 'Close', markAllRead: 'Mark all as read', clear: 'Clear',
        filter: 'Filter notifications', groupSelector: 'Notification group', unread: 'Unread',
        newCount: '%d new', noGroup: 'No group', empty: 'No notifications yet.',
        emptyNoGroup: 'You do not belong to a GLPI group.',
        emptyCategory: 'No notifications in this category.',
        loadError: 'Could not load notifications.',
        loadErrorStatus: 'Could not load notifications (%d).',
        csrfError: 'GLPI security token was not found.',
    };
    const icons = {
        resolved: '✓',
        attention: '!',
        responses: '✉',
        other: '•',
    };
    let savedGroupId = 0;
    try { savedGroupId = Number(window.localStorage.getItem('notificationbell.group_id')) || 0; } catch (_) { /* Storage can be disabled. */ }
    const state = {notifications: [], groups: [], groupId: savedGroupId, unread: 0, tab: 'all', openHost: null, modalOpen: false, i18n: defaults};
    let requestQueue = Promise.resolve();
    let accessStopped = false;
    let queuedRequests = 0;
    let pollTimer = null;
    let pollingStarted = false;
    let retryDelay = POLL_INTERVAL;
    let nextPollAt = Date.now() + POLL_INTERVAL;
    const formatter = new Intl.RelativeTimeFormat(document.documentElement.lang || globalThis.navigator?.language || 'en', {numeric: 'auto'});
    const tr = (key, ...args) => args.reduce((message, value) => message.replace(/%[sd]/, String(value)), state.i18n[key] || defaults[key] || key);

    // Always insert server-supplied text with textContent to avoid HTML injection.
    function element(tag, className, content) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (content !== undefined) node.textContent = content;
        return node;
    }

    // Render GLPI's database timestamp as a short label; retain the raw value if invalid.
    function relativeTime(value) {
        const date = new Date(String(value).replace(' ', 'T'));
        if (Number.isNaN(date.getTime())) return value;
        const minutes = Math.round((date.getTime() - Date.now()) / 60000);
        if (Math.abs(minutes) < 60) return formatter.format(minutes, 'minute');
        const hours = Math.round(minutes / 60);
        if (Math.abs(hours) < 24) return formatter.format(hours, 'hour');
        return formatter.format(Math.round(hours / 24), 'day');
    }

    // The server enforces authorization; this request wrapper supplies CSRF for writes.
    function request(action = 'list', id = null) {
        const groupId = state.groupId;
        if (action !== 'list' && groupId <= 0) {
            return action === 'mark_all_read' ? request() : Promise.resolve();
        }

        // Preserve the order of reads and writes so a read cannot restore stale state.
        const run = async () => {
            if (accessStopped) throw new Error(tr('loadError'));
            let requestedGroupId = groupId;
            if (action === 'list' && requestedGroupId !== state.groupId) return;

            const url = new URL(endpoint);
            const view = state.modalOpen ? 'history' : 'preview';
            const controller = new AbortController();
            const options = {
                credentials: 'same-origin', redirect: 'error', signal: controller.signal,
                headers: {'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json'},
            };
            if (action === 'list') {
                options.cache = 'no-store';
                url.searchParams.set('view', view);
                if (requestedGroupId > 0) url.searchParams.set('group_id', String(requestedGroupId));
            } else {
                const token = document.querySelector('meta[property="glpi:csrf_token"]')?.content;
                if (!token) throw new Error(tr('csrfError'));
                options.method = 'POST';
                options.headers['X-Glpi-Csrf-Token'] = token;
                options.headers['Content-Type'] = 'application/x-www-form-urlencoded; charset=UTF-8';
                options.body = new URLSearchParams({action, view, group_id: String(requestedGroupId), ...(id === null ? {} : {id: String(id)})});
            }
            const timeout = window.setTimeout(() => controller.abort(), 15000);
            try {
                let response = await fetch(url, options);
                if (state.groupId !== requestedGroupId) return;
                if (action === 'list' && response.status === 403 && requestedGroupId > 0) {
                    state.groupId = 0;
                    state.notifications = [];
                    state.groups = [];
                    state.unread = 0;
                    render();
                    requestedGroupId = 0;
                    url.searchParams.delete('group_id');
                    try { window.localStorage.removeItem('notificationbell.group_id'); } catch (_) { /* Storage can be disabled. */ }
                    response = await fetch(url, options);
                }
                if (!response.ok) {
                    if ([401, 403, 404].includes(response.status)) accessStopped = true;
                    const error = new Error(tr('loadErrorStatus', response.status));
                    if ([429, 503].includes(response.status)) {
                        const retryAfter = response.headers.get('Retry-After');
                        error.retryAfterMs = /^\d+$/.test(retryAfter || '')
                            ? Number(retryAfter) * 1000
                            : Math.max(0, Date.parse(retryAfter) - Date.now()) || 0;
                    }
                    throw error;
                }
                if (!response.headers.get('Content-Type')?.includes('application/json')) throw new Error(tr('loadError'));
                const data = await response.json();
                if (!data || !Array.isArray(data.notifications) || !Array.isArray(data.groups)) throw new Error(tr('loadError'));
                if (state.groupId !== requestedGroupId) return;
                state.notifications = data.notifications;
                if (data.i18n && typeof data.i18n === 'object') state.i18n = {...defaults, ...data.i18n};
                state.groups = data.groups;
                state.groupId = Number(data.group_id) || 0;
                state.unread = Number(data.unread) || 0;
                render();
            } catch (error) {
                if (accessStopped) {
                    // Revocation removes ticket excerpts already displayed in the page too.
                    state.notifications = [];
                    state.groups = [];
                    state.unread = 0;
                    render();
                }
                throw error;
            } finally {
                window.clearTimeout(timeout);
            }
        };

        queuedRequests++;
        schedulePoll();
        const pending = requestQueue.then(run, run).then(value => {
            retryDelay = POLL_INTERVAL;
            // Spread clients slightly so simultaneous page loads do not synchronize every poll.
            nextPollAt = Date.now() + POLL_INTERVAL + Math.floor(Math.random() * 5000);
            return value;
        }, error => {
            retryDelay = Math.min(retryDelay * 2, MAX_RETRY_DELAY);
            nextPollAt = Date.now() + Math.max(retryDelay, error.retryAfterMs || 0);
            throw error;
        }).finally(() => {
            queuedRequests--;
            schedulePoll();
        });
        requestQueue = pending.catch(() => {});
        return pending;
    }

    // One timer after completion: never overlap or accumulate background requests.
    function schedulePoll() {
        window.clearTimeout(pollTimer);
        pollTimer = null;
        if (!pollingStarted || accessStopped || queuedRequests > 0
            || document.hidden || globalThis.navigator?.onLine === false) return;
        pollTimer = window.setTimeout(() => {
            pollTimer = null;
            request().catch(showError);
        }, Math.max(0, nextPollAt - Date.now()));
    }

    function startPolling() {
        pollingStarted = true;
        schedulePoll();
        document.addEventListener('visibilitychange', schedulePoll);
        window.addEventListener('offline', schedulePoll);
        window.addEventListener('online', schedulePoll);
        window.addEventListener('pagehide', () => {
            pollingStarted = false;
            window.clearTimeout(pollTimer);
        });
        window.addEventListener('pageshow', () => {
            pollingStarted = true;
            schedulePoll();
        });
    }

    // A saved group is only a preference; the API validates current membership.
    function groupSelector() {
        if (state.groups.length < 2) return null;
        const select = element('select', 'nb-group-select');
        select.setAttribute('aria-label', tr('groupSelector'));
        state.groups.forEach(group => {
            const option = element('option', '', group.name);
            option.value = String(group.id);
            option.selected = Number(group.id) === state.groupId;
            select.append(option);
        });
        select.addEventListener('click', event => event.stopPropagation());
        select.addEventListener('change', () => {
            state.groupId = Number(select.value);
            state.notifications = [];
            state.unread = 0;
            try { window.localStorage.setItem('notificationbell.group_id', String(state.groupId)); } catch (_) { /* Storage can be disabled. */ }
            render();
            request(state.openHost || state.modalOpen ? 'mark_all_read' : 'list').catch(showError);
        });
        return select;
    }

    // Keep links on the current origin and render notification fields as plain text.
    function makeItem(notification) {
        const item = element('div', `nb-item ${notification.unread ? 'is-unread' : ''} ${notification.category === 'attention' ? 'is-critical' : ''}`);
        const icon = element('span', 'nb-item-icon', icons[notification.category] || icons.other);
        icon.setAttribute('aria-hidden', 'true');
        const link = element('a', 'nb-item-link');
        const url = new URL(notification.url, window.location.href);
        if (url.origin === window.location.origin) link.href = url.href;
        link.append(element('strong', '', notification.title), element('span', '', notification.body));
        const time = element('time', '', relativeTime(notification.created));
        time.dateTime = String(notification.created).replace(' ', 'T');
        link.append(time);
        link.addEventListener('click', async event => {
            if (!notification.unread || !link.href) return;
            event.preventDefault();
            try {
                await request('mark_read', notification.id);
            } catch (error) {
                showError(error);
            }
            window.location.assign(link.href);
        });
        item.append(icon, link);
        if (notification.unread) {
            const dot = element('span', 'nb-unread-dot');
            dot.setAttribute('aria-label', tr('unread'));
            item.append(dot);
        }
        return item;
    }

    // Surface failed mutations instead of silently presenting stale inbox state.
    function showError(error) {
        const message = error instanceof Error ? error.message : tr('loadError');
        document.querySelectorAll('.nb-status').forEach(node => { node.textContent = message; });
    }

    // Keep the visible panel and accessibility state in sync.
    function closeDropdown() {
        if (state.openHost) {
            state.openHost.classList.remove('is-open');
            state.openHost.querySelector('.nb-bell')?.setAttribute('aria-expanded', 'false');
            state.openHost = null;
        }
    }

    // Opening the history marks accessible notices read for the selected group.
    function openModal() {
        closeDropdown();
        state.modalOpen = true;
        const modal = document.getElementById('notificationbell-modal');
        if (modal) {
            modal.hidden = false;
            document.body.classList.add('nb-modal-visible');
            modal.querySelector('.nb-modal-close')?.focus();
        }
        request('mark_all_read').catch(showError);
    }

    // Restore document scrolling when the dialog closes.
    function closeModal() {
        state.modalOpen = false;
        const modal = document.getElementById('notificationbell-modal');
        if (modal) modal.hidden = true;
        document.body.classList.remove('nb-modal-visible');
    }

    // Install one bell beside each GLPI user menu; the inbox state is shared.
    function installBell(userMenu) {
        const host = element('div', 'nb-host nav-item');
        const button = element('button', 'nb-bell nav-link');
        button.type = 'button';
        button.innerHTML = '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9Z"/><path d="M10 21h4"/></svg>';
        button.setAttribute('aria-label', tr('notifications'));
        button.setAttribute('aria-expanded', 'false');
        button.setAttribute('aria-haspopup', 'true');
        const badge = element('span', 'nb-badge');
        badge.hidden = true;
        button.append(badge);

        const panel = element('section', 'nb-dropdown');
        panel.setAttribute('aria-label', tr('recent'));
        panel.append(element('div', 'nb-dropdown-header'));
        panel.append(element('div', 'nb-list'));
        const footer = element('div', 'nb-dropdown-footer');
        const all = element('button', 'nb-view-all', `${tr('viewAll')} →`);
        all.type = 'button';
        all.addEventListener('click', openModal);
        footer.append(all);
        panel.append(footer);
        panel.append(element('p', 'nb-status', ''));
        host.append(button, panel);
        userMenu.parentElement.insertBefore(host, userMenu);

        button.addEventListener('click', event => {
            event.stopPropagation();
            const alreadyOpen = state.openHost === host;
            closeDropdown();
            if (alreadyOpen) return;
            host.classList.add('is-open');
            state.openHost = host;
            button.setAttribute('aria-expanded', 'true');
            request('mark_all_read').catch(showError);
        });
    }

    // Build the dialog once and bind its actions to the currently selected group.
    function installModal() {
        const modal = element('div', 'nb-modal');
        modal.id = 'notificationbell-modal';
        modal.hidden = true;
        modal.innerHTML = '<div class="nb-backdrop" data-nb-close></div><section class="nb-modal-card" role="dialog" aria-modal="true" aria-labelledby="nb-modal-title"><header class="nb-modal-header"><div><h2 id="nb-modal-title"></h2><span class="nb-group-name"></span><div class="nb-modal-group"></div></div><button type="button" class="nb-modal-close">×</button></header><div class="nb-modal-actions"><button type="button" class="nb-mark-all"></button><button type="button" class="nb-clear"></button></div><nav class="nb-tabs" role="tablist"></nav><div class="nb-modal-list"></div><p class="nb-status" role="status"></p></section>';
        modal.querySelector('#nb-modal-title').textContent = tr('notifications');
        modal.querySelector('.nb-group-name').textContent = tr('sharedInbox');
        modal.querySelector('.nb-modal-close').setAttribute('aria-label', tr('close'));
        modal.querySelector('.nb-mark-all').textContent = `✓ ${tr('markAllRead')}`;
        modal.querySelector('.nb-clear').textContent = tr('clear');
        modal.querySelector('.nb-tabs').setAttribute('aria-label', tr('filter'));
        document.body.append(modal);
        modal.querySelectorAll('[data-nb-close], .nb-modal-close').forEach(node => node.addEventListener('click', closeModal));
        modal.querySelector('.nb-mark-all').addEventListener('click', () => request('mark_all_read').catch(showError));
        modal.querySelector('.nb-clear').addEventListener('click', () => {
            request('clear').then(closeModal).catch(showError);
        });
    }

    // Rebuild both views from one response so group switches cannot mix inboxes.
    function render() {
        const currentGroup = state.groups.find(group => Number(group.id) === state.groupId);
        const groupName = currentGroup?.name || tr('noGroup');
        document.querySelectorAll('.nb-host').forEach(host => {
            host.querySelector('.nb-status').textContent = '';
            const badge = host.querySelector('.nb-badge');
            badge.hidden = state.unread === 0;
            badge.textContent = state.unread > 99 ? '99+' : String(state.unread);

            const header = host.querySelector('.nb-dropdown-header');
            header.replaceChildren();
            const copy = element('div');
            copy.append(element('strong', '', tr('notifications')), element('span', '', groupName));
            header.append(copy);
            if (state.unread) header.append(element('span', 'nb-count', tr('newCount', state.unread)));
            const selector = groupSelector();
            if (selector) header.append(selector);

            const list = host.querySelector('.nb-list');
            list.replaceChildren();
            const recent = state.notifications.slice(0, 5);
            if (!recent.length) list.append(element('p', 'nb-empty', state.groups.length ? tr('empty') : tr('emptyNoGroup')));
            recent.forEach(notification => list.append(makeItem(notification)));
        });

        const modal = document.getElementById('notificationbell-modal');
        if (!modal) return;
        modal.querySelector('.nb-group-name').textContent = tr('sharedInboxGroup', groupName);
        const groupHolder = modal.querySelector('.nb-modal-group');
        groupHolder.replaceChildren();
        const modalSelector = groupSelector();
        if (modalSelector) groupHolder.append(modalSelector);
        const tabs = modal.querySelector('.nb-tabs');
        tabs.replaceChildren();
        for (const key of ['all', 'resolved', 'attention', 'responses']) {
            const button = element('button', state.tab === key ? 'is-active' : '', tr(key));
            button.type = 'button';
            button.setAttribute('role', 'tab');
            button.setAttribute('aria-selected', String(state.tab === key));
            const count = state.notifications.filter(n => n.unread && (key === 'all' || n.category === key)).length;
            if (count) button.append(element('i', '', String(count)));
            button.addEventListener('click', () => { state.tab = key; render(); });
            tabs.append(button);
        }
        const list = modal.querySelector('.nb-modal-list');
        list.replaceChildren();
        const filtered = state.notifications.filter(n => state.tab === 'all' || n.category === state.tab);
        if (!filtered.length) list.append(element('p', 'nb-empty', tr('emptyCategory')));
        filtered.forEach(n => list.append(makeItem(n)));
        modal.querySelector('.nb-mark-all').hidden = state.unread === 0;
        modal.querySelector('.nb-clear').hidden = state.notifications.length === 0;
        modal.querySelector('.nb-status').textContent = '';
    }

    // Periodic reads do not persist the GLPI session; hidden/offline pages pause polling.
    async function start() {
        const menus = document.querySelectorAll('header.navbar .user-menu');
        if (!menus.length) return;
        let startupError = null;
        try { await request(); } catch (error) { startupError = error; }
        menus.forEach(installBell);
        installModal();
        render();
        if (startupError) showError(startupError);
        startPolling();
        document.addEventListener('click', event => {
            if (state.openHost && !state.openHost.contains(event.target)) closeDropdown();
        });
        document.addEventListener('keydown', event => {
            if (event.key === 'Escape') {
                if (state.modalOpen) closeModal();
                else closeDropdown();
            }
        });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, {once: true});
    else start();
})();
