/*
 * ปุ่ม "ให้ AI สรุปผลลัพธ์การเรียนรู้ระดับรายวิชา" บนฟอร์มรายวิชา (courses/edit.latte)
 * ส่งค่าที่กรอกอยู่บนฟอร์ม (ยังไม่ต้องบันทึก) ไป POST /courses/draft-outcomes แล้วเติมผลลงช่อง
 */
(function () {
    'use strict';

    var btn = document.getElementById('cloDraft');
    var area = document.getElementById('cClo');
    var status = document.getElementById('cloStatus');
    if (!btn || !area) return;

    var idle = status.textContent;
    var val = function (id) { var el = document.getElementById(id); return el ? el.value : ''; };

    btn.addEventListener('click', function () {
        if (area.value.trim() !== '' && !window.confirm('แทนที่ผลลัพธ์การเรียนรู้ที่กรอกไว้ด้วยฉบับที่ผู้ช่วยร่างใหม่หรือไม่')) return;

        var m = location.pathname.match(/\/courses\/(\d+)\/edit/);
        var body = new FormData();
        body.append('_token', btn.dataset.token);
        body.append('course_id', m ? m[1] : '0');
        body.append('code', val('cCode'));
        body.append('name', val('cName'));
        body.append('description', val('cDesc'));
        body.append('objectives', val('cObj'));
        body.append('competencies', val('cComp'));

        btn.disabled = true;
        status.className = 'hint';
        status.textContent = 'ผู้ช่วยกำลังสรุป… ปกติใช้เวลา 10–40 วินาที';

        fetch(btn.dataset.url, { method: 'POST', body: body, credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'เซิร์ฟเวอร์ตอบกลับไม่ถูกต้อง (' + r.status + ')' }; }); })
            .then(function (d) {
                if (!d.ok) throw new Error(d.error || 'ผู้ช่วยสรุปไม่สำเร็จ');
                area.value = d.text;
                area.dispatchEvent(new Event('input', { bubbles: true }));
                status.textContent = 'ร่างเสร็จแล้ว · ตรวจและแก้ไขได้ แล้วกดบันทึกรายวิชา';
                area.focus();
            })
            .catch(function (e) {
                status.className = 'hint is-err';
                status.textContent = e.message === 'Failed to fetch' ? 'เชื่อมต่อเซิร์ฟเวอร์ไม่ได้ กรุณาลองใหม่' : e.message;
            })
            .then(function () {
                btn.disabled = false;
                setTimeout(function () { if (status.textContent.indexOf('กำลัง') === -1 && status.className === 'hint') status.textContent = idle; }, 15000);
            });
    });
})();
