/* กล่องยืนยันกลางของระบบ — ดูคำอธิบายการใช้งานใน resources/views/_partials/confirm.latte */
(function () {
    'use strict';

    var modal, titleEl, textEl, markEl, okBtn, cancelBtn, pending = null, lastFocus = null;

    function ready() {
        modal = document.getElementById('confirmModal');
        if (!modal) { return false; }
        titleEl = document.getElementById('confirmTitle');
        textEl = document.getElementById('confirmText');
        markEl = document.getElementById('confirmMark');
        okBtn = document.getElementById('confirmOk');
        cancelBtn = document.getElementById('confirmCancel');

        return true;
    }

    function close(answer) {
        modal.hidden = true;
        var resolve = pending;
        pending = null;
        if (lastFocus && lastFocus.focus) { lastFocus.focus(); }
        if (resolve) { resolve(answer); }
    }

    /* alertOnly = แจ้งเตือนอย่างเดียว: ซ่อนปุ่มยกเลิก ปุ่มเดียวคือ "ตกลง" */
    function open(opts, alertOnly) {
        return new Promise(function (resolve) {
            if (!modal && !ready()) {
                if (alertOnly) {
                    window.alert(opts.message || '');
                    resolve(true);
                } else {
                    resolve(window.confirm(opts.message || 'ยืนยันการทำรายการ?'));
                }

                return;
            }

            /* มีกล่องค้างอยู่ — ถือว่ากล่องเดิมถูกยกเลิก */
            if (pending) { close(false); }

            pending = resolve;
            lastFocus = document.activeElement;
            titleEl.textContent = opts.title || (alertOnly ? 'แจ้งเตือน' : 'ยืนยันการทำรายการ');
            textEl.textContent = opts.message || '';
            okBtn.textContent = opts.confirmText || (alertOnly ? 'ตกลง' : 'ยืนยัน');
            markEl.textContent = opts.danger ? '!' : (alertOnly ? 'i' : '?');
            markEl.classList.toggle('is-danger', !!opts.danger);
            okBtn.classList.toggle('btn-danger', !!opts.danger && !alertOnly);
            okBtn.classList.toggle('btn-primary', !opts.danger || alertOnly);
            if (cancelBtn) { cancelBtn.hidden = !!alertOnly; }
            modal.hidden = false;
            okBtn.focus();
        });
    }

    /* rvcConfirm({message, title, confirmText, danger}) → Promise<boolean> */
    window.rvcConfirm = function (options) {
        return open(options || {}, false);
    };

    /* rvcAlert('ข้อความ') หรือ rvcAlert({message, title, danger}) → Promise ที่จบเมื่อปิดกล่อง */
    window.rvcAlert = function (options) {
        return open(typeof options === 'string' ? { message: options } : (options || {}), true);
    };

    document.addEventListener('click', function (event) {
        if (!modal) { return; }
        if (event.target === modal || event.target.closest('[data-confirm-cancel]')) { close(false); }
        if (event.target.closest('#confirmOk')) { close(true); }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && modal && !modal.hidden) { close(false); }
    });

    /* ฟอร์มที่ติด data-confirm ไว้ จะถูกถามก่อนส่งเสมอ */
    document.addEventListener('submit', function (event) {
        var form = event.target;
        var message = form.getAttribute && form.getAttribute('data-confirm');
        if (!message || form.dataset.confirmed === '1') { return; }

        event.preventDefault();
        window.rvcConfirm({
            message: message,
            title: form.getAttribute('data-confirm-title') || undefined,
            confirmText: form.getAttribute('data-confirm-ok') || undefined,
            danger: form.hasAttribute('data-confirm-danger')
        }).then(function (ok) {
            if (!ok) { return; }
            form.dataset.confirmed = '1';
            if (typeof form.requestSubmit === 'function') { form.requestSubmit(); } else { form.submit(); }
        });
    }, true);
})();

/* ---------- สถานะกำลังดำเนินการ ----------
   ใส่ data-busy="ข้อความ" ที่ <form> ที่ใช้เวลานาน (เรียก AI, ทดสอบการเชื่อมต่อ, นำเข้าไฟล์)
   ปุ่มจะกลายเป็นวงกลมหมุนพร้อมข้อความ กันกดซ้ำ และบอกผู้ใช้ว่าระบบกำลังทำงานอยู่ */
(function () {
    'use strict';

    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!form.getAttribute || !form.hasAttribute('data-busy')) { return; }

        /* ฟอร์มที่ต้องยืนยันก่อน จะยังไม่ถูกส่งจริงในรอบนี้ */
        if (form.hasAttribute('data-confirm') && form.dataset.confirmed !== '1') { return; }
        if (event.defaultPrevented) { return; }

        var button = form.querySelector('button[type="submit"], button:not([type])');
        if (!button || button.dataset.busyOn === '1') { return; }

        button.dataset.busyOn = '1';
        button.disabled = true;
        button.innerHTML = '<span class="spinner"></span>' + (form.getAttribute('data-busy') || 'กำลังดำเนินการ…');

        var note = form.querySelector('[data-busy-note]');
        if (note) { note.hidden = false; }
    });
})();

/* ---------- ปุ่ม (i) คำอธิบายย่อ ----------
   <span class="info-tip"><button class="info-btn" aria-expanded="false">…</button><span class="info-pop" hidden>…</span></span>
   กดเพื่อเปิด/ปิด · กดที่อื่นหรือ Esc เพื่อปิด */
(function () {
    'use strict';

    function closeAll(except) {
        document.querySelectorAll('.info-tip').forEach(function (tip) {
            if (tip === except) { return; }
            var pop = tip.querySelector('.info-pop');
            var btn = tip.querySelector('.info-btn');
            if (pop) { pop.hidden = true; }
            if (btn) { btn.setAttribute('aria-expanded', 'false'); }
        });
    }

    document.addEventListener('click', function (e) {
        var btn = e.target.closest ? e.target.closest('.info-btn') : null;
        if (!btn) {
            if (!e.target.closest || !e.target.closest('.info-pop')) { closeAll(null); }
            return;
        }
        var tip = btn.closest('.info-tip');
        var pop = tip && tip.querySelector('.info-pop');
        if (!pop) { return; }
        closeAll(tip);
        pop.hidden = !pop.hidden;
        btn.setAttribute('aria-expanded', pop.hidden ? 'false' : 'true');
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { closeAll(null); }
    });
})();
