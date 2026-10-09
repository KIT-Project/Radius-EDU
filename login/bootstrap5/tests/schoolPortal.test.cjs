const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require('node:path').join(__dirname, '../js/schoolPortal.js'), 'utf8');

function page(query, config = {}, browser = {}) {
    const elements = {};
    for (const id of ['loginForm', 'submitButton', 'status', 'retryLogin', 'schoolName', 'magic', 'password', 'username']) {
        const classes = new Set();
        elements[id] = {
            hidden: id === 'retryLogin', value: '', children: [], listeners: {}, attrs: {},
            classList: { add: name => classes.add(name), remove: name => classes.delete(name), contains: name => classes.has(name) },
            submit() { this.submitted = true; },
            appendChild(child) { this.children.push(child); },
            setAttribute(name, value) { this.attrs[name] = value; },
            addEventListener(name, callback) { this.listeners[name] = callback; }
        };
    }
    const channels = [];
    const redirects = [];
    const window = {
        crypto: require('node:crypto').webcrypto,
        BroadcastChannel: class {
            constructor(name) { this.name = name; channels.push(this); }
        },
        schoolPortalConfig: config,
        location: { search: query, href: 'https://portal.school/login/' + query, replace: url => redirects.push(url) }, listeners: {},
        addEventListener(name, callback) { this.listeners[name] = callback; }
    };
    Object.assign(window, browser);
    vm.runInNewContext(source, {
        window, URL, URLSearchParams,
        document: { getElementById: id => elements[id], createElement: () => ({}) }
    });
    return { elements, window, channels, redirects };
}
const challenge = '?magic=transaction123&post=' + encodeURIComponent('https://10.10.10.1:1003/fgtauth');

test('native login POST supplies our continuation, ignoring query redirect overrides', () => {
    const { elements, window, redirects } = page(challenge + '&CONTINUE_URL=https://evil.example/&username=evil&password=evil&userip=192.168.23.59');
    const target = new URL(elements.loginForm.action);
    assert.equal(target.origin, 'https://10.10.10.1:1003');
    assert.equal(target.pathname, '/fgtauth');
    assert.equal(target.searchParams.get('magic'), 'transaction123');
    const continuation = new URL(target.searchParams.get('CONTINUE_URL'));
    assert.equal(continuation.origin, 'https://portal.school');
    assert.equal(continuation.pathname, '/login/success.html');
    assert.match(continuation.hash, /^#[a-f0-9]{32}$/);
    const fields = Object.fromEntries(elements.loginForm.children.map(field => [field.name, field.value]));
    assert.equal(fields.CONTINUE_URL, continuation.href);
    assert.equal(fields.userip, '192.168.23.59');
    assert.equal(fields.username, undefined);
    assert.equal(fields.password, undefined);
    let blocked = false;
    elements.loginForm.listeners.submit({ preventDefault() { blocked = true; } });
    assert.equal(blocked, false);
    assert.equal(elements.submitButton.disabled, true);
    assert.equal(elements.loginForm.target, '_top');
    assert.equal(elements.submitButton.formTarget, '_top');
    assert.equal(redirects.length, 0, 'submission must not prematurely navigate to Google');
});

test('Auth=Failed without a challenge shows an alert and offers a fresh login', () => {
    const { elements } = page('?Auth=Failed');
    assert.equal(elements.status.hidden, false);
    assert.equal(elements.status.attrs.role, 'alert');
    assert.equal(elements.status.classList.contains('error'), true);
    assert.match(elements.status.textContent, /ชื่อผู้ใช้และรหัสผ่าน/);
    assert.equal(elements.loginForm.hidden, true);
    assert.equal(elements.retryLogin.hidden, false);
    assert.equal(elements.password.value, '');
});

test('failed login with a gateway challenge keeps the credential form available', () => {
    const { elements } = page(challenge + '&Auth=Failed');
    assert.equal(elements.loginForm.hidden, false);
    assert.equal(elements.retryLogin.hidden, true);
    elements.loginForm.listeners.submit({ preventDefault() { assert.fail('valid request blocked'); } });
    assert.equal(elements.status.classList.contains('error'), false);
    assert.equal(elements.status.attrs.role, 'status');
});

test('invalid or untrusted gateway cannot receive credentials', () => {
    for (const query of ['?magic=x&post=http://10.10.10.1/fgtauth', '?magic=x&post=https://evil.example/fgtauth']) {
        const { elements } = page(query, { fortigateOrigin: 'https://10.10.10.1:1003' });
        let blocked = false;
        elements.loginForm.listeners.submit({ preventDefault() { blocked = true; } });
        assert.equal(blocked, true);
        assert.equal(elements.loginForm.action, undefined);
    }
});

test('browser back restores the submit button and clears the password', () => {
    const { elements, window } = page(challenge);
    elements.loginForm.listeners.submit({ preventDefault() {} });
    elements.password.value = 'do-not-retain';
    window.listeners.pageshow({ persisted: true });
    assert.equal(elements.submitButton.disabled, false);
    assert.equal(elements.password.value, '');
});


test('only completion for the pending login replaces the page and clears credentials', () => {
    const { elements, channels, redirects } = page(challenge);
    const continuation = new URL(new URL(elements.loginForm.action).searchParams.get('CONTINUE_URL'));
    const attempt = continuation.hash.slice(1);
    channels[0].onmessage({ data: { type: 'login-complete', attempt } });
    assert.equal(redirects.length, 0, 'must submit first');
    elements.loginForm.listeners.submit({ preventDefault() {} });
    elements.password.value = 'sensitive';
    channels[0].onmessage({ data: { type: 'login-complete', attempt: 'old-login' } });
    assert.equal(redirects.length, 0, 'ignore a previous session at the same IP');
    channels[0].onmessage({ data: { type: 'login-complete', attempt } });
    assert.deepEqual(redirects, ['https://www.google.com/']);
    assert.equal(elements.password.value, '');
    assert.equal(elements.loginForm.hidden, true);
});

test('success continuation broadcasts its attempt and replaces itself with Google', () => {
    const source = fs.readFileSync(require('node:path').join(__dirname, '../js/schoolSuccess.js'), 'utf8');
    for (const hash of ['#' + 'a'.repeat(32), '#bad']) {
        const messages = [];
        const redirects = [];
        vm.runInNewContext(source, { window: {
            location: { hash, replace: url => redirects.push(url) },
            BroadcastChannel: class { postMessage(message) { messages.push(message); } }
        } });
        assert.deepEqual(redirects, ['https://www.google.com/']);
        assert.equal(messages.length, hash === '#bad' ? 0 : 1);
        if (messages.length) assert.equal(messages[0].attempt, 'a'.repeat(32));
    }
});


test('fresh login retry targets Google in the current top-level tab', () => {
    const html = fs.readFileSync(require('node:path').join(__dirname, '../index.html'), 'utf8');
    const retryLink = html.match(/<a\s+id="retryLogin"[^>]*>/)[0];
    assert.match(retryLink, /href="http:\/\/www\.google\.com\/"/);
    assert.match(retryLink, /target="_top"/);
    assert.doesNotMatch(retryLink, /neverssl/);
});



function sessionPage(query, initializeFails = false) {
    let authenticated = false;
    let tick;
    const calls = [];
    const fixture = page(query, {}, {
        AbortController,
        setTimeout: () => 1, clearTimeout() {},
        setInterval: callback => { tick = callback; return 2; },
        clearInterval() {},
        fetch: async (url, options) => {
            calls.push({url, options});
            if (initializeFails) throw new Error('API unavailable');
            const fields = new URLSearchParams(options.body);
            return {ok: true, json: async () => fields.has('username') ?
                {token: 'signed-fixture-proof'} : {authenticated}};
        }
    });
    fixture.elements.username.value = 'Teacher';
    return {...fixture, calls, confirm() {authenticated = true;}, tick: () => tick()};
}
const flushPromises = () => new Promise(resolve => setImmediate(resolve));

test('retry captures accounting baseline before native POST and waits for confirmed session', async () => {
    const fixture = sessionPage(challenge + '&Auth=Failed');
    fixture.elements.password.value = 'never-send-to-portal';
    let prevented = false;
    fixture.elements.loginForm.listeners.submit({preventDefault() {prevented = true;}});
    assert.equal(prevented, true);
    assert.equal(fixture.elements.loginForm.submitted, undefined, 'must capture baseline before gateway POST');
    await flushPromises();
    assert.equal(fixture.elements.loginForm.submitted, true);
    assert.equal(fixture.calls[0].url, 'https://portal.school/cake4/rd_cake/radaccts/portal-session-status.json');
    assert.equal(fixture.calls[0].options.body, 'username=Teacher');
    await fixture.tick();
    assert.equal(fixture.redirects.length, 0, 'no new session means no redirect');
    assert.equal(fixture.calls[1].options.body, 'token=signed-fixture-proof');
    fixture.confirm();
    await fixture.tick();
    assert.deepEqual(fixture.redirects, ['https://www.google.com/']);
    assert.equal(fixture.elements.loginForm.hidden, true);
    assert.equal(fixture.elements.password.value, '');
});

test('confirmation API failure still allows native FortiGate login', async () => {
    const fixture = sessionPage(challenge, true);
    fixture.elements.loginForm.listeners.submit({preventDefault() {}});
    await flushPromises();
    assert.equal(fixture.elements.loginForm.submitted, true);
    assert.equal(fixture.redirects.length, 0);
});

test('browser back cancels a pending confirmation redirect', async () => {
    const fixture = sessionPage(challenge);
    fixture.elements.loginForm.listeners.submit({preventDefault() {}});
    await flushPromises();
    fixture.window.listeners.pageshow({persisted: true});
    fixture.confirm();
    await fixture.tick();
    assert.equal(fixture.redirects.length, 0);
});
