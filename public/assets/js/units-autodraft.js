// หน้ารายวิชา แท็บหน่วยการเรียน: ให้ผู้ช่วยร่างเนื้อหาทุกหน่วยที่ยังว่าง ทีละหน่วยจนครบ
// POST /courses/{c}/units/{u}/content-draft/auto ต่อหน่วย (UnitController::autoDraftContent) — หน่วยที่ร่างแล้วเป็นฉบับร่างเสมอ
(function () {
    'use strict';
    var box = document.getElementById('autoDraft');
    if (!box) return;
    var startBtn = document.getElementById('autoDraftStart');
    var stopBtn = document.getElementById('autoDraftStop');
    var progress = box.querySelector('.autodraft-progress');
    var bar = document.getElementById('autoDraftBar');
    var status = document.getElementById('autoDraftStatus');
    var ids = (box.dataset.units || '').split(',').filter(Boolean);
    var running = false;
    var modelChip = box.querySelector('.ai-model-chip');
    var stopAsked = false;

    function row(id) { return document.querySelector('.list-row[data-unit="' + id + '"]'); }

    function mark(id, text, cls) {
        var r = row(id);
        if (!r) return;
        var chip = r.querySelector('.autodraft-state');
        if (!chip) return;
        chip.hidden = false;
        chip.className = 'autodraft-state' + (cls ? ' is-' + cls : '');
        chip.textContent = text;
    }

    function title(id) {
        var r = row(id);
        var t = r && r.querySelector('.list-title');
        return t ? t.textContent.trim() : 'หน่วย';
    }

    function draftOne(id) {
        var body = new FormData();
        body.append('_token', box.dataset.token);
        return fetch(box.dataset.url + id + '/content-draft/auto', {
            method: 'POST', body: body, credentials: 'same-origin', headers: { Accept: 'application/json' }
        }).then(function (r) {
            return r.json().catch(function () {
                return { ok: false, error: 'เซิร์ฟเวอร์ตอบกลับไม่ถูกต้อง (' + r.status + ')' };
            });
        }).catch(function () {
            return { ok: false, error: 'เชื่อมต่อเซิร์ฟเวอร์ไม่ได้' };
        });
    }

    // ระหว่างทำงาน กันปิดหน้า/ไปหน้าอื่นโดยไม่ตั้งใจ (หน่วยที่กำลังร่างยังทำต่อที่เซิร์ฟเวอร์ แต่หน่วยถัดไปจะไม่เริ่ม)
    window.addEventListener('beforeunload', function (e) {
        if (running) { e.preventDefault(); e.returnValue = ''; }
    });

    stopBtn.addEventListener('click', function () {
        stopAsked = true;
        stopBtn.disabled = true;
        stopBtn.textContent = 'จะหยุดหลังหน่วยนี้…';
    });

    startBtn.addEventListener('click', function () {
        if (running || !ids.length) return;
        running = true;
        stopAsked = false;
        startBtn.hidden = true;
        stopBtn.hidden = false;
        stopBtn.disabled = false;
        stopBtn.textContent = 'หยุดหลังหน่วยนี้';
        progress.hidden = false;
        if (window.rvcAiModel) { window.rvcAiModel(modelChip, 'unit_content', ''); }
        ids.forEach(function (id) { mark(id, 'รอคิว', 'wait'); });

        var done = 0, made = 0, failed = [], skipped = 0;
        var total = ids.length;
        var queue = ids.slice();

        function finish(message) {
            running = false;
            stopBtn.hidden = true;
            bar.style.width = '100%';
            queue.forEach(function (id) { mark(id, 'ยังไม่ได้ร่าง', 'wait'); });
            ids = queue.concat(failed);
            status.innerHTML = '';
            status.appendChild(document.createTextNode(message + ' '));
            if (made) {
                var a = document.createElement('a');
                a.href = window.location.pathname + '?tab=units';
                a.textContent = 'รีเฟรชรายการหน่วย';
                status.appendChild(a);
            }
            if (ids.length) {
                startBtn.hidden = false;
                startBtn.textContent = '✦ ร่างหน่วยที่เหลือ (' + ids.length + ')';
            }
        }

        function next() {
            if (stopAsked) {
                return finish('หยุดแล้ว · ร่างเสร็จ ' + made + ' หน่วย ยังเหลือ ' + (queue.length + failed.length) + ' หน่วย');
            }
            var id = queue.shift();
            if (!id) {
                var msg = 'เสร็จแล้ว · ร่างเนื้อหา ' + made + ' หน่วย';
                if (skipped) msg += ' · ข้าม ' + skipped + ' หน่วยที่มีเนื้อหาแล้ว';
                if (failed.length) msg += ' · ไม่สำเร็จ ' + failed.length + ' หน่วย (กดปุ่มเพื่อลองใหม่)';
                return finish(msg + ' · เปิดแต่ละหน่วยเพื่อตรวจแล้วกด "เผยแพร่" เอง');
            }
            status.textContent = 'กำลังร่างหน่วยที่ ' + (done + 1) + ' จาก ' + total + ': ' + title(id) + ' …';
            mark(id, 'กำลังร่าง…', 'run');
            draftOne(id).then(function (d) {
                done++;
                if (d.chip && window.rvcAiModel) { window.rvcAiModel.set(modelChip, d.chip); }
                bar.style.width = Math.round(done / total * 100) + '%';
                if (d.ok && d.skipped) {
                    skipped++;
                    mark(id, d.skipped === 'exam' ? 'หน่วยสอบ ข้าม' : 'มีเนื้อหาแล้ว ข้าม', 'skip');
                } else if (d.ok) {
                    made++;
                    mark(id, '✓ ร่าง ' + d.sections + ' หัวข้อ · รอตรวจ', 'ok');
                    var r = row(id);
                    if (r) { r.classList.remove('is-published'); r.classList.add('is-draft'); }
                } else {
                    failed.push(id);
                    mark(id, '✗ ' + (d.error || 'ไม่สำเร็จ'), 'err');
                    if (d.stop) {
                        return finish('หยุด: ' + (d.error || 'ผู้ช่วย AI ใช้งานไม่ได้ตอนนี้')
                            + ' · ร่างเสร็จ ' + made + ' หน่วย');
                    }
                }
                next();
            });
        }
        next();
    });
})();
