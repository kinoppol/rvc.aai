/* แท็บในฟอร์มยาว (เช่น แก้ไขรายวิชา) — สลับฝั่งเบราว์เซอร์อย่างเดียว ทุกช่องยังอยู่ในฟอร์มเดียว
   แถบแท็บ: [data-form-tabs] ที่มีปุ่ม [data-tab="key"] · เนื้อหา: [data-panel="key"]
   [data-tabs-hide="key key"] = ซ่อนเมื่อแท็บนั้นเปิดอยู่ (เช่น ปุ่มบันทึกในแท็บเก็บเข้าคลังซึ่งเป็นฟอร์มแยก) */
(function () {
    'use strict';

    var bar = document.querySelector('[data-form-tabs]');
    if (!bar) { return; }

    var tabs = Array.prototype.slice.call(bar.querySelectorAll('[data-tab]'));
    var panels = Array.prototype.slice.call(document.querySelectorAll('[data-panel]'));
    var hiders = Array.prototype.slice.call(document.querySelectorAll('[data-tabs-hide]'));
    var keys = tabs.map(function (t) { return t.getAttribute('data-tab'); });
    var storeKey = 'rvc.formtab:' + location.pathname;

    function show(key, focus) {
        if (keys.indexOf(key) === -1) { key = keys[0]; }
        tabs.forEach(function (t) {
            var on = t.getAttribute('data-tab') === key;
            t.classList.toggle('is-active', on);
            t.setAttribute('aria-selected', on ? 'true' : 'false');
            t.tabIndex = on ? 0 : -1;
            if (on && focus) { t.focus(); }
        });
        panels.forEach(function (p) { p.hidden = p.getAttribute('data-panel') !== key; });
        hiders.forEach(function (el) {
            el.hidden = (' ' + el.getAttribute('data-tabs-hide') + ' ').indexOf(' ' + key + ' ') !== -1;
        });
        if (history.replaceState) { history.replaceState(null, '', '#' + key); }
    }

    tabs.forEach(function (t) {
        t.addEventListener('click', function () { show(t.getAttribute('data-tab')); });
    });

    // ลูกศรซ้าย/ขวาเลื่อนแท็บตามแบบ tablist
    bar.addEventListener('keydown', function (e) {
        if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') { return; }
        var i = tabs.indexOf(document.activeElement);
        if (i === -1) { return; }
        e.preventDefault();
        var next = (i + (e.key === 'ArrowRight' ? 1 : tabs.length - 1)) % tabs.length;
        show(keys[next], true);
    });

    // ช่องบังคับกรอกที่อยู่ในแท็บที่ซ่อน — เปิดแท็บนั้นก่อน เบราว์เซอร์จึงชี้ช่องที่ผิดให้เห็นได้
    document.addEventListener('invalid', function (e) {
        var panel = e.target.closest && e.target.closest('[data-panel]');
        if (panel && panel.hidden) { show(panel.getAttribute('data-panel')); }
    }, true);

    // ส่งฟอร์มแล้วถูกส่งกลับมาหน้าเดิม (เช่น รหัสวิชาซ้ำ) — กลับมาที่แท็บเดิม
    document.addEventListener('submit', function () {
        var active = bar.querySelector('.tab.is-active');
        try { sessionStorage.setItem(storeKey, active ? active.getAttribute('data-tab') : ''); } catch (err) { /* ไม่มีที่เก็บก็เปิดแท็บแรก */ }
    });

    var start = location.hash.slice(1);
    if (!start) {
        try {
            start = sessionStorage.getItem(storeKey) || '';
            sessionStorage.removeItem(storeKey);
        } catch (err) { start = ''; }
    }
    show(start || keys[0]);
})();
