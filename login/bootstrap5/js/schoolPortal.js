/* FortiGate external portal: browser POST to the gateway supplied in the redirect. */
(function () {
    'use strict';
    const config = window.schoolPortalConfig || {};
    const form = document.getElementById('loginForm');
    const button = document.getElementById('submitButton');
    const status = document.getElementById('status');
    const params = new URLSearchParams(window.location.search);
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
            form.action = target.href;
            document.getElementById('magic').value = magic;
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
    button.disabled = false;
    form.addEventListener('submit', function (event) {
        if (!ready) {
            event.preventDefault();
            status.hidden = false;
            status.textContent = 'ไม่พบข้อมูลเข้าสู่ระบบที่ถูกต้องจาก FortiGate กรุณาเปิดผ่านเครือข่าย Wi-Fi อีกครั้ง';
            return;
        }
        button.disabled = true;
        status.textContent = 'กำลังส่งข้อมูลเข้าสู่ระบบไปยัง FortiGate…';
    });
}());
