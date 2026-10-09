/* FortiGate reaches this continuation after accepting the native login POST. */
(function () {
    'use strict';
    const attempt = window.location.hash.slice(1);
    if (/^[a-f0-9]{32}$/.test(attempt) && window.BroadcastChannel) {
        try {
            const channel = new window.BroadcastChannel('school-wifi-login');
            channel.postMessage({ type: 'login-complete', attempt: attempt });
        } catch (_) {
            // Restricted storage must not prevent the current tab from continuing.
        }
    }
    // Replace the continuation entry so Back does not reopen this redirect page.
    window.location.replace('https://www.google.com/');
}());
