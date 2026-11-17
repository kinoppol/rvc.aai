/*
 * โหมดคุมสอบ (หน้า learn/take.latte) — มาตรการป้องปรามการใช้ AI/ตัวช่วยระหว่างทำข้อสอบ
 *
 * - เริ่มสอบด้วยปุ่มเดียวที่เปิดโหมดเต็มจอ (ถ้าเบราว์เซอร์ทำได้ · iPhone/iPad ทำไม่ได้ จึงตรวจการสลับแอปอย่างเดียว)
 * - นับ "สลับหน้าจอ" = สลับแท็บ/แอป ออกจากหน้าต่าง หรือออกจากเต็มจอ (หลายอีเวนต์ในช่วงสั้น ๆ นับครั้งเดียว)
 * - นับ "คัดลอก/จับภาพ" = คัดลอก/ตัดข้อความข้อสอบ ปุ่ม PrintScreen คีย์ลัดจับภาพ สั่งพิมพ์
 * - กันไว้แต่ไม่นับ: คลิกขวา วางข้อความ ลากวาง เลือกข้อความ เครื่องมือนักพัฒนา
 * - เซิร์ฟเวอร์เป็นคนนับและตัดสินว่าเกินกำหนดหรือยัง (StudentController::proctorEvent → ProctorRepository)
 *   เกิน 3 ครั้งในกลุ่มใดกลุ่มหนึ่ง = ระงับการสอบจนครูอนุมัติ หน้าที่ถูกระงับถามสถานะทุก 5 วินาที
 * - data-practice="1" (ซ้อมสอบ learn/practice.latte): นับและเตือนในเบราว์เซอร์อย่างเดียว ไม่ส่งอะไรไปเซิร์ฟเวอร์
 *   เกินกำหนดแสดงหน้าระงับให้ดูว่าเป็นอย่างไร แล้วเริ่มซ้อมใหม่ได้
 */
(function () {
    'use strict';
    var root = document.getElementById('take');
    if (!root || root.dataset.proctor !== '1') return;

    var practice = root.dataset.practice === '1';
    var base = root.dataset.base, attempt = root.dataset.attempt, token = root.dataset.token;
    var limit = +root.dataset.plimit || 3;
    var counts = { switch: +root.dataset.switches || 0, copy: +root.dataset.copies || 0 };
    var url = base + '/learn/attempts/' + attempt + '/proctor';
    var resultUrl = base + '/learn/attempts/' + attempt + '/result';

    var state = window.rvcProctor = { active: false, locked: root.dataset.locked === '1', leaving: false };
    var fsOK = !!(document.fullscreenEnabled || document.webkitFullscreenEnabled);
    var last = { switch: 0, copy: 0 };

    var $ = function (id) { return document.getElementById(id); };
    var gate = $('prGate'), warn = $('prWarn'), lock = $('prLock');

    // ---- ลายน้ำชื่อนักเรียน (ถ่ายรูปหน้าจอไปถามที่อื่นก็รู้ว่าเป็นของใคร) ----
    (function watermark() {
        var wm = $('prWatermark');
        if (!wm) return;
        var text = root.dataset.student || '';
        var html = '';
        for (var i = 0; i < 40; i++) html += '<span>' + text.replace(/[&<>"]/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
        }) + '</span>';
        wm.innerHTML = html;
    })();

    function isFull() { return !!(document.fullscreenElement || document.webkitFullscreenElement); }
    function enterFull() {
        if (!fsOK || isFull()) return Promise.resolve();
        var el = document.documentElement;
        try {
            var p = el.requestFullscreen ? el.requestFullscreen({ navigationUI: 'hide' }) : (el.webkitRequestFullscreen && el.webkitRequestFullscreen());
            return p && p.then ? p.catch(function () {}) : Promise.resolve();
        } catch (e) { return Promise.resolve(); }
    }

    function cover(on) { root.classList.toggle('pr-covered', on); }
    function showOnly(el) {
        [gate, warn, lock].forEach(function (x) { if (x) x.hidden = x !== el; });
        cover(!!el);
    }

    var toastTimer;
    function toast(msg) {
        var t = $('prToast');
        if (!t) return;
        t.textContent = msg;
        t.hidden = false;
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { t.hidden = true; }, 2600);
    }

    // ---- ระงับการสอบ ----
    var pollTimer;
    function showLock() {
        state.locked = true;
        state.active = false;
        showOnly(lock);
        if (practice) return;   // ซ้อม: ไม่มีครูต้องอนุมัติ กดเริ่มซ้อมใหม่เอง
        clearInterval(pollTimer);
        pollTimer = setInterval(poll, 5000);
    }
    function poll() {
        fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d.status && d.status !== 'in_progress') { state.leaving = true; location.href = resultUrl; return; }
                if (d.ok && !d.locked) { state.leaving = true; location.reload(); }
            })
            .catch(function () {});
    }

    // ---- รายงานเหตุการณ์ ----
    var WHAT = {
        hidden: 'สลับไปแท็บหรือแอปอื่น', blur: 'ออกจากหน้าต่างข้อสอบ', fullscreen: 'ออกจากโหมดเต็มจอ',
        copy: 'คัดลอกข้อความข้อสอบ', cut: 'ตัดข้อความข้อสอบ', printscreen: 'กดปุ่มจับภาพหน้าจอ',
        snip: 'ใช้คีย์ลัดจับภาพหน้าจอ', print: 'สั่งพิมพ์หรือบันทึกหน้าข้อสอบ'
    };

    function report(type, detail) {
        if (!state.active || state.locked || state.leaving) return;
        var counted = type === 'switch' || type === 'copy';
        if (counted) {
            var now = Date.now();
            if (now - last[type] < 2500) return;   // หลายอีเวนต์จากการกระทำครั้งเดียว
            last[type] = now;
            counts[type]++;
            showCounters();
            warnUser(type, detail, counts[type]);   // แสดงทันที ไม่รอเซิร์ฟเวอร์
        }
        if (practice) return;
        var body = new URLSearchParams({ _token: token, type: type, detail: detail });
        fetch(url, { method: 'POST', body: body, keepalive: true, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d || !d.ok) {
                    if (d && d.status && d.status !== 'in_progress') { state.leaving = true; location.href = resultUrl; }
                    return;
                }
                counts.switch = d.switches; counts.copy = d.copies;
                showCounters();
                if (d.locked) showLock();
            })
            .catch(function () {});
    }

    function showCounters() {
        var el = $('prCounters');
        if (el) el.textContent = 'สลับหน้าจอ ' + counts.switch + '/' + limit + ' · คัดลอก/จับภาพ ' + counts.copy + '/' + limit;
    }

    function warnUser(type, detail, n) {
        if (n > limit) { showLock(); return; }
        var left = limit - n;
        $('prWarnTitle').textContent = type === 'switch'
            ? 'ตรวจพบการสลับหน้าจอ ครั้งที่ ' + n + ' จาก ' + limit
            : 'ตรวจพบการคัดลอก/จับภาพหน้าจอ ครั้งที่ ' + n + ' จาก ' + limit;
        $('prWarnWhat').textContent = WHAT[detail] || '';
        $('prWarnLeft').textContent = left > 0
            ? 'ทำได้อีก ' + left + ' ครั้ง ถ้าเกินจากนี้ระบบจะระงับการสอบ และต้องให้ครูผู้สอนอนุมัติจึงจะสอบต่อได้'
            : 'ครั้งนี้เป็นครั้งสุดท้ายที่อนุญาต ถ้าเกิดขึ้นอีกระบบจะระงับการสอบทันที และต้องให้ครูผู้สอนอนุมัติจึงจะสอบต่อได้';
        $('prWarnBtn').textContent = fsOK ? 'รับทราบ กลับเข้าโหมดเต็มจอ' : 'รับทราบ ทำข้อสอบต่อ';
        state.active = false;   // ระหว่างอ่านคำเตือนไม่นับซ้ำ
        showOnly(warn);
        setTimeout(function () { if (!warn.hidden) state.active = true; }, 1200);
    }

    function resume() {
        enterFull().then(function () {
            showOnly(null);
            state.active = true;
            last.switch = Date.now();   // การกลับเข้าเต็มจอเองไม่นับเป็นการสลับ
        });
    }

    if (gate) $('prStartBtn').addEventListener('click', resume);
    if (warn) $('prWarnBtn').addEventListener('click', resume);
    if ($('prRestart')) $('prRestart').addEventListener('click', function () { state.leaving = true; location.reload(); });

    // ---- สลับหน้าจอ ----
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'hidden') report('switch', 'hidden');
    });
    window.addEventListener('blur', function () {
        root.classList.add('pr-veil');
        setTimeout(function () {
            if (document.visibilityState === 'visible' && !document.hasFocus()) report('switch', 'blur');
        }, 400);
    });
    window.addEventListener('focus', function () { root.classList.remove('pr-veil'); });
    ['fullscreenchange', 'webkitfullscreenchange'].forEach(function (ev) {
        document.addEventListener(ev, function () {
            if (!isFull() && state.active) report('switch', 'fullscreen');
        });
    });
    // ออกจากหน้าเอง (ส่งคำตอบ/ไปหน้าผล/รีโหลด) ไม่ใช่การสลับหน้าจอ
    window.addEventListener('beforeunload', function () { state.leaving = true; });
    var submitForm = $('submitForm');
    if (submitForm) submitForm.addEventListener('submit', function () { state.leaving = true; });

    // ---- คัดลอก / จับภาพ / พิมพ์ ----
    function inAnswer(t) { return !!(t && t.closest && t.closest('textarea, input')); }

    ['copy', 'cut'].forEach(function (ev) {
        document.addEventListener(ev, function (e) {
            if (inAnswer(e.target)) return;   // ข้อความที่นักเรียนพิมพ์เอง
            e.preventDefault();
            report('copy', ev);
        });
    });
    document.addEventListener('paste', function (e) {
        e.preventDefault();
        toast('ไม่อนุญาตให้วางข้อความระหว่างสอบ กรุณาพิมพ์คำตอบเอง');
        report('paste', 'paste');
    });
    document.addEventListener('drop', function (e) { e.preventDefault(); toast('ไม่อนุญาตให้ลากข้อความมาวาง'); });
    document.addEventListener('dragstart', function (e) { e.preventDefault(); });
    document.addEventListener('contextmenu', function (e) { e.preventDefault(); toast('ปิดการคลิกขวาระหว่างสอบ'); });
    document.addEventListener('selectstart', function (e) { if (!inAnswer(e.target)) e.preventDefault(); });

    document.addEventListener('keydown', function (e) {
        var k = (e.key || '').toLowerCase();
        var mod = e.ctrlKey || e.metaKey;
        if (k === 'printscreen') { e.preventDefault(); return; }   // นับตอนปล่อยปุ่ม (Windows ส่งแค่ keyup)
        if ((e.metaKey && e.shiftKey && (k === 's' || k === '3' || k === '4' || k === '5')) || (e.metaKey && k === 'printscreen')) {
            e.preventDefault(); report('copy', 'snip'); return;
        }
        if (mod && k === 'p') { e.preventDefault(); report('copy', 'print'); return; }
        if (k === 'f12' || (mod && e.shiftKey && (k === 'i' || k === 'j' || k === 'c')) || (mod && k === 'u')) {
            e.preventDefault(); toast('ปิดเครื่องมือนี้ระหว่างสอบ'); report('blocked', 'devtools'); return;
        }
        if (mod && k === 's') { e.preventDefault(); toast('ไม่อนุญาตให้บันทึกหน้าข้อสอบ'); return; }
        if (mod && k === 'a' && !inAnswer(e.target)) { e.preventDefault(); }
    }, true);
    document.addEventListener('keyup', function (e) {
        if ((e.key || '').toLowerCase() === 'printscreen') {
            report('copy', 'printscreen');
            // ล้างภาพที่เพิ่งจับไว้ในคลิปบอร์ด (ทำได้บางเบราว์เซอร์)
            try { navigator.clipboard && navigator.clipboard.writeText(' '); } catch (err) {}
        }
    }, true);
    window.addEventListener('beforeprint', function () { report('copy', 'print'); });

    // ---- คำตอบถูกปฏิเสธเพราะถูกระงับ (เช่น อีกแท็บหนึ่งทำให้ถูกระงับ) ----
    state.onLocked = showLock;

    // ---- เริ่มต้น ----
    if (state.locked) showLock();
    else showOnly(gate);
})();
