// ตารางมาตรฐานอาชีพในฟอร์มรายวิชา (แท็บ "มาตรฐานอาชีพ"): เพิ่ม/ลบแถว และวางตารางที่คัดลอกจาก Word / Excel
(function () {
    'use strict';
    var body = document.getElementById('occRows');
    var tpl = document.getElementById('occRowTpl');
    if (!body || !tpl) return;
    var empty = document.getElementById('occEmpty');
    var COLS = ['os_uoc_code[]', 'os_uoc[]', 'os_eoc_code[]', 'os_eoc[]', 'os_pc[]', 'os_method[]'];

    function sync() {
        if (empty) empty.hidden = body.rows.length > 0;
    }

    function addRow(values) {
        var row = tpl.content.firstElementChild.cloneNode(true);
        if (values) {
            COLS.forEach(function (name, i) {
                var field = row.querySelector('[name="' + name + '"]');
                if (field) field.value = values[i] || '';
            });
        }
        body.appendChild(row);
        sync();
        return row;
    }

    // TSV แบบที่ Excel/Word คัดลอกออกมา: แท็บคั่นช่อง ขึ้นบรรทัดใหม่คั่นแถว ช่องที่มีหลายบรรทัดอยู่ใน "..."
    function parseTsv(text) {
        var rows = [], row = [], cell = '', quoted = false, i, ch;
        text = text.replace(/\r\n?/g, '\n');
        for (i = 0; i < text.length; i++) {
            ch = text[i];
            if (quoted) {
                if (ch === '"' && text[i + 1] === '"') { cell += '"'; i++; }
                else if (ch === '"') quoted = false;
                else cell += ch;
            } else if (ch === '"' && cell === '') {
                quoted = true;
            } else if (ch === '\t') {
                row.push(cell); cell = '';
            } else if (ch === '\n') {
                row.push(cell); rows.push(row); row = []; cell = '';
            } else {
                cell += ch;
            }
        }
        row.push(cell);
        rows.push(row);
        return rows
            .map(function (r) { return r.map(function (c) { return c.trim(); }); })
            .filter(function (r) { return r.join('') !== ''; });
    }

    document.getElementById('occAdd').addEventListener('click', function () {
        var row = addRow(null);
        var first = row.querySelector('input, textarea');
        if (first) first.focus();
    });

    body.addEventListener('click', function (e) {
        var btn = e.target.closest('.occ-del');
        if (!btn) return;
        btn.closest('tr').remove();
        sync();
    });

    var clear = document.getElementById('occClear');
    clear.addEventListener('click', function () {
        if (!body.rows.length) return;
        if (!window.confirm('ล้างแถวมาตรฐานอาชีพทั้งหมดใช่หรือไม่ (ยังไม่บันทึกจนกว่าจะกดบันทึก)')) return;
        while (body.rows.length) body.deleteRow(0);
        sync();
    });

    var pasteBox = document.getElementById('occPaste');
    var pasteText = document.getElementById('occPasteText');
    document.querySelector('[data-reveal-occ]').addEventListener('click', function () {
        pasteBox.hidden = !pasteBox.hidden;
        if (!pasteBox.hidden) pasteText.focus();
    });

    document.getElementById('occPasteApply').addEventListener('click', function () {
        var rows = parseTsv(pasteText.value);
        if (!rows.length) { pasteText.focus(); return; }
        var prev = ['', ''];
        var added = 0;
        rows.forEach(function (r) {
            // ข้ามแถวหัวตารางที่ติดมาด้วย
            if (/หน่วยสมรรถนะ|UoC|^รหัส$/i.test(r[0] || '') && /สมรรถนะย่อย|EoC|คำอธิบาย/i.test(r.join(' '))) return;
            while (r.length < 6) r.push('');
            if (r.length > 6) r = r.slice(0, 5).concat([r.slice(5).join(' ')]);
            // เซลล์ UoC ที่รวมไว้หลายแถวใน Word/Excel มาเป็นช่องว่าง → ใช้ค่าจากแถวก่อนหน้า
            if (r[0] === '' && r[1] === '') { r[0] = prev[0]; r[1] = prev[1]; }
            prev = [r[0], r[1]];
            addRow(r);
            added++;
        });
        if (added) {
            pasteText.value = '';
            pasteBox.hidden = true;
        }
    });

    sync();
})();
