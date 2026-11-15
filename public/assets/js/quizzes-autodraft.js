// แท็บแบบทดสอบ: ให้ผู้ช่วยร่างแบบทดสอบก่อนเรียน/หลังเรียนของทุกหน่วยที่ยังไม่มี ทีละชุดจนครบ
// ตั้งค่า (ชุด รูปแบบ ระดับ จำนวนข้อ) ครั้งเดียว แล้ว POST /courses/{c}/quizzes/auto ต่อชุด (QuizWizardController::autoDraft)
(function () {
    'use strict';
    var form = document.getElementById('quizAutoDraft');
    if (!form) return;
    var startBtn = document.getElementById('quizAutoStart');
    var stopBtn = document.getElementById('quizAutoStop');
    var countEl = document.getElementById('quizAutoCount');
    var progress = form.querySelector('.autodraft-progress');
    var bar = document.getElementById('quizAutoBar');
    var status = document.getElementById('quizAutoStatus');
    var items = Array.prototype.slice.call(form.querySelectorAll('.qad-queue li'));
    var running = false;
    var stopAsked = false;

    // การ์ดซ่อนไว้ก่อน · ปุ่ม [data-autodraft-open] เปิด/ปิด (ระหว่างร่างปิดไม่ได้)
    var opener = document.querySelector('[data-autodraft-open="' + form.id + '"]');
    if (opener) {
        opener.addEventListener('click', function () {
            if (running && !form.hidden) return;
            form.hidden = !form.hidden;
            opener.setAttribute('aria-expanded', String(!form.hidden));
            if (!form.hidden) form.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    }

    function checked(name) {
        return Array.prototype.map.call(form.querySelectorAll('input[name="' + name + '"]:checked'), function (i) { return i.value; });
    }

    // งานที่จะทำตามชุดที่ติ๊ก (ก่อนเรียน/หลังเรียน) และยังไม่สำเร็จ
    function pending() {
        var kinds = checked('kinds[]');
        return items.filter(function (li) { return !li.dataset.done && kinds.indexOf(li.dataset.kind) !== -1; });
    }

    function refreshCount() {
        var n = pending().length;
        countEl.textContent = n;
        startBtn.disabled = running || n === 0 || checked('types[]').length === 0;
        items.forEach(function (li) {
            if (li.dataset.done) return;
            var on = checked('kinds[]').indexOf(li.dataset.kind) !== -1;
            li.classList.toggle('is-off', !on);
        });
    }
    form.addEventListener('change', function () { if (!running) refreshCount(); });
    refreshCount();

    function mark(li, text, cls) {
        var chip = li.querySelector('.autodraft-state');
        chip.className = 'autodraft-state' + (cls ? ' is-' + cls : '');
        chip.textContent = text;
    }

    function draftOne(li) {
        var body = new FormData();
        body.append('_token', form.querySelector('input[name="_token"]').value);
        body.append('unit_id', li.dataset.unit);
        body.append('kind', li.dataset.kind);
        checked('types[]').forEach(function (t) { body.append('types[]', t); });
        body.append('level', (form.querySelector('input[name="level"]:checked') || {}).value || 'กลาง');
        body.append('count', form.querySelector('select[name="count"]').value);
        return fetch(form.dataset.url, {
            method: 'POST', body: body, credentials: 'same-origin', headers: { Accept: 'application/json' }
        }).then(function (r) {
            return r.json().catch(function () {
                return { ok: false, error: 'เซิร์ฟเวอร์ตอบกลับไม่ถูกต้อง (' + r.status + ')' };
            });
        }).catch(function () {
            return { ok: false, error: 'เชื่อมต่อเซิร์ฟเวอร์ไม่ได้' };
        });
    }

    window.addEventListener('beforeunload', function (e) {
        if (running) { e.preventDefault(); e.returnValue = ''; }
    });

    stopBtn.addEventListener('click', function () {
        stopAsked = true;
        stopBtn.disabled = true;
        stopBtn.textContent = 'จะหยุดหลังชุดนี้…';
    });

    startBtn.addEventListener('click', function () {
        var queue = pending();
        if (running || !queue.length) return;
        if (!checked('types[]').length) { window.alert('เลือกรูปแบบข้อสอบอย่างน้อยหนึ่งแบบ'); return; }
        running = true;
        stopAsked = false;
        form.querySelectorAll('.qad-settings input, .qad-settings select').forEach(function (el) { el.disabled = true; });
        startBtn.hidden = true;
        stopBtn.hidden = false;
        stopBtn.disabled = false;
        stopBtn.textContent = 'หยุดหลังชุดนี้';
        progress.hidden = false;
        queue.forEach(function (li) { mark(li, 'รอคิว', 'wait'); });

        var total = queue.length, done = 0, made = 0, skipped = 0, failed = 0;

        function finish(message) {
            running = false;
            stopBtn.hidden = true;
            form.querySelectorAll('.qad-settings input, .qad-settings select').forEach(function (el) { el.disabled = false; });
            queue.forEach(function (li) { mark(li, 'ยังไม่ได้ร่าง', 'wait'); });
            status.innerHTML = '';
            status.appendChild(document.createTextNode(message + ' '));
            if (made) {
                var a = document.createElement('a');
                a.href = window.location.pathname + '?tab=quizzes';
                a.textContent = 'รีเฟรชรายการแบบทดสอบ';
                status.appendChild(a);
            }
            refreshCount();
            if (pending().length) {
                startBtn.hidden = false;
                startBtn.firstChild.textContent = '✦ ร่างชุดที่เหลือ (';
            }
        }

        function next() {
            if (stopAsked) {
                return finish('หยุดแล้ว · ร่างเสร็จ ' + made + ' ชุด');
            }
            var li = queue.shift();
            if (!li) {
                var msg = 'เสร็จแล้ว · ร่างแบบทดสอบ ' + made + ' ชุด';
                if (skipped) msg += ' · ข้าม ' + skipped + ' ชุดที่มีอยู่แล้ว';
                if (failed) msg += ' · ไม่สำเร็จ ' + failed + ' ชุด (กดปุ่มเพื่อลองใหม่)';
                return finish(msg + ' · เปิดแต่ละชุดเพื่อตรวจแล้วกด "เผยแพร่" เอง');
            }
            status.textContent = 'กำลังร่างชุดที่ ' + (done + 1) + ' จาก ' + total + ': '
                + li.querySelector('.qad-label').textContent + ' …';
            mark(li, 'กำลังร่าง…', 'run');
            draftOne(li).then(function (d) {
                done++;
                bar.style.width = Math.round(done / total * 100) + '%';
                if (d.ok && d.skipped) {
                    skipped++;
                    li.dataset.done = '1';
                    mark(li, d.skipped === 'exam' ? 'หน่วยสอบ ข้าม' : 'มีแบบทดสอบแล้ว ข้าม', 'skip');
                } else if (d.ok) {
                    made++;
                    li.dataset.done = '1';
                    mark(li, '', 'ok');
                    var a = document.createElement('a');
                    a.href = d.reviewUrl;
                    a.target = '_blank';
                    a.rel = 'noopener';
                    a.textContent = '✓ ' + d.questions + ' ข้อ · ตรวจ';
                    li.querySelector('.autodraft-state').appendChild(a);
                } else {
                    failed++;
                    mark(li, '✗ ' + (d.error || 'ไม่สำเร็จ'), 'err');
                    if (d.stop) {
                        return finish('หยุด: ' + (d.error || 'ผู้ช่วย AI ใช้งานไม่ได้ตอนนี้') + ' · ร่างเสร็จ ' + made + ' ชุด');
                    }
                }
                next();
            });
        }
        next();
    });
})();
