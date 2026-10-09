const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require('node:path').join(__dirname, '../js/schoolPortal.js'), 'utf8');

function page(query, config = {}) {
    const elements = {};
    for (const id of ['loginForm', 'submitButton', 'status', 'retryLogin', 'schoolName', 'magic', 'password']) {
        const classes = new Set();
        elements[id] = {
            hidden: id === 'retryLogin', value: '', children: [], listeners: {}, attrs: {},
            classList: { add: name => classes.add(name), remove: name => classes.delete(name), contains: name => classes.has(name) },
            appendChild(child) { this.children.push(child); },
            setAttribute(name, value) { this.attrs[name] = value; },
            addEventListener(name, callback) { this.listeners[name] = callback; }
        };
    }
    const window = {
        schoolPortalConfig: config, location: { search: query }, listeners: {},
        addEventListener(name, callback) { this.listeners[name] = callback; }
    };
    vm.runInNewContext(source, {
        window, URL, URLSearchParams,
        document: { getElementById: id => elements[id], createElement: () => ({}) }
    });
    return { elements, window };
}
const challenge = '?magic=transaction123&post=' + encodeURIComponent('https://10.10.10.1:1003/fgtauth');

test('native login POST supplies Google in URL and body, ignoring query redirect overrides', () => {
    const { elements, window } = page(challenge + '&CONTINUE_URL=https://evil.example/&username=evil&password=evil&userip=192.168.23.59');
    const target = new URL(elements.loginForm.action);
    assert.equal(target.origin, 'https://10.10.10.1:1003');
    assert.equal(target.pathname, '/fgtauth');
    assert.equal(target.searchParams.get('magic'), 'transaction123');
    assert.equal(target.searchParams.get('CONTINUE_URL'), 'https://www.google.com/');
    const fields = Object.fromEntries(elements.loginForm.children.map(field => [field.name, field.value]));
    assert.equal(fields.CONTINUE_URL, 'https://www.google.com/');
    assert.equal(fields.userip, '192.168.23.59');
    assert.equal(fields.username, undefined);
    assert.equal(fields.password, undefined);
    let blocked = false;
    elements.loginForm.listeners.submit({ preventDefault() { blocked = true; } });
    assert.equal(blocked, false);
    assert.equal(elements.submitButton.disabled, true);
    assert.equal(window.location.href, undefined, 'submission must not prematurely navigate to Google');
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
