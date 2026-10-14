import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

/**
 * Live messages for a page that carries `{{ messaging:realtime }}`.
 *
 * The websocket only says "message N changed". The text is fetched from an
 * authorised endpoint, because the sender's name depends on who is looking.
 *
 * Markup the page may offer (all optional):
 *   [data-messaging-list]                  where new messages are appended; give it aria-live="polite"
 *   <template data-messaging-template="mine|theirs">
 *                                          one message; [data-field="body|sender|time"] receive the text
 *   [data-message-id="42"]                 a rendered message, so a live one is not added twice
 *   [data-messaging-refresh="name"]        a region (inbox list, unread badges) replaced from a fresh
 *                                          copy of the page whenever anything arrives for this person
 */
const script = document.querySelector('script[data-messaging-realtime]');

function start(config) {
    window.Pusher = Pusher;

    const secure = (config.scheme || (location.protocol === 'https:' ? 'https' : 'http')) === 'https';
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || config.csrf;
    const headers = { 'X-CSRF-TOKEN': csrf, Accept: 'application/json' };
    const options = {
        broadcaster: config.driver === 'pusher' ? 'pusher' : 'reverb',
        key: config.key,
        authEndpoint: config.auth,
        auth: { headers },
        channelAuthorization: { endpoint: config.auth, transport: 'ajax', headers },
    };

    if (config.driver === 'pusher') {
        options.cluster = config.cluster;
        options.forceTLS = true;
    } else {
        const port = Number(config.port || location.port || (secure ? 443 : 80));
        options.wsHost = config.host || location.hostname;
        options.wsPort = port;
        options.wssPort = port;
        options.forceTLS = secure;
        options.enabledTransports = ['ws', 'wss'];
    }

    const echo = new Echo(options);
    window.messagingEcho = echo;

    const list = document.querySelector('[data-messaging-list]');

    if (list && config.conversationChannel) {
        const channel = echo.private(config.conversationChannel);
        channel.listen('.message.sent', (e) => show(config, list, e.message));
        channel.listen('.message.restored', (e) => show(config, list, e.message));
        channel.listen('.message.hidden', (e) => hide(list, e.message));
    }

    if (config.userChannel) {
        const refresh = debounce(() => refreshRegions(), 250);
        const channel = echo.private(config.userChannel);
        for (const name of ['.message.sent', '.message.restored', '.message.hidden']) {
            channel.listen(name, refresh);
        }
    }
}

const known = (list, id) => list.querySelector(`[data-message-id="${id}"]`);

async function show(config, list, id) {
    if (known(list, id)) return;

    const url = config.messageUrl.replace('{conversation}', config.conversation).replace('{message}', id);
    const response = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
    if (!response.ok || known(list, id)) return;

    const message = await response.json();
    const node = build(message);
    node.setAttribute('data-message-id', String(message.id));

    const scroller = scrollParent(list);
    const atBottom = scroller.scrollHeight - scroller.scrollTop - scroller.clientHeight < 120;

    // Keep the order of ids: a restored message goes back where it was.
    const later = [...list.querySelectorAll('[data-message-id]')].find((el) => Number(el.getAttribute('data-message-id')) > message.id);
    list.insertBefore(node, later || null);

    if (atBottom) scroller.scrollTop = scroller.scrollHeight;

    document.dispatchEvent(new CustomEvent('messaging:message', { detail: message }));
}

function hide(list, id) {
    known(list, id)?.remove();
    document.dispatchEvent(new CustomEvent('messaging:hidden', { detail: { id } }));
}

function build(message) {
    const template = document.querySelector(`template[data-messaging-template="${message.mine ? 'mine' : 'theirs'}"]`);
    let node;

    if (template?.content.firstElementChild) {
        node = template.content.firstElementChild.cloneNode(true);
    } else {
        node = document.createElement('div');
        node.style.cssText = 'white-space:pre-line;overflow-wrap:anywhere';
        node.innerHTML = '<p data-field="body"></p><small data-field="meta"></small>';
    }

    const fill = (field, text) => node.querySelectorAll(`[data-field="${field}"]`).forEach((el) => { el.textContent = text; });
    fill('body', message.body);
    fill('sender', message.sender);
    fill('time', message.time);
    fill('meta', `${message.mine ? '' : message.sender + ' · '}${message.time}`);

    return node;
}

let pending = false;

async function refreshRegions() {
    const regions = document.querySelectorAll('[data-messaging-refresh]');
    if (!regions.length) return;

    // A hidden tab would mark the new message as read on the server without anyone seeing it.
    if (document.hidden) {
        pending = true;
        return;
    }

    pending = false;
    const response = await fetch(location.href, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } });
    if (!response.ok) return;

    const fresh = new DOMParser().parseFromString(await response.text(), 'text/html');
    regions.forEach((region) => {
        const name = region.getAttribute('data-messaging-refresh');
        const next = fresh.querySelector(`[data-messaging-refresh="${name}"]`);
        if (next) region.replaceWith(document.importNode(next, true));
    });
}

document.addEventListener('visibilitychange', () => {
    if (!document.hidden && pending) refreshRegions();
});

function scrollParent(el) {
    for (let node = el.parentElement; node; node = node.parentElement) {
        const overflow = getComputedStyle(node).overflowY;
        if ((overflow === 'auto' || overflow === 'scroll') && node.scrollHeight > node.clientHeight) return node;
    }

    return document.scrollingElement || document.documentElement;
}

function debounce(fn, wait) {
    let timer;

    return (...args) => {
        clearTimeout(timer);
        timer = setTimeout(() => fn(...args), wait);
    };
}

if (script) {
    try {
        start(JSON.parse(script.dataset.messagingRealtime));
    } catch (error) {
        console.error('messaging realtime could not start', error);
    }
}
