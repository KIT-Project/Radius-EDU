/* FortiGate external portal: browser POST to the gateway supplied in the redirect. */
(function () {
    'use strict';
    const config = window.schoolPortalConfig || {};
    const form = document.getElementById('loginForm');
    const button = document.getElementById('submitButton');
    const status = document.getElementById('status');
    const retry = document.getElementById('retryLogin');
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
    if (channel) {
        channel.onmessage = function (event) {
            // A reply from an earlier login (even at the same IP) must not finish this one.
            if (!pending || !event.data || event.data.type !== 'login-complete' ||
                event.data.attempt !== attempt) return;
            pending = false;
            document.getElementById('password').value = '';
            form.hidden = true;
            status.textContent = 'เข้าสู่ระบบสำเร็จ กำลังเปิด Google…';
            window.location.replace('https://www.google.com/');
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
        // Ask the network for a fresh challenge instead of posting incomplete credentials.
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
    });
    window.addEventListener('pageshow', function (event) {
        if (!event.persisted) return;
        pending = false;
        button.disabled = false;
        document.getElementById('password').value = '';
        if (ready && !failed) status.textContent = 'พร้อมเข้าสู่ระบบ Wi-Fi ของโรงเรียน';
    });
}());
