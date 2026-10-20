/*
 * หน้าตรวจงานของครู (courses/submissions.latte) — สลับระหว่างกริดงานทั้งหมด (#all) กับงานของนักเรียนทีละคน (#s{studentId})
 * ใช้ hash ใน URL: ลิงก์ในรายชื่อ/การ์ด/ปุ่มก่อนหน้า-ถัดไปเป็น <a href="#…"> ธรรมดา และหลังบันทึกคะแนน
 * เซิร์ฟเวอร์พากลับมาที่ #s{studentId} เดิม · ปุ่ม Back ของเบราว์เซอร์ย้อนคนก่อนได้
 */
(function () {
    'use strict';

    var root = document.querySelector('[data-review]');
    if (!root) return;

    var panes = root.querySelectorAll('[data-pane]');
    var links = root.querySelectorAll('.rv-side [data-target]');
    var main = root.querySelector('.rv-main');

    function show() {
        var key = location.hash.replace('#', '') || 'all';
        var found = root.querySelector('[data-pane="' + key + '"]');
        if (!found) key = 'all';

        panes.forEach(function (p) { p.hidden = p.dataset.pane !== key; });
        links.forEach(function (a) {
            var on = a.dataset.target === key;
            a.classList.toggle('is-active', on);
            if (on) a.setAttribute('aria-current', 'true'); else a.removeAttribute('aria-current');
        });

        // จอแคบ: รายชื่ออยู่ด้านบน — เลื่อนลงไปที่งานให้เห็นทันที · จอกว้าง: ให้รายชื่อคนที่เลือกอยู่ในสายตา
        if (key !== 'all') {
            var active = root.querySelector('.rv-side .is-active');
            if (active && active.scrollIntoView) active.scrollIntoView({ block: 'nearest' });
            if (window.matchMedia('(max-width: 860px)').matches) main.scrollIntoView({ block: 'start' });
            else window.scrollTo({ top: Math.min(window.scrollY, root.offsetTop - 90) });
        }
    }

    window.addEventListener('hashchange', show);
    show();

    // ← / → เลื่อนไปคนก่อนหน้า/ถัดไป (เมื่อไม่ได้พิมพ์อยู่ในช่องกรอก)
    document.addEventListener('keydown', function (e) {
        if (e.target.closest('input, textarea, select') || e.altKey || e.ctrlKey || e.metaKey) return;
        var pane = root.querySelector('.rv-detail:not([hidden])');
        if (!pane) return;
        var nav = pane.querySelectorAll('.rv-detail-nav a');
        var label = e.key === 'ArrowLeft' ? 'คนก่อนหน้า' : e.key === 'ArrowRight' ? 'คนถัดไป' : '';
        if (!label) return;
        nav.forEach(function (a) { if (a.getAttribute('aria-label') === label) location.hash = a.getAttribute('href'); });
    });
})();
