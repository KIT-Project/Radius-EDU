/* FortiGate external portal: browser POST directly to the trusted gateway. */
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
    } else if (!config.fortigateOrigin) {
        status.textContent = 'ยังไม่ได้ตั้งค่าปลายทาง FortiGate กรุณาติดต่อผู้ดูแลระบบ';
    } else {
        try {
            const target = new URL(post);
            const trusted = new URL(config.fortigateOrigin);
            if (target.protocol !== 'https:' || target.origin !== trusted.origin ||
                target.pathname !== '/fgtauth' || target.username || target.password ||
                target.search || target.hash || magic.length > 512) {
                throw new Error('Invalid gateway');
            }
            form.action = target.href;
            document.getElementById('magic').value = magic;
            button.disabled = false;
            ready = true;
            status.textContent = 'พร้อมเข้าสู่ระบบ Wi-Fi ของโรงเรียน';
        } catch (_) {
            status.textContent = 'ปลายทางเข้าสู่ระบบไม่ตรงกับ FortiGate ของโรงเรียน';
        }
    }
    form.addEventListener('submit', function (event) {
        if (!ready) { event.preventDefault(); return; }
        button.disabled = true;
        status.textContent = 'กำลังส่งข้อมูลเข้าสู่ระบบไปยัง FortiGate…';
    });
}());
