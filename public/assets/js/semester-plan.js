/*
 * ตัวอย่างตารางภาคเรียนบนฟอร์ม "ออกแบบหน่วยการเรียนด้วย AI"
 * คำนวณแบบเดียวกับ App\Support\Semester::plan() — แก้ฝั่งใดต้องแก้อีกฝั่งด้วย
 */
(function () {
    'use strict';

    var box = document.getElementById('semesterBox');
    if (!box) return;

    var weeksIn = document.getElementById('weeks');
    var countIn = document.getElementById('count');
    var mid = document.getElementById('midterm');
    var fin = document.getElementById('final');
    var strip = document.getElementById('semesterStrip');
    var summary = document.getElementById('semesterSummary');

    var teaching = parseInt(box.dataset.teaching, 10) || 18;
    var included = box.dataset.included === '1';
    var existing = parseInt(box.dataset.existing, 10) || 0;
    var weekly = parseInt(box.dataset.weekly, 10) || 0;
    // ปวส.: ครูยังไม่ได้พิมพ์จำนวนสัปดาห์เอง → ปรับตามจำนวนครั้งที่สอบ (15 + สัปดาห์สอบ)
    var auto = box.dataset.auto === '1';

    function spread(weeks, count) {
        var out = [];
        if (count <= 0) return out;
        weeks = Math.max(0, weeks);
        var base = Math.floor(weeks / count), extra = weeks % count;
        for (var i = 0; i < count; i++) out.push(base + (i < extra ? 1 : 0));
        return out;
    }

    function midtermAfter(list, total) {
        var n = list.length;
        if (n <= 1) return n;
        var target = Math.floor((total + 1) / 2), best = 1, gap = Infinity, cum = 0;
        for (var k = 1; k < n; k++) {
            cum += list[k - 1];
            var g = Math.abs(cum + 1 - target);
            if (g < gap) { best = k; gap = g; }
        }
        return best;
    }

    function cell(cls, text, title) {
        var el = document.createElement('span');
        el.className = 'wk ' + cls;
        el.textContent = text;
        el.title = title;
        return el;
    }

    function draw() {
        if (mid.checked) fin.checked = true;
        var exams = (mid.checked ? 1 : 0) + (fin.checked ? 1 : 0);
        if (auto && !included) weeksIn.value = teaching + exams;

        var weeks = Math.max(1, Math.min(30, parseInt(weeksIn.value, 10) || 1));
        var room = weeks - exams - existing;
        countIn.max = String(Math.max(1, Math.min(20, room)));
        var count = Math.max(1, Math.min(parseInt(countIn.value, 10) || 1, Math.max(1, room)));

        var list = spread(weeks - exams, existing + count);
        var after = mid.checked ? midtermAfter(list, weeks) : null;

        strip.textContent = '';
        list.forEach(function (w, i) {
            if (after !== null && i === after) strip.appendChild(cell('wk-exam', 'กลาง', 'สอบกลางภาค'));
            for (var j = 0; j < w; j++) {
                strip.appendChild(cell(i < existing ? 'wk-old' : (i % 2 ? 'wk-b' : 'wk-a'), String(i + 1),
                    'หน่วยที่ ' + (i + 1) + (i < existing ? ' (หน่วยเดิม)' : '')));
            }
        });
        if (after !== null && after >= list.length) strip.appendChild(cell('wk-exam', 'กลาง', 'สอบกลางภาค'));
        if (fin.checked) strip.appendChild(cell('wk-exam', 'ปลาย', 'สอบปลายภาค'));

        var parts = [];
        if (room < 1) {
            parts.push('สัปดาห์ไม่พอสำหรับหน่วยใหม่ — เพิ่มจำนวนสัปดาห์หรือลดการสอบ');
        } else {
            var mn = Math.min.apply(null, list), mx = Math.max.apply(null, list);
            parts.push('หน่วยละ ' + (mn === mx ? mn : mn + '–' + mx) + ' สัปดาห์'
                + (weekly ? ' (' + (mn === mx ? mn * weekly : mn * weekly + '–' + mx * weekly) + ' ชั่วโมง)' : ''));
            if (after !== null) {
                var midWeek = 1;
                for (var k = 0; k < after; k++) midWeek += list[k];
                parts.push('สอบกลางภาคสัปดาห์ที่ ' + midWeek + ' (หลังหน่วยที่ ' + after + ')');
            }
            if (fin.checked) parts.push('สอบปลายภาคสัปดาห์ที่ ' + weeks);
            if (parseInt(countIn.value, 10) > room) parts.push('จำนวนหน่วยลดเหลือ ' + room + ' ตามสัปดาห์ที่มี');
        }
        summary.textContent = parts.join(' · ');
    }

    mid.addEventListener('change', draw);
    fin.addEventListener('change', function () {
        if (!fin.checked) mid.checked = false; // ไม่มีสอบปลายภาคก็มีสอบกลางภาคไม่ได้
        draw();
    });
    weeksIn.addEventListener('input', function () { auto = false; draw(); });
    countIn.addEventListener('input', draw);
    draw();
})();
