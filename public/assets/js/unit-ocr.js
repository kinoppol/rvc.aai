/* ข้อความของ PDF สแกนในหน้าแก้ไขหน่วย — ให้ AI อ่าน (OCR) แล้วแสดงความคืบหน้าที่แถวของไฟล์
 *
 * แถวที่ data-state="pending" ถูกสั่งอ่านทันทีที่เปิดหน้า (POST …/ocr ซึ่งรอจนอ่านจบ อาจหลายนาที)
 * ระหว่างนั้นถาม GET …/text ทุก 4 วินาทีเพื่อแสดง "หน้า x/y" — ถ้าปิดหน้าไป เซิร์ฟเวอร์ก็อ่านต่อจนจบ
 * แถวที่ "running" อยู่แล้ว (อีกแท็บหรือ bin/unit-ocr.php กำลังอ่าน) แค่ถามสถานะจนเสร็จ
 * ทีละไฟล์ — อ่านพร้อมกันหลายไฟล์จะกินโควตาและคิวของเครื่อง AI ส่วนกลาง
 */
(function () {
    'use strict';

    var rows = Array.prototype.slice.call(document.querySelectorAll('[data-ocr-section]'));
    if (!rows.length) { return; }
    var csrf = (document.querySelector('input[name="_token"]') || {}).value || '';
    var queue = [];

    function fmt(n) { return Number(n || 0).toLocaleString('th-TH'); }

    function show(row, s) {
        var chip = row.querySelector('[data-ocr-chip]');
        var msg = row.querySelector('[data-ocr-msg]');
        var retry = row.querySelector('[data-ocr-retry]');
        row.setAttribute('data-state', s.state);
        chip.className = 'chip ' + (s.state === 'ready' ? 'chip-ok' : (s.state === 'failed' ? 'chip-err' : 'chip-warn'));
        chip.textContent = s.state === 'ready' ? 'AI ใช้ได้ · ' + fmt(s.chars) + ' ตัวอักษร'
            : s.state === 'running' ? 'กำลังให้ AI อ่านไฟล์สแกน…' + (s.pages ? ' หน้า ' + s.page + '/' + s.pages : '')
            : s.state === 'pending' ? 'ไฟล์สแกน · รอ AI อ่าน (OCR)'
            : 'AI อ่านไฟล์นี้ไม่ได้';
        msg.textContent = s.state === 'failed' ? (s.message || s.error || '') : '';
        retry.hidden = s.state !== 'failed';
    }

    function status(row) {
        return fetch(row.getAttribute('data-status-url'), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then(function (r) { return r.json(); });
    }

    /* ถามสถานะจนกว่าจะไม่ใช่ running แล้วคืนสถานะสุดท้าย */
    function watch(row) {
        return new Promise(function (done) {
            (function tick() {
                status(row).then(function (s) {
                    show(row, s);
                    if (s.state === 'running') { setTimeout(tick, 4000); } else { done(s); }
                }).catch(function () { setTimeout(tick, 8000); });
            })();
        });
    }

    function read(row, retry) {
        show(row, { state: 'running', page: 0, pages: 0 });
        var body = new URLSearchParams();
        body.set('_token', csrf);
        if (retry) { body.set('retry', '1'); }

        var finished = false;
        var poll = setTimeout(function tick() {
            if (finished) { return; }
            status(row).then(function (s) {
                if (!finished && s.state === 'running') { show(row, s); poll = setTimeout(tick, 4000); }
            }).catch(function () {});
        }, 4000);

        return fetch(row.getAttribute('data-ocr-url'), { method: 'POST', credentials: 'same-origin', body: body, headers: { Accept: 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (s) {
                finished = true;
                clearTimeout(poll);
                if (s.state === 'running') { return watch(row); }   // อีกคำขอถือล็อกอยู่ — รอให้อันนั้นเสร็จ
                show(row, s.ok === false && !s.state ? { state: 'failed', message: s.error } : s);
                return s;
            })
            .catch(function () {
                // การเชื่อมต่อหลุด (เช่น เกตเวย์ตัดคำขอที่นาน) — เซิร์ฟเวอร์ยังอ่านต่ออยู่ จึงถามสถานะแทน
                finished = true;
                clearTimeout(poll);
                return watch(row);
            });
    }

    function next() {
        var job = queue.shift();
        if (job) { job().then(next, next); }
    }

    rows.forEach(function (row) {
        var state = row.getAttribute('data-state');
        if (state === 'pending') { queue.push(function () { return read(row, false); }); }
        if (state === 'running') { queue.push(function () { return watch(row); }); }
        row.querySelector('[data-ocr-retry]').addEventListener('click', function () {
            read(row, true);
        });
    });
    next();
})();
