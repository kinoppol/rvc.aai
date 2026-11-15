// แท็บใบงาน: ให้ผู้ช่วยร่างใบงานของทุกหน่วยที่ยังไม่มี ทีละหน่วยจนครบ
// ครูสั่งเพิ่มเติม/คะแนนเต็มครั้งเดียว แล้ว POST /courses/{c}/units/{u}/assignments/auto ต่อหน่วย (AssignmentController::autoDraft)
(function () {
    'use strict';
    var form = document.getElementById('wsAutoDraft');
    if (!form) return;
    var startBtn = document.getElementById('wsAutoStart');
    var stopBtn = document.getElementById('wsAutoStop');
    var countEl = document.getElementById('wsAutoCount');
    var progress = form.querySelector('.autodraft-progress');
    var bar = document.getElementById('wsAutoBar');
    var status = document.getElementById('wsAutoStatus');
    var items = Array.prototype.slice.call(form.querySelectorAll('.qad-queue li'));
    var running = false;
    var stopAsked = false;

    function pending() {
        return items.filter(function (li) { return !li.dataset.done; });
    }

    function mark(li, text, cls) {
        var chip = li.querySelector('.autodraft-state');
        chip.className = 'autodraft-state' + (cls ? ' is-' + cls : '');
        chip.textContent = text;
    }

    function draftOne(li) {
        var body = new FormData();
        body.append('_token', form.querySelector('input[name="_token"]').value);
        body.append('note', form.querySelector('textarea[name="note"]').value);
        body.append('max_score', form.querySelector('select[name="max_score"]').value);
        return fetch(form.dataset.url + li.dataset.unit + '/assignments/auto', {
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
        stopBtn.textContent = 'จะหยุดหลังหน่วยนี้…';
    });

    function lock(on) {
        form.querySelectorAll('.qad-settings textarea, .qad-settings select').forEach(function (el) { el.disabled = on; });
    }

    startBtn.addEventListener('click', function () {
        var queue = pending();
        if (running || !queue.length) return;
        running = true;
        stopAsked = false;
        lock(true);
        startBtn.hidden = true;
        stopBtn.hidden = false;
        stopBtn.disabled = false;
        stopBtn.textContent = 'หยุดหลังหน่วยนี้';
        progress.hidden = false;
        bar.style.width = '0%';
        queue.forEach(function (li) { mark(li, 'รอคิว', 'wait'); });

        var total = queue.length, done = 0, made = 0, skipped = 0, failed = 0;

        function finish(message) {
            running = false;
            stopBtn.hidden = true;
            lock(false);
            queue.forEach(function (li) { mark(li, 'ยังไม่ได้ร่าง', 'wait'); });
            status.innerHTML = '';
            status.appendChild(document.createTextNode(message + ' '));
            if (made) {
                var a = document.createElement('a');
                a.href = window.location.pathname + '?tab=worksheets';
                a.textContent = 'รีเฟรชรายการใบงาน';
                status.appendChild(a);
            }
            var left = pending().length;
            countEl.textContent = left;
            if (left) {
                startBtn.hidden = false;
                startBtn.firstChild.textContent = '✦ ร่างหน่วยที่เหลือ (';
            }
        }

        function next() {
            if (stopAsked) {
                return finish('หยุดแล้ว · ร่างใบงานเสร็จ ' + made + ' หน่วย');
            }
            var li = queue.shift();
            if (!li) {
                var msg = 'เสร็จแล้ว · ร่างใบงาน ' + made + ' หน่วย';
                if (skipped) msg += ' · ข้าม ' + skipped + ' หน่วยที่มีใบงานแล้ว';
                if (failed) msg += ' · ไม่สำเร็จ ' + failed + ' หน่วย (กดปุ่มเพื่อลองใหม่)';
                return finish(msg + ' · เปิดแต่ละใบงานเพื่อตรวจ แล้วกด "เผยแพร่" หน่วยเอง');
            }
            status.textContent = 'กำลังร่างหน่วยที่ ' + (done + 1) + ' จาก ' + total + ': '
                + li.querySelector('.qad-label').textContent + ' …';
            mark(li, 'กำลังร่าง…', 'run');
            draftOne(li).then(function (d) {
                done++;
                bar.style.width = Math.round(done / total * 100) + '%';
                if (d.ok && d.skipped) {
                    skipped++;
                    li.dataset.done = '1';
                    mark(li, d.skipped === 'exam' ? 'หน่วยสอบ ข้าม' : 'มีใบงานแล้ว ข้าม', 'skip');
                } else if (d.ok) {
                    made++;
                    li.dataset.done = '1';
                    mark(li, '', 'ok');
                    var a = document.createElement('a');
                    a.href = d.editUrl;
                    a.target = '_blank';
                    a.rel = 'noopener';
                    a.textContent = '✓ ' + d.title + ' · ตรวจ';
                    li.querySelector('.autodraft-state').appendChild(a);
                } else {
                    failed++;
                    mark(li, '✗ ' + (d.error || 'ไม่สำเร็จ'), 'err');
                    if (d.stop) {
                        return finish('หยุด: ' + (d.error || 'ผู้ช่วย AI ใช้งานไม่ได้ตอนนี้') + ' · ร่างเสร็จ ' + made + ' หน่วย');
                    }
                }
                next();
            });
        }
        next();
    });
})();
