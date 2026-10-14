/*
 * ฉายรหัสเข้าร่วม / QR Code ลิงก์เข้าร่วมรายวิชาเต็มหน้าจอ (แท็บนักเรียนของหน้ารายวิชา)
 * QR สร้างในเบราว์เซอร์ด้วย qrcode-generator (โหลดจาก CDN ในหน้าเดียวกัน) แล้ววาดเป็น SVG ให้คมทุกขนาดจอ
 */
(function () {
    'use strict';

    var box = document.getElementById('joinPresent');
    if (!box) return;

    // การ์ดเป็นกระจก (backdrop-filter) ซึ่งทำให้ position:fixed อิงกรอบการ์ดแทนจอ — ย้ายออกไปไว้ที่ body
    document.body.appendChild(box);

    var url = box.dataset.url;
    var qrBox = document.getElementById('joinPresentQr');
    var learn = box.querySelector('.join-present-learn');
    var drawn = false;

    // ลิงก์หน้าเรียนของนักเรียน (ตัด /join/รหัส ออก) ให้นักเรียนรู้ว่าไปกรอกรหัสที่ไหน
    if (learn) learn.textContent = 'ที่หน้า ' + url.replace(/\/join\/[^/]+$/, '/learn');

    function drawQr() {
        if (drawn) return;
        if (typeof window.qrcode !== 'function') {
            qrBox.innerHTML = '<div class="join-present-qr-err">โหลดตัวสร้าง QR Code ไม่ได้ (ต้องต่ออินเทอร์เน็ต) · ใช้ลิงก์หรือรหัสด้านล่างแทน</div>';
            return;
        }
        var qr = window.qrcode(0, 'M');
        qr.addData(url);
        qr.make();
        var n = qr.getModuleCount(), q = 4, size = n + q * 2, d = '';
        for (var r = 0; r < n; r++) {
            for (var c = 0; c < n; c++) {
                if (qr.isDark(r, c)) d += 'M' + (c + q) + ' ' + (r + q) + 'h1v1h-1z';
            }
        }
        qrBox.innerHTML = '<svg viewBox="0 0 ' + size + ' ' + size + '" shape-rendering="crispEdges" xmlns="http://www.w3.org/2000/svg">' +
            '<rect width="' + size + '" height="' + size + '" fill="#fff"/><path d="' + d + '" fill="#000"/></svg>';
        drawn = true;
    }

    function setMode(mode) {
        box.dataset.mode = mode;
        box.querySelectorAll('[data-view]').forEach(function (v) { v.hidden = v.dataset.view !== mode; });
        box.querySelectorAll('[data-mode]').forEach(function (b) {
            var on = b.dataset.mode === mode;
            b.classList.toggle('is-on', on);
            b.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        if (mode === 'qr') drawQr();
    }

    function open(mode) {
        setMode(mode);
        box.hidden = false;
        document.documentElement.classList.add('is-presenting');
        // ขอเต็มจอจริงถ้าเบราว์เซอร์ยอม · ไม่ยอมก็ยังเป็นแผ่นทับทั้งหน้าต่างอยู่ดี
        if (box.requestFullscreen && !document.fullscreenElement) {
            box.requestFullscreen().catch(function () { /* ใช้แบบทับหน้าต่างแทน */ });
        }
        box.querySelector('[data-present-close]').focus({ preventScroll: true });
    }

    function close() {
        if (document.fullscreenElement === box) document.exitFullscreen();
        box.hidden = true;
        document.documentElement.classList.remove('is-presenting');
    }

    document.querySelectorAll('[data-present]').forEach(function (b) {
        b.addEventListener('click', function () { open(b.dataset.present); });
    });
    box.querySelectorAll('[data-mode]').forEach(function (b) {
        b.addEventListener('click', function () { setMode(b.dataset.mode); });
    });
    box.querySelector('[data-present-close]').addEventListener('click', close);

    // ออกจากเต็มจอด้วย Esc ของเบราว์เซอร์ → ปิดแผ่นฉายไปด้วย
    document.addEventListener('fullscreenchange', function () {
        if (!document.fullscreenElement && !box.hidden) close();
    });
    document.addEventListener('keydown', function (e) {
        if (box.hidden) return;
        if (e.key === 'Escape') { e.preventDefault(); e.stopImmediatePropagation(); close(); }
        // สลับรหัส/QR ด้วยปุ่มลูกศรซ้าย/ขวาระหว่างฉาย
        if (e.key === 'ArrowLeft' || e.key === 'ArrowRight') setMode(box.dataset.mode === 'qr' ? 'code' : 'qr');
    }, true);
})();
