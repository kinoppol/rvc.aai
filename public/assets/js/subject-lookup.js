// ฟอร์มรายวิชา: พิมพ์รหัส/ชื่อวิชา → ตัวเลือกจากคลังรายวิชา สอศ. · รหัสครบ → เติมชื่อ ท-ป-น คำอธิบาย จุดประสงค์ สมรรถนะ
(function () {
    'use strict';
    var code = document.getElementById('cCode');
    if (!code || !code.dataset.subjectLookup) return;
    var base = code.dataset.subjectLookup;
    var list = document.getElementById('cCodeList');
    var status = document.getElementById('cCodeStatus');
    var idle = status ? status.textContent : '';
    var timer = null;
    var lastQuery = '';
    var lastCode = code.value.trim(); // วิชาที่มีอยู่แล้ว: ไม่เติมทับตอนเปิดหน้า
    var CODE = /^[0-9A-Za-z]{2,6}([-*][0-9A-Za-z]{2,6})+$/;

    var fields = {
        name: document.getElementById('cName'),
        description: document.getElementById('cDesc'),
        objectives: document.getElementById('cObj'),
        competencies: document.getElementById('cComp')
    };
    var numbers = {
        theory: document.getElementById('cTheory'),
        practice: document.getElementById('cPractice'),
        credits: document.getElementById('cCredits')
    };

    function say(text, cls) {
        if (!status) return;
        status.hidden = false;
        status.className = 'hint' + (cls ? ' ' + cls : '');
        status.textContent = text;
    }

    function get(path) {
        return fetch(base + path, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then(function (r) { if (!r.ok) throw new Error(String(r.status)); return r.json(); });
    }

    // ตรวจว่ามีคลังรายวิชาหรือยัง (ก่อนรัน migration = ซ่อนคำแนะนำ ฟอร์มทำงานแบบเดิม)
    if (status) status.hidden = true;
    get('/search?q=').then(function (d) { if (d.ready && status) status.hidden = false; }).catch(function () {});

    function suggest() {
        var q = code.value.trim();
        if (q.length < 2 || q === lastQuery) return;
        lastQuery = q;
        get('/search?q=' + encodeURIComponent(q)).then(function (d) {
            if (!d.ready || !list) return;
            list.innerHTML = '';
            d.items.forEach(function (it) {
                var o = document.createElement('option');
                o.value = it.code;
                o.label = it.name + ' · ' + it.tpn + ' · หลักสูตร ' + it.year;
                o.textContent = o.label;
                list.appendChild(o);
            });
        }).catch(function () {});
    }

    function set(el, value) {
        if (!el || value === undefined || value === null || String(el.value) === String(value)) return;
        el.value = value;
        el.dispatchEvent(new Event('input', { bubbles: true }));
    }

    function apply(s, others) {
        var conflicts = Object.keys(fields).filter(function (k) {
            var el = fields[k];
            return el && s[k] && el.value.trim() !== '' && el.value.trim() !== String(s[k]).trim();
        });
        var overwrite = conflicts.length === 0
            || window.confirm('พบวิชา ' + s.code + ' ' + s.name + ' ในคลังรายวิชา สอศ.\n'
                + 'แทนที่ข้อมูลที่กรอกไว้แล้ว (ชื่อวิชา คำอธิบาย จุดประสงค์ สมรรถนะ ท-ป-น) หรือไม่?\n'
                + 'กด "ยกเลิก" = เติมเฉพาะช่องที่ยังว่าง');
        Object.keys(fields).forEach(function (k) {
            if (s[k] && (overwrite || fields[k].value.trim() === '')) set(fields[k], s[k]);
        });
        if (overwrite) {
            set(numbers.theory, s.theory);
            set(numbers.practice, s.practice);
            set(numbers.credits, Number(s.credits).toFixed(1));
        }
        var missing = ['description', 'objectives', 'competencies'].filter(function (k) { return !s[k]; });
        say('✓ เติมจากคลังรายวิชา สอศ. หลักสูตร ' + s.year + ' (' + s.theory + '-' + s.practice + '-' + Number(s.credits) + ')'
            + (missing.length ? ' · คลังยังไม่มี' + missing.map(function (k) {
                return { description: 'คำอธิบาย', objectives: 'จุดประสงค์', competencies: 'สมรรถนะ' }[k];
            }).join(' ') + ' กรอกเองได้' : '')
            + (overwrite ? '' : ' · เติมเฉพาะช่องที่ว่าง'), 'is-ok');
        if (others && others.length) {
            var span = document.createElement('span');
            span.textContent = ' · หลักสูตรอื่น: ';
            others.forEach(function (o, i) {
                var a = document.createElement('a');
                a.href = '#';
                a.textContent = String(o.year);
                a.addEventListener('click', function (e) { e.preventDefault(); apply(o, others.filter(function (x) { return x !== o; }).concat(s)); });
                if (i) span.appendChild(document.createTextNode(', '));
                span.appendChild(a);
            });
            status.appendChild(span);
        }
    }

    function lookup() {
        var c = code.value.trim();
        if (!CODE.test(c) || c === lastCode) return;
        lastCode = c;
        say('กำลังค้นรหัสวิชา ' + c + ' …');
        get('/lookup?code=' + encodeURIComponent(c)).then(function (d) {
            if (!d.ready) { if (status) status.hidden = true; return; }
            if (!d.subjects.length) { say('ไม่พบรหัส ' + c + ' ในคลังรายวิชา สอศ. — กรอกข้อมูลเองได้ตามปกติ'); return; }
            apply(d.subjects[0], d.subjects.slice(1));
        }).catch(function () { say(idle); });
    }

    code.addEventListener('input', function () {
        clearTimeout(timer);
        timer = setTimeout(function () { suggest(); if (list && list.querySelector('option[value="' + code.value.trim().replace(/"/g, '') + '"]')) lookup(); }, 250);
    });
    code.addEventListener('change', lookup);
})();
