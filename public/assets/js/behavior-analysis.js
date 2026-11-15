// ตารางวิเคราะห์พฤติกรรมการเรียนรู้ในฟอร์มรายวิชา (แท็บแผนการสอน): รวมร้อยละ และปุ่ม "ให้ AI วิเคราะห์"
(function () {
    'use strict';
    var box = document.getElementById('behavior');
    if (!box) return;
    var total = document.getElementById('bhTotal');
    var status = document.getElementById('bhStatus');
    var btn = document.getElementById('bhDraft');

    function sum() {
        if (!total) return;
        var n = 0;
        box.querySelectorAll('.bh-weight').forEach(function (i) { n += parseInt(i.value, 10) || 0; });
        total.textContent = n;
        total.classList.toggle('is-off', n !== 100);
        total.title = n === 100 ? 'ครบ 100 แล้ว' : 'ร้อยละรวมต้องเท่ากับ 100';
        markNeeded(n);
    }

    // ปุ่ม "ให้ AI วิเคราะห์" เด่นเมื่อตารางยังไม่ครบ (มีแถวที่ยังว่างบางช่อง หรือร้อยละรวมไม่ใช่ 100)
    function markNeeded(n) {
        var btn = document.getElementById('bhDraft');
        if (!btn) return;
        var incomplete = Array.prototype.some.call(box.querySelectorAll('tbody tr'), function (row) {
            return !row.querySelector('.bh-k input:checked')
                || Array.prototype.some.call(row.querySelectorAll('select, .bh-weight'), function (el) { return el.value === ''; });
        });
        btn.classList.toggle('is-needed', incomplete || n !== 100);
    }
    box.addEventListener('input', function (e) { if (e.target.classList.contains('bh-weight')) sum(); });
    box.addEventListener('change', sum);
    sum();

    if (!btn) return;
    var idle = status ? status.textContent : '';
    var val = function (id) { var el = document.getElementById(id); return el ? el.value : ''; };

    function filled() {
        return Array.prototype.some.call(box.querySelectorAll('tbody select, tbody .bh-weight'), function (el) { return el.value !== ''; })
            || box.querySelector('tbody input[type=checkbox]:checked') !== null;
    }

    function apply(data) {
        Object.keys(data.units || {}).forEach(function (id) {
            var row = box.querySelector('tr[data-unit="' + id + '"]');
            var d = data.units[id];
            if (!row) return;
            row.querySelectorAll('.bh-k input').forEach(function (c) { c.checked = d.k.indexOf(c.value) !== -1; });
            ['s', 'a', 'ap'].forEach(function (f) {
                var sel = row.querySelector('select[name$="[' + f + ']"]');
                if (sel) sel.value = d[f] || '';
            });
            var w = row.querySelector('.bh-weight');
            if (w) w.value = d.weight === null || d.weight === undefined ? '' : d.weight;
            row.classList.remove('bh-new');
            void row.offsetWidth;
            row.classList.add('bh-new');
        });
        var ow = box.querySelector('input[name="bh_outcome_weight"]');
        if (ow && data.outcome_weight !== undefined) ow.value = data.outcome_weight;
        sum();
    }

    btn.addEventListener('click', function () {
        if (filled() && !window.confirm('แทนที่ค่าในตารางด้วยผลวิเคราะห์ใหม่จาก AI หรือไม่')) return;
        var body = new FormData();
        body.append('_token', btn.dataset.token);
        // ค่าที่ยังไม่บันทึกบนฟอร์ม — ให้ AI วิเคราะห์จากข้อมูลล่าสุด
        body.append('description', val('cDesc'));
        body.append('objectives', val('cObj'));
        body.append('competencies', val('cComp'));
        body.append('learning_outcomes', val('cClo'));

        btn.disabled = true;
        status.className = 'hint';
        status.textContent = 'ผู้ช่วยกำลังวิเคราะห์ทุกหน่วย… ปกติใช้เวลา 20–60 วินาที ';
        if (window.rvcAiModel) { var chip = document.createElement('span'); chip.className = 'ai-model-chip'; status.appendChild(chip); window.rvcAiModel(chip, 'behavior_analysis', ''); }

        fetch(btn.dataset.url, { method: 'POST', body: body, credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'เซิร์ฟเวอร์ตอบกลับไม่ถูกต้อง (' + r.status + ')' }; }); })
            .then(function (d) {
                if (!d.ok) throw new Error(d.error || 'ผู้ช่วยวิเคราะห์ไม่สำเร็จ');
                apply(d);
                status.textContent = 'วิเคราะห์เสร็จแล้ว' + (d.missing > 0 ? ' (ยังขาด ' + d.missing + ' หน่วย เติมเองได้)' : '')
                    + ' · ตรวจแก้ได้ แล้วกด “บันทึกการแก้ไข”';
            })
            .catch(function (e) {
                status.className = 'hint is-err';
                status.textContent = e.message === 'Failed to fetch' ? 'เชื่อมต่อเซิร์ฟเวอร์ไม่ได้ กรุณาลองใหม่' : e.message;
            })
            .then(function () {
                btn.disabled = false;
                setTimeout(function () { if (status.className === 'hint' && status.textContent.indexOf('กำลัง') === -1) status.textContent = idle; }, 20000);
            });
    });
})();
