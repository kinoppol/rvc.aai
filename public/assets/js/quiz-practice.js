/*
 * ซ้อมสอบ (learn/practice.latte) — ข้อตกลงการสอบ ตรวจในเบราว์เซอร์ ไม่ส่งอะไรไปเซิร์ฟเวอร์
 * โหมดคุมสอบทำงานจาก quiz-proctor.js (data-practice="1")
 */
(function () {
    'use strict';
    var root = document.getElementById('take');
    if (!root) return;
    var qs = Array.prototype.slice.call(root.querySelectorAll('.take-q'));
    var total = qs.length, cur = 0, picked = {};
    var $ = function (id) { return document.getElementById(id); };
    var done = $('practiceDone'), foot = $('practiceFoot');
    var proctor = function () { return window.rvcProctor || {}; };

    function render() {
        qs.forEach(function (s, i) { s.hidden = i !== cur; });
        $('posText').textContent = 'ข้อ ' + (cur + 1) + ' จาก ' + total;
        $('progress').style.width = Math.round((cur + 1) / total * 100) + '%';
        $('prevBtn').disabled = cur === 0;
        $('nextBtn').textContent = cur === total - 1 ? 'ส่งคำตอบ (ซ้อม)' : 'ข้อถัดไป';
    }

    qs.forEach(function (sec, i) {
        sec.querySelectorAll('.take-choice').forEach(function (btn) {
            btn.addEventListener('click', function () {
                sec.querySelectorAll('.take-choice').forEach(function (b) { b.classList.remove('is-picked'); });
                btn.classList.add('is-picked');
                picked[i] = btn.dataset.choice;
            });
        });
    });

    function finish(timeUp) {
        var right = 0, auto = 0;
        qs.forEach(function (sec, i) {
            sec.hidden = false;
            var why = sec.querySelector('.practice-why');
            if (why) why.hidden = false;
            var ans = sec.dataset.answer;
            if (ans === '') return;
            auto++;
            sec.querySelectorAll('.take-choice').forEach(function (b) {
                b.disabled = true;
                if (b.dataset.choice === ans) b.classList.add('is-right');
                else if (b.classList.contains('is-picked')) b.classList.add('is-wrong');
            });
            if (picked[i] === ans) right++;
        });
        $('practiceScore').textContent = (timeUp ? (root.dataset.timeup || 'หมดเวลาแล้ว') + ' · ' : '')
            + 'ตอบถูก ' + right + ' จาก ' + auto + ' ข้อ (ข้อที่ตรวจอัตโนมัติ) · อ่านคำอธิบายใต้แต่ละข้อได้เลย';
        if (timeUp) $('practiceAgain').hidden = true;
        done.hidden = false;
        foot.hidden = true;
        proctor().active = false;
        proctor().leaving = true;   // ซ้อมจบแล้ว ไม่ต้องตรวจต่อ
        if (document.fullscreenElement && document.exitFullscreen) document.exitFullscreen().catch(function () {});
        done.scrollIntoView({ behavior: 'smooth' });
    }

    $('prevBtn').addEventListener('click', function () { if (cur > 0) { cur--; render(); } });
    $('nextBtn').addEventListener('click', function () {
        if (cur < total - 1) { cur++; render(); return; }
        finish(false);
    });
    $('practiceAgain').addEventListener('click', function () { proctor().leaving = true; location.reload(); });
    $('practiceBack').addEventListener('click', function () { proctor().leaving = true; });
    document.querySelectorAll('[data-leave]').forEach(function (a) {
        a.addEventListener('click', function () { proctor().leaving = true; });
    });

    // นับถอยหลังช่วงซ้อมที่ครูเปิดไว้ (ครูลองดูหน้านี้ = ไม่มีกำหนด)
    var until = +root.dataset.until || 0;
    if (until) {
        var clock = $('clock');
        (function tick() {
            var left = Math.max(0, until * 1000 - Date.now());
            var m = Math.floor(left / 60000), s = Math.floor(left % 60000 / 1000);
            clock.textContent = (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;
            if (left <= 0) { if (done.hidden) finish(true); return; }
            setTimeout(tick, 500);
        })();
    }

    render();
})();
