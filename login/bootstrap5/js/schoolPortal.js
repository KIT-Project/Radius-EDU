/* FortiGate external portal: browser POST to the gateway supplied in the redirect. */
(function () {
    'use strict';
    const config = window.schoolPortalConfig || {};
    const form = document.getElementById('loginForm');
    const button = document.getElementById('submitButton');
    const status = document.getElementById('status');
    const retry = document.getElementById('retryLogin');
    // Trigger a fresh HTTP captive-portal challenge. Google can be upgraded to
    // HTTPS by HSTS, preventing the gateway from intercepting the request.
    function freshChallengeUrl() {
        const url = new URL('http://neverssl.com/');
        url.searchParams.set('school_wifi_retry', Date.now() + '-' +
            Array.from(window.crypto.getRandomValues(new Uint8Array(8)),
                byte => byte.toString(16).padStart(2, '0')).join(''));
        return url.href;
    }
    retry.href = freshChallengeUrl();
    retry.addEventListener('click', function () {
        // A second click/back navigation must not reuse a cached redirect/token.
        retry.href = freshChallengeUrl();
    });
    const params = new URLSearchParams(window.location.search);
    const failed = (params.get('Auth') || '').toLowerCase() === 'failed';
    const attempt = Array.from(window.crypto.getRandomValues(new Uint8Array(16)),
        byte => byte.toString(16).padStart(2, '0')).join('');
    const completion = new URL('success.html', window.location.href);
    completion.hash = attempt;
    const continueUrl = completion.href;
    let pending = false;
    let channel = null;
    if (window.BroadcastChannel) {
        try { channel = new window.BroadcastChannel('school-wifi-login'); } catch (_) {}
    }
    let probeTimer = null;
    let proofToken = null;
    let probeBusy = false;
    const canConfirm = !!(window.fetch && window.AbortController);
    const statusUrl = new URL('/cake4/rd_cake/radaccts/portal-session-status.json', window.location.href).href;
    function finishLogin() {
        pending = false;
        if (probeTimer !== null) window.clearInterval(probeTimer);
        document.getElementById('password').value = '';
        form.hidden = true;
        status.textContent = 'เข้าสู่ระบบสำเร็จ กำลังเปิด Google…';
        window.location.replace('https://www.google.com/');
    }
    async function sessionRequest(data) {
        const controller = new window.AbortController();
        const timeout = window.setTimeout(function () { controller.abort(); }, 4000);
        try {
            const response = await window.fetch(statusUrl, {
                method: 'POST', mode: 'same-origin', credentials: 'omit', cache: 'no-store',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: new URLSearchParams(data).toString(), signal: controller.signal
            });
            if (!response.ok) throw new Error('Session confirmation unavailable');
            return await response.json();
        } finally { window.clearTimeout(timeout); }
    }
    // Confirm a NEW Accounting session for this username and the caller's real
    // IP, rather than guessing from internet connectivity or an older login.
    function watchWaitingTab() {
        if (!proofToken || probeTimer !== null) return;
        let checks = 0;
        probeTimer = window.setInterval(async function () {
            if (!pending || ++checks > 85) {
                window.clearInterval(probeTimer); probeTimer = null; return;
            }
            if (probeBusy) return;
            probeBusy = true;
            try {
                const result = await sessionRequest({token: proofToken});
                if (pending && result.authenticated === true) finishLogin();
            } catch (_) {
                // The native FortiGate continuation remains available if the API is offline.
            } finally { probeBusy = false; }
        }, 2000);
    }
    if (channel) {
        channel.onmessage = function (event) {
            // A reply from an earlier login (even at the same IP) must not finish this one.
            if (!pending || !event.data || event.data.type !== 'login-complete' ||
                event.data.attempt !== attempt) return;
            finishLogin();
        };
    }
    document.getElementById('schoolName').textContent = config.schoolName || 'WIFI';
    document.title = config.schoolName || 'WIFI';
    let ready = false;
    const magic = params.get('magic');
    const post = params.get('post');
    if (!magic || !post) {
        status.textContent = '';
        status.hidden = true;
    } else {
        try {
            const target = new URL(post);
            const trusted = config.fortigateOrigin ? new URL(config.fortigateOrigin) : null;
            if (target.protocol !== 'https:' || (trusted && target.origin !== trusted.origin) ||
                target.pathname !== '/fgtauth' || target.username || target.password ||
                target.search || target.hash || magic.length > 512) {
                throw new Error('Invalid gateway');
            }
            // Match the working external portal: transaction token in URL and body.
            target.searchParams.set('magic', magic);
            target.searchParams.set('auth', '1');
            // The gateway follows this destination only after successful authentication.
            // Keep it fixed here; never accept a redirect destination from the page URL.
            target.searchParams.set('CONTINUE_URL', continueUrl);
            form.action = target.href;
            document.getElementById('magic').value = magic;
            const destination = document.createElement('input');
            destination.type = 'hidden';
            destination.name = 'CONTINUE_URL';
            destination.value = continueUrl;
            form.appendChild(destination);
            // Preserve gateway context without allowing it to replace credentials.
            ['login', 'post', 'usermac', 'apmac', 'apip', 'userip',
                'ssid', 'apname', 'bssid', 'device_type'].forEach(function (name) {
                if (!params.has(name)) return;
                const field = document.createElement('input');
                field.type = 'hidden';
                field.name = name;
                field.value = params.get(name);
                form.appendChild(field);
            });
            button.disabled = false;
            ready = true;
            status.textContent = 'พร้อมเข้าสู่ระบบ Wi-Fi ของโรงเรียน';
        } catch (_) {
            status.textContent = 'ปลายทางเข้าสู่ระบบไม่ตรงกับ FortiGate ของโรงเรียน';
        }
    }
    if (failed) {
        status.hidden = false;
        status.classList.add('error');
        status.setAttribute('role', 'alert');
        status.textContent = 'เข้าสู่ระบบไม่สำเร็จ กรุณาตรวจสอบชื่อผู้ใช้และรหัสผ่าน หากข้อมูลถูกต้อง โปรดติดต่อผู้ดูแลระบบเพื่อตรวจสอบสิทธิ์การใช้งาน';
        document.getElementById('password').value = '';
        // FortiGate can return Auth=Failed without a new transaction token.
        // Request a fresh challenge via non-HSTS HTTP; successful authentication
        // continues to success.html and Google through the new login transaction.
        if (!ready) {
            form.hidden = true;
            retry.hidden = false;
        }
    }
    button.disabled = false;
    form.addEventListener('submit', function (event) {
        if (!ready) {
            event.preventDefault();
            status.hidden = false;
            status.textContent = 'ไม่พบข้อมูลเข้าสู่ระบบที่ถูกต้องจาก FortiGate กรุณาเปิดผ่านเครือข่าย Wi-Fi อีกครั้ง';
            return;
        }
        // Navigate this browser tab, including when the portal is embedded in a frame.
        form.target = '_top';
        button.formTarget = '_top';
        pending = true;
        button.disabled = true;
        status.hidden = false;
        status.classList.remove('error');
        status.setAttribute('role', 'status');
        status.textContent = 'กำลังส่งข้อมูลเข้าสู่ระบบไปยัง FortiGate…';
        if (!canConfirm) return;
        event.preventDefault();
        // Capture the accounting watermark BEFORE credentials reach FortiGate.
        // Never send the password to the portal API.
        sessionRequest({username: document.getElementById('username').value}).then(function (result) {
            if (typeof result.token === 'string') proofToken = result.token;
        }).catch(function () {
            // Failure to initialize confirmation must not block the native login.
        }).then(function () {
            if (!pending) return;
            watchWaitingTab();
            form.submit();
        });
    });
    window.addEventListener('pageshow', function (event) {
        if (!event.persisted) return;
        pending = false;
        if (probeTimer !== null) { window.clearInterval(probeTimer); probeTimer = null; }
        button.disabled = false;
        document.getElementById('password').value = '';
        if (ready && !failed) status.textContent = 'พร้อมเข้าสู่ระบบ Wi-Fi ของโรงเรียน';
    });
}());
