/*
 * ปุ่ม ✦ ของหน้าแก้ไขหน่วย (courses/unit-edit.latte) ให้ผู้ช่วย AI ร่างข้อมูลแผนการสอนของหน่วย
 *   data-unit-plan-ai="outcome"  → ผลลัพธ์การเรียนรู้ระดับหน่วย (เติมช่อง learning_outcome)
 *   data-unit-plan-ai="standard" → หน่วยสมรรถนะ/สมรรถนะย่อย/บูรณาการกลุ่มอาชีพ (std_uoc, std_eoc, std_career)
 * ส่งค่าที่กรอกอยู่บนฟอร์ม (ยังไม่ต้องบันทึก) ไป POST …/draft-plan แล้วเติมผลลงช่อง — ครูแก้แล้วกดบันทึกเอง
 */
(function () {
    'use strict';

    var form = document.getElementById('unitForm');
    if (!form) return;
    var val = function (name) { var el = form.elements[name]; return el ? el.value : ''; };
    var set = function (name, v) {
        var el = form.elements[name];
        if (!el) return;
        el.value = v || '';
        el.dispatchEvent(new Event('input', { bubbles: true }));
    };

    document.querySelectorAll('[data-unit-plan-ai]').forEach(function (btn) {
        var what = btn.getAttribute('data-unit-plan-ai');
        var status = btn.parentNode.querySelector('[data-status]');
        var idle = status ? status.textContent : '';
        var targets = what === 'outcome' ? ['learning_outcome'] : ['std_uoc', 'std_eoc'];

        // ปุ่มเด่น (is-needed) เฉพาะตอนที่ช่องยังว่าง
        var mark = function () {
            btn.classList.toggle('is-needed', targets.every(function (n) { return val(n).trim() === ''; }));
        };
        targets.forEach(function (n) { if (form.elements[n]) form.elements[n].addEventListener('input', mark); });

        btn.addEventListener('click', function () {
            var filled = targets.some(function (n) { return val(n).trim() !== ''; });
            if (filled && !window.confirm('แทนที่ข้อความที่กรอกไว้ด้วยฉบับที่ผู้ช่วยร่างใหม่หรือไม่')) return;

            var body = new FormData();
            body.append('_token', btn.dataset.token);
            body.append('what', what);
            ['title', 'key_content', 'objectives', 'competencies', 'learning_outcome'].forEach(function (n) { body.append(n, val(n)); });

            btn.disabled = true;
            if (status) {
                status.className = 'hint is-working';
                status.textContent = 'ผู้ช่วยกำลังร่าง… ปกติใช้เวลา 10–40 วินาที ';
                if (window.rvcAiModel) {
                    var chip = document.createElement('span');
                    chip.className = 'ai-model-chip';
                    status.appendChild(chip);
                    window.rvcAiModel(chip, what === 'outcome' ? 'unit_outcomes' : 'unit_standard', '');
                }
            }

            fetch(btn.dataset.url, { method: 'POST', body: body, credentials: 'same-origin', headers: { Accept: 'application/json' } })
                .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'เซิร์ฟเวอร์ตอบกลับไม่ถูกต้อง (' + r.status + ')' }; }); })
                .then(function (d) {
                    if (!d.ok) throw new Error(d.error || 'ผู้ช่วยร่างไม่สำเร็จ');
                    if (what === 'outcome') {
                        set('learning_outcome', d.text);
                    } else {
                        set('std_uoc', d.uoc);
                        set('std_eoc', d.eoc);
                        if (d.career) set('std_career', d.career);
                    }
                    if (status) status.className = 'hint';
                    if (status) status.textContent = 'ร่างเสร็จแล้ว · ตรวจและแก้ไขได้ แล้วกดบันทึกฉบับร่าง';
                })
                .catch(function (e) {
                    if (!status) return;
                    status.className = 'hint is-err';
                    status.textContent = e.message === 'Failed to fetch' ? 'เชื่อมต่อเซิร์ฟเวอร์ไม่ได้ กรุณาลองใหม่' : e.message;
                })
                .then(function () {
                    btn.disabled = false;
                    mark();
                    setTimeout(function () {
                        if (status && status.textContent.indexOf('กำลัง') === -1 && status.className === 'hint') status.textContent = idle;
                    }, 15000);
                });
        });
    });
})();
