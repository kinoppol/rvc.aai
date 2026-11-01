/*
 * ตัวแก้ไขแบบทดสอบ (quiz/review.latte): ครูเพิ่ม/ลบ/สลับข้อ แก้คำถาม ตัวเลือก เฉลย และคะแนนได้เอง
 * ข้อมูลเริ่มต้นมาจาก data-editor · ตอนบันทึกส่งข้อสอบทั้งชุดเป็น JSON (payload + sync=1)
 * ฝั่งเซิร์ฟเวอร์: QuizWizardController::save() → QuizRepository::syncQuestions()
 */
(function () {
    'use strict';

    var root = document.getElementById('reviewRoot');
    if (!root) return;

    var data = JSON.parse(root.dataset.editor || '{}');
    var labels = data.labels || ['ก', 'ข', 'ค', 'ง', 'จ', 'ฉ', 'ช', 'ซ'];
    var questions = data.questions || [];
    var list = document.getElementById('questionList');
    var canRegen = root.dataset.regen === '1';
    var dirty = false;
    var TYPE_NAMES = { choice: 'ปรนัย', short_answer: 'อัตนัย', matching: 'จับคู่', multi_choice: 'ปรนัยหลายคำตอบ' };
    var EDITABLE = ['choice', 'short_answer'];

    function el(tag, cls, text) {
        var e = document.createElement(tag);
        if (cls) e.className = cls;
        if (text != null) e.textContent = text;
        return e;
    }
    function icon(name) {
        var s = el('span', 'msi', name);
        s.setAttribute('aria-hidden', 'true');
        return s;
    }
    function iconBtn(name, title, cls) {
        var b = el('button', 'btn btn-sm btn-icon ' + (cls || ''));
        b.type = 'button';
        b.title = title;
        b.setAttribute('aria-label', title);
        b.appendChild(icon(name));
        return b;
    }
    function changed() { dirty = true; updateFoot(); }

    function blankChoices() {
        return [0, 1, 2, 3].map(function () { return { id: 0, content: '', is_correct: false }; });
    }

    /* ปัญหาของข้อหนึ่ง (แสดงใต้ข้อ และกันไม่ให้เผยแพร่) */
    function problemOf(q) {
        if (!q.question.trim()) return 'ยังไม่มีคำถาม';
        if (q.type === 'choice') {
            var filled = q.choices.filter(function (c) { return c.content.trim() !== ''; });
            var correct = filled.filter(function (c) { return c.is_correct; }).length;
            if (filled.length < 2) return 'ต้องมีตัวเลือกอย่างน้อย 2 ข้อ';
            if (correct === 0) return 'ยังไม่ได้เลือกเฉลย';
            if (correct > 1) return 'เลือกเฉลยได้ข้อเดียว';
        }
        return '';
    }

    function textarea(value, placeholder, rows, onInput) {
        var t = el('textarea', 'input qe-area');
        t.rows = rows;
        t.placeholder = placeholder;
        t.value = value || '';
        t.addEventListener('input', function () { onInput(t.value); changed(); });
        return t;
    }

    function renderCard(q, i) {
        var card = el('div', 'q-card qe-card');
        var problemBox = el('div', 'qe-problem');
        function refreshProblem() {
            var p = problemOf(q);
            problemBox.textContent = p ? '⚠ ' + p : '';
            problemBox.hidden = !p;
        }

        /* แถวบน: เลขข้อ ชนิด คะแนน ปุ่ม */
        var top = el('div', 'q-card-top');
        top.appendChild(el('span', 'mono q-no', 'ข้อ ' + (i + 1)));
        if (EDITABLE.indexOf(q.type) >= 0) {
            var sel = el('select', 'input qe-type');
            EDITABLE.forEach(function (t) {
                var o = el('option', null, TYPE_NAMES[t]);
                o.value = t;
                o.selected = q.type === t;
                sel.appendChild(o);
            });
            sel.setAttribute('aria-label', 'ชนิดข้อสอบ');
            sel.addEventListener('change', function () {
                q.type = sel.value;
                if (q.type === 'choice' && q.choices.length < 2) q.choices = blankChoices();
                changed();
                render();
            });
            top.appendChild(sel);
        } else {
            top.appendChild(el('span', 'tag', TYPE_NAMES[q.type] || q.type));
        }

        var scoreWrap = el('label', 'qe-score');
        scoreWrap.appendChild(document.createTextNode('คะแนน'));
        var score = el('input', 'input');
        score.type = 'number';
        score.min = '0';
        score.max = '100';
        score.step = '0.5';
        score.value = q.score;
        score.addEventListener('input', function () { q.score = parseFloat(score.value) || 0; changed(); });
        scoreWrap.appendChild(score);
        top.appendChild(scoreWrap);
        top.appendChild(el('div', 'grow'));

        var up = iconBtn('arrow_upward', 'เลื่อนขึ้น');
        up.disabled = i === 0;
        up.addEventListener('click', function () { move(i, -1); });
        var down = iconBtn('arrow_downward', 'เลื่อนลง');
        down.disabled = i === questions.length - 1;
        down.addEventListener('click', function () { move(i, 1); });
        top.appendChild(up);
        top.appendChild(down);

        if (canRegen && q.id > 0) {
            var regen = el('button', 'btn btn-sm', 'ให้ AI ร่างข้อนี้ใหม่');
            regen.type = 'button';
            regen.addEventListener('click', function () { regenerate(q, regen); });
            top.appendChild(regen);
        }
        var del = iconBtn('delete', 'ลบข้อนี้', 'btn-ghost-danger');
        del.addEventListener('click', function () {
            rvcConfirm({ title: 'ลบข้อนี้', message: 'ลบข้อ ' + (i + 1) + ' ออกจากแบบทดสอบใช่หรือไม่ (มีผลเมื่อกดบันทึก)', confirmText: 'ลบข้อนี้', danger: true })
                .then(function (ok) {
                    if (!ok) return;
                    questions.splice(i, 1);
                    changed();
                    render();
                });
        });
        top.appendChild(del);
        card.appendChild(top);

        /* คำถาม */
        card.appendChild(textarea(q.question, 'พิมพ์คำถาม', 2, function (v) { q.question = v; refreshProblem(); }));

        /* ตัวเลือก + เฉลย */
        if (q.type !== 'short_answer') {
            var wrap = el('div', 'q-choices');
            var matching = q.type === 'matching';
            q.choices.forEach(function (c, ci) {
                var row = el('div', 'q-choice qe-choice' + (c.is_correct ? ' is-correct' : ''));
                if (!matching) {
                    var pick = el('label', 'qe-pick');
                    pick.title = 'เลือกเป็นเฉลย';
                    var radio = el('input');
                    radio.type = 'radio';
                    radio.name = 'correct-' + i;
                    radio.checked = !!c.is_correct;
                    radio.setAttribute('aria-label', 'เฉลยข้อ ' + labels[ci]);
                    radio.addEventListener('change', function () {
                        q.choices.forEach(function (x) { x.is_correct = false; });
                        c.is_correct = true;
                        changed();
                        render();
                    });
                    pick.appendChild(radio);
                    wrap.appendChild(row);
                    row.appendChild(pick);
                } else {
                    wrap.appendChild(row);
                }
                row.appendChild(el('span', 'mono q-choice-key', labels[ci] || ''));
                var input = el('input', 'input qe-choice-text');
                input.type = 'text';
                input.value = c.content;
                input.placeholder = 'ตัวเลือก ' + (labels[ci] || '');
                input.addEventListener('input', function () { c.content = input.value; changed(); refreshProblem(); });
                row.appendChild(input);
                if (c.is_correct && !matching) row.appendChild(el('span', 'q-choice-badge', 'เฉลย'));
                if (!matching && q.choices.length > 2) {
                    var rm = iconBtn('close', 'ลบตัวเลือกนี้');
                    rm.addEventListener('click', function () { q.choices.splice(ci, 1); changed(); render(); });
                    row.appendChild(rm);
                }
            });
            card.appendChild(wrap);
            if (!matching && q.choices.length < labels.length) {
                var addC = el('button', 'btn btn-sm qe-add-choice');
                addC.type = 'button';
                addC.appendChild(icon('add'));
                addC.appendChild(document.createTextNode('เพิ่มตัวเลือก'));
                addC.addEventListener('click', function () {
                    q.choices.push({ id: 0, content: '', is_correct: false });
                    changed();
                    render();
                    var inputs = list.querySelectorAll('.qe-card')[i].querySelectorAll('.qe-choice-text');
                    if (inputs.length) inputs[inputs.length - 1].focus();
                });
                card.appendChild(addC);
            }
            if (matching) card.appendChild(el('div', 'hint', 'ข้อจับคู่แก้ได้เฉพาะข้อความ'));
        }

        /* คำอธิบายเฉลย / แนวคำตอบ */
        var why = el('div', 'q-why');
        why.appendChild(el('span', 'q-why-label', q.type === 'short_answer' ? 'แนวคำตอบและเกณฑ์' : 'เหตุผลของเฉลย'));
        why.appendChild(textarea(q.explanation, q.type === 'short_answer' ? 'แนวคำตอบที่ถูกต้อง และเกณฑ์ให้คะแนน' : 'อธิบายว่าทำไมข้อนี้ถูก (นักเรียนเห็นหลังส่ง)', 2, function (v) { q.explanation = v; }));
        card.appendChild(why);

        card.appendChild(problemBox);
        refreshProblem();

        return card;
    }

    function move(i, dir) {
        var j = i + dir;
        if (j < 0 || j >= questions.length) return;
        var t = questions[i];
        questions[i] = questions[j];
        questions[j] = t;
        changed();
        render();
    }

    function addQuestion(type) {
        questions.push({ id: 0, type: type, question: '', explanation: '', score: 1, choices: type === 'choice' ? blankChoices() : [] });
        changed();
        render();
        var cards = list.querySelectorAll('.qe-card');
        var last = cards[cards.length - 1];
        last.scrollIntoView({ behavior: 'smooth', block: 'center' });
        last.querySelector('textarea').focus();
    }

    function regenerate(q, btn) {
        btn.disabled = true;
        btn.textContent = 'กำลังร่าง…';
        fetch(root.dataset.base + '/courses/' + root.dataset.course + '/quizzes/' + root.dataset.quiz + '/questions/' + q.id + '/regenerate', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: '_token=' + encodeURIComponent(root.dataset.token)
        }).then(function (r) { return r.json(); }).then(function (d) {
            btn.disabled = false;
            btn.textContent = 'ให้ AI ร่างข้อนี้ใหม่';
            if (d.error) { rvcAlert({ title: 'ร่างข้อนี้ใหม่ไม่สำเร็จ', message: d.error, danger: true }); return; }
            var n = d.question;
            q.type = n.type;
            q.question = n.question || '';
            q.explanation = n.explanation || '';
            q.choices = (n.choices || []).map(function (c) { return { id: +c.id, content: c.content, is_correct: +c.is_correct === 1 }; });
            render();
        }).catch(function () {
            btn.disabled = false;
            btn.textContent = 'ให้ AI ร่างข้อนี้ใหม่';
            rvcAlert({ title: 'ร่างข้อนี้ใหม่ไม่สำเร็จ', message: 'เชื่อมต่อไม่ได้ กรุณาลองใหม่', danger: true });
        });
    }

    function render() {
        list.textContent = '';
        questions.forEach(function (q, i) { list.appendChild(renderCard(q, i)); });
        if (!questions.length) {
            var empty = el('div', 'empty');
            empty.appendChild(el('h3', null, 'ยังไม่มีข้อสอบ'));
            empty.appendChild(el('p', null, 'กด "เพิ่มข้อปรนัย" หรือ "เพิ่มข้ออัตนัย" ด้านล่างเพื่อเริ่มเขียน'));
            list.appendChild(empty);
        }
        updateFoot();
    }

    function updateFoot() {
        var total = questions.reduce(function (s, q) { return s + (parseFloat(q.score) || 0); }, 0);
        var bad = questions.filter(function (q) { return problemOf(q) !== ''; }).length;
        document.getElementById('qCount').textContent = questions.length;
        document.getElementById('footHint').textContent = questions.length + ' ข้อ · รวม ' + (Math.round(total * 100) / 100) + ' คะแนน'
            + (bad ? ' · ยังต้องแก้ ' + bad + ' ข้อ' : '') + (dirty ? ' · ยังไม่ได้บันทึก' : '');
    }

    document.querySelectorAll('[data-add]').forEach(function (b) {
        b.addEventListener('click', function () { addQuestion(b.getAttribute('data-add')); });
    });
    ['quizTitle', 'quizTime'].forEach(function (id) {
        document.getElementById(id).addEventListener('input', changed);
    });

    var form = document.getElementById('saveForm');
    var submitter = null;
    form.addEventListener('click', function (e) {
        var b = e.target.closest('button[type=submit]');
        if (b) submitter = b;
    });
    form.addEventListener('submit', function (e) {
        var action = (e.submitter || submitter || {}).value;
        if (action === 'publish') {
            var problems = [];
            questions.forEach(function (q, i) {
                var p = problemOf(q);
                if (p) problems.push('ข้อ ' + (i + 1) + ': ' + p);
            });
            if (!questions.length) problems.push('ยังไม่มีข้อสอบสักข้อ');
            if (problems.length) {
                e.preventDefault();
                rvcAlert({ title: 'ยังเผยแพร่ไม่ได้', message: problems.slice(0, 8).join('\n') + (problems.length > 8 ? '\n…' : ''), danger: true });
                return;
            }
        }
        document.getElementById('payloadField').value = JSON.stringify(questions.map(function (q) {
            return {
                id: q.id,
                type: q.type,
                question: q.question,
                explanation: q.explanation,
                score: q.score,
                choices: q.type === 'short_answer' ? [] : q.choices.filter(function (c) { return c.content.trim() !== '' || c.id > 0; })
            };
        }));
        document.getElementById('titleField').value = document.getElementById('quizTitle').value;
        document.getElementById('timeField').value = document.getElementById('quizTime').value;
        dirty = false;
    });

    window.addEventListener('beforeunload', function (e) {
        if (dirty) { e.preventDefault(); e.returnValue = ''; }
    });

    render();
})();
