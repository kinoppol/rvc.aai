/* เลือกโมเดลที่เปิดใช้ได้หลายตัว — ใช้ในหน้าตั้งค่า AI ของครูและของผู้ดูแล
 *
 *   var picker = RvcModelPicker(boxElement, { name: 'models[]', selected: ['a', 'b'] });
 *   picker.setModels(['a', 'b', 'c']);   // รายชื่อที่ดึงจากผู้ให้บริการ
 *
 * ตัวที่ติ๊กไว้ส่งไปกับฟอร์มเป็น hidden input ตามลำดับที่เลือก — ตัวแรกคือโมเดลหลัก
 * ส่ง unlimitedName: 'unlimited[]' (หน้าผู้ดูแล) เพื่อให้ติ๊ก "ใช้ได้ไม่จำกัด" เป็นรายโมเดล
 * (โมเดลฟรีที่ไม่ควรนับโควตาของครู) — ค่าเดิมส่งมาทาง unlimited: [...]
 * ส่ง aliasName: 'alias' (หน้าผู้ดูแล) เพื่อตั้งชื่อเล่นสั้น ๆ ให้ครูจำง่าย — ส่งไปเป็น alias_model[] คู่กับ alias_name[]
 * ส่ง rolesName: 'roles_json' เพื่อกำหนดบทบาทของแต่ละโมเดล (แชต / ข้อสอบ / แผน / เนื้อหา / OCR) — ส่งไปเป็น JSON ช่องเดียว
 * ค่าเดิมทาง roles: {model: {roles, note}} · ใส่ suggestUrl + csrf (+ provider) เพื่อเปิดปุ่ม "ให้ AI จัดบทบาทให้"
 * รายชื่อจากผู้ให้บริการบางรายยาวหลายร้อยตัว จึงมีช่องค้นหาและแสดงทีละไม่เกิน 150 ตัว
 */
(function () {
    'use strict';

    var MAX_SHOWN = 150;
    /* ตรงกับ App\AI\ModelRoles::ROLES — แก้ให้ตรงกันทั้งสองที่ */
    var ROLES = [
        ['chat', 'แชต', 'แชตผู้ช่วย ถาม–ตอบ'],
        ['quiz', 'ข้อสอบ', 'ออกข้อสอบ'],
        ['plan', 'แผน', 'แผนการสอน'],
        ['content', 'เนื้อหา', 'ร่างหน่วย เนื้อหา ใบงาน'],
        ['ocr', 'OCR', 'อ่านเอกสารสแกน/รูปภาพ (OCR)']
    ];
    var TEXT_ROLES = ['chat', 'quiz', 'plan', 'content'];
    /* ตรงกับ ModelRoles::OCR_ONLY / VISION */
    var OCR_ONLY = /ocr/i;
    var VISION = /vision|vl\b|-vl|llava|pixtral|gemini|gpt-4o|gpt-4\.1|gpt-5|claude|gemma-?3|internvl|minicpm-v|llama-?4/i;
    /* ตรงกับ ModelRoles::guess() — โมเดลที่ไม่ใช่งานข้อความไม่ได้บทบาท */
    var NOT_TEXT = /tts|whisper|speech|audio|transcri|embed|rerank|image|imagen|dall-?e|diffusion|flux|guard|moderation/i;

    function guessRoles(m) {
        if (OCR_ONLY.test(m)) { return ['ocr']; }
        if (NOT_TEXT.test(m)) { return []; }
        return VISION.test(m) ? TEXT_ROLES.concat(['ocr']) : TEXT_ROLES.slice();
    }

    window.RvcModelPicker = function (box, opts) {
        opts = opts || {};
        var name = opts.name || 'models[]';
        var selected = (opts.selected || []).filter(function (m) { return m; });
        var unlimitedName = opts.unlimitedName || '';
        var unlimited = (opts.unlimited || []).slice();
        var aliasName = opts.aliasName || '';
        var aliases = opts.aliases || {};
        var rolesName = opts.rolesName || '';
        var roles = {};
        Object.keys(opts.roles || {}).forEach(function (m) {
            var v = opts.roles[m] || {};
            roles[m] = { roles: (v.roles || []).slice(), note: v.note || '' };
        });
        var models = [];

        function rolesOf(m) {
            if (!roles[m]) { roles[m] = { roles: guessRoles(m), note: '' }; }
            return roles[m];
        }

        box.classList.add('mp');
        box.innerHTML =
            (rolesName
                ? '<div class="mp-rolebar"><span class="hint grow">ติ๊กบทบาทให้แต่ละโมเดล · เลือก AI “อัตโนมัติ” แล้วระบบจะใช้โมเดลที่เหมาะกับงานนั้นก่อน</span>' +
                  (opts.suggestUrl ? '<button type="button" class="btn btn-sm btn-ai mp-suggest">✦ ให้ AI จัดบทบาทให้</button>' : '') +
                  '</div><div class="mp-suggest-msg" hidden></div>'
                : '') +
            '<input type="search" class="input mp-filter" placeholder="ค้นหาโมเดล เช่น qwen, gemini, gpt" autocomplete="off">' +
            '<div class="mp-list" role="group" aria-label="โมเดลที่เปิดใช้"></div>' +
            '<div class="mp-add">' +
            '<input type="text" class="input mono mp-custom" placeholder="หรือพิมพ์ชื่อโมเดลเอง" autocomplete="off">' +
            '<button type="button" class="btn btn-sm">เพิ่ม</button>' +
            '</div>' +
            '<div class="mp-hidden"></div>' +
            '<div class="hint mp-count"></div>';

        var filter = box.querySelector('.mp-filter');
        var list = box.querySelector('.mp-list');
        var hidden = box.querySelector('.mp-hidden');
        var count = box.querySelector('.mp-count');
        var custom = box.querySelector('.mp-custom');

        function sync() {
            hidden.innerHTML = '';
            selected.forEach(function (m) {
                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = name;
                input.value = m;
                hidden.appendChild(input);
                if (aliasName && aliases[m]) {
                    [[aliasName + '_model[]', m], [aliasName + '_name[]', aliases[m]]].forEach(function (p) {
                        var a = document.createElement('input');
                        a.type = 'hidden';
                        a.name = p[0];
                        a.value = p[1];
                        hidden.appendChild(a);
                    });
                }
                if (unlimitedName && unlimited.indexOf(m) !== -1) {
                    var free = document.createElement('input');
                    free.type = 'hidden';
                    free.name = unlimitedName;
                    free.value = m;
                    hidden.appendChild(free);
                }
            });
            if (rolesName) {
                var map = {};
                selected.forEach(function (m) { map[m] = rolesOf(m); });
                var rj = document.createElement('input');
                rj.type = 'hidden';
                rj.name = rolesName;
                rj.value = JSON.stringify(map);
                hidden.appendChild(rj);
            }
            var freeCount = selected.filter(function (m) { return unlimited.indexOf(m) !== -1; }).length;
            count.textContent = selected.length
                ? 'เปิดใช้ ' + selected.length + ' โมเดล · ตัวแรกในรายการ (หลัก) จะถูกใช้เมื่อไม่ได้ระบุโมเดล'
                : 'ยังไม่ได้เลือกโมเดล · ระบบจะให้ผู้ให้บริการเลือกโมเดลเริ่มต้นเอง';
            if (unlimitedName && freeCount) {
                count.textContent += ' · ใช้ได้ไม่จำกัด ' + freeCount + ' โมเดล (ไม่นับโควตาครู)';
            }
        }

        function row(m, checked) {
            var label = document.createElement('label');
            label.className = 'mp-row' + (checked ? ' is-on' : '');
            var cb = document.createElement('input');
            cb.type = 'checkbox';
            cb.checked = checked;
            cb.addEventListener('change', function () {
                var i = selected.indexOf(m);
                if (cb.checked && i === -1) { selected.push(m); }
                if (!cb.checked && i !== -1) { selected.splice(i, 1); }
                render();
            });
            var text = document.createElement('span');
            text.className = 'mp-name mono';
            text.textContent = m;
            text.title = m;
            label.appendChild(cb);
            label.appendChild(text);
            if (checked && selected[0] === m) {
                var main = document.createElement('span');
                main.className = 'chip chip-ok';
                main.textContent = 'หลัก';
                label.appendChild(main);
            } else if (checked) {
                var up = document.createElement('button');
                up.type = 'button';
                up.className = 'mp-main';
                up.textContent = 'ตั้งเป็นหลัก';
                up.addEventListener('click', function (e) {
                    e.preventDefault();
                    selected.splice(selected.indexOf(m), 1);
                    selected.unshift(m);
                    render();
                });
                label.appendChild(up);
            }
            if (checked && unlimitedName) {
                var freeLabel = document.createElement('span');
                freeLabel.className = 'mp-free' + (unlimited.indexOf(m) !== -1 ? ' is-on' : '');
                freeLabel.title = 'ครูใช้โมเดลนี้ได้โดยไม่นับโควตารายเดือน (เหมาะกับโมเดลฟรี)';
                freeLabel.textContent = unlimited.indexOf(m) !== -1 ? '∞ ไม่จำกัด' : 'นับโควตา';
                freeLabel.setAttribute('role', 'switch');
                freeLabel.setAttribute('aria-checked', unlimited.indexOf(m) !== -1 ? 'true' : 'false');
                freeLabel.tabIndex = 0;
                var toggleFree = function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    var i = unlimited.indexOf(m);
                    if (i === -1) { unlimited.push(m); } else { unlimited.splice(i, 1); }
                    render();
                };
                freeLabel.addEventListener('click', toggleFree);
                freeLabel.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { toggleFree(e); } });
                label.appendChild(freeLabel);
            }
            if (checked && aliasName) {
                var nick = document.createElement('input');
                nick.type = 'text';
                nick.className = 'input mp-alias';
                nick.placeholder = 'ชื่อเล่น';
                nick.maxLength = 40;
                nick.value = aliases[m] || '';
                nick.title = 'ชื่อสั้น ๆ ที่ครูเห็นแทนชื่อโมเดลจริง (เว้นว่าง = ใช้ชื่อจริง)';
                nick.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); nick.focus(); });
                nick.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); } });
                nick.addEventListener('input', function () {
                    var v = nick.value.trim();
                    if (v) { aliases[m] = v; } else { delete aliases[m]; }
                    sync();
                });
                label.appendChild(nick);
            }
            if (checked && models.length && models.indexOf(m) === -1) {
                var gone = document.createElement('span');
                gone.className = 'chip chip-warn';
                gone.textContent = 'ไม่พบบนปลายทาง';
                label.appendChild(gone);
            }
            if (checked && rolesName) {
                label.classList.add('has-roles');
                label.appendChild(roleLine(m));
            }
            return label;
        }

        /* บรรทัดบทบาทใต้ชื่อโมเดล — ป้ายกดสลับได้ อยู่ใน <label> จึงต้องกันไม่ให้ไปติ๊ก/เอาติ๊กออก */
        function roleLine(m) {
            var info = rolesOf(m);
            var line = document.createElement('div');
            line.className = 'mp-roles';
            ROLES.forEach(function (r) {
                var on = info.roles.indexOf(r[0]) !== -1;
                var chip = document.createElement('span');
                chip.className = 'mp-role' + (on ? ' is-on' : '');
                chip.textContent = (on ? '✓ ' : '') + r[1];
                chip.title = r[2];
                chip.setAttribute('role', 'switch');
                chip.setAttribute('aria-checked', on ? 'true' : 'false');
                chip.tabIndex = 0;
                var toggle = function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    var i = info.roles.indexOf(r[0]);
                    if (i === -1) { info.roles.push(r[0]); } else { info.roles.splice(i, 1); }
                    info.roles = ROLES.map(function (x) { return x[0]; }).filter(function (k) { return info.roles.indexOf(k) !== -1; });
                    info.note = '';
                    render();
                };
                chip.addEventListener('click', toggle);
                chip.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { toggle(e); } });
                line.appendChild(chip);
            });
            var note = document.createElement('span');
            note.className = 'mp-role-note';
            note.textContent = info.note || (info.roles.length ? '' : 'ไม่ใช้กับงานสอน (เช่น เสียง, embedding)');
            line.appendChild(note);
            line.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); });
            return line;
        }

        function render() {
            var words = filter.value.toLowerCase().split(/\s+/).filter(Boolean);
            var hit = function (m) {
                var n = m.toLowerCase();
                return words.every(function (w) { return n.indexOf(w) !== -1; });
            };

            list.innerHTML = '';
            selected.filter(hit).forEach(function (m) { list.appendChild(row(m, true)); });

            var rest = models.filter(function (m) { return selected.indexOf(m) === -1 && hit(m); });
            rest.slice(0, MAX_SHOWN).forEach(function (m) { list.appendChild(row(m, false)); });

            if (rest.length > MAX_SHOWN) {
                var more = document.createElement('div');
                more.className = 'mp-note';
                more.textContent = 'และอีก ' + (rest.length - MAX_SHOWN) + ' โมเดล · พิมพ์ค้นหาเพื่อกรองให้แคบลง';
                list.appendChild(more);
            }
            if (!list.children.length) {
                var none = document.createElement('div');
                none.className = 'mp-note';
                none.textContent = models.length ? 'ไม่พบโมเดลที่ตรงกับคำค้น' : 'ยังไม่มีรายชื่อโมเดล · กดดึงรายชื่อ หรือพิมพ์ชื่อโมเดลเองด้านล่าง';
                list.appendChild(none);
            }
            sync();
        }

        function addCustom() {
            var m = custom.value.trim();
            if (!m) { return; }
            if (selected.indexOf(m) === -1) { selected.push(m); }
            custom.value = '';
            render();
        }

        filter.addEventListener('input', render);
        custom.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); addCustom(); }
        });
        box.querySelector('.mp-add .btn').addEventListener('click', addCustom);

        /* ให้ AI (ส่วนกลางก่อน หมดโควตาแล้วใช้ตัวอื่น) จัดบทบาทของโมเดลที่ติ๊กไว้ — ยังไม่บันทึก จนกว่าจะกดบันทึกฟอร์ม */
        var suggestBtn = box.querySelector('.mp-suggest');
        if (suggestBtn) {
            var msg = box.querySelector('.mp-suggest-msg');
            var say = function (ok, text) {
                msg.hidden = false;
                msg.className = 'mp-suggest-msg ' + (ok ? 'is-ok' : 'is-err');
                msg.textContent = text;
            };
            suggestBtn.addEventListener('click', function () {
                if (!selected.length) { say(false, 'ติ๊กโมเดลที่จะเปิดใช้ก่อน แล้วค่อยให้ AI จัดบทบาท'); return; }
                var body = new URLSearchParams();
                body.append('_token', opts.csrf || '');
                body.append('provider', typeof opts.provider === 'function' ? opts.provider() : (opts.provider || ''));
                selected.forEach(function (m) { body.append('models[]', m); });
                suggestBtn.disabled = true;
                suggestBtn.textContent = 'AI กำลังพิจารณา…';
                say(true, 'กำลังให้ AI ดูคุณสมบัติของ ' + selected.length + ' โมเดล อาจใช้เวลาครึ่งนาที');
                fetch(opts.suggestUrl, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body })
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        if (d.ok) {
                            Object.keys(d.roles || {}).forEach(function (m) { roles[m] = d.roles[m]; });
                            render();
                        }
                        say(d.ok, d.message || (d.ok ? 'เรียบร้อย' : 'ไม่สำเร็จ'));
                    })
                    .catch(function () { say(false, 'ติดต่อเซิร์ฟเวอร์ไม่ได้ ลองใหม่อีกครั้ง'); })
                    .then(function () { suggestBtn.disabled = false; suggestBtn.textContent = '✦ ให้ AI จัดบทบาทให้'; });
            });
        }

        render();

        return {
            setModels: function (names) {
                models = (names || []).slice();
                render();
            },
            /* ติ๊กโมเดลที่ผู้ให้บริการแนะนำไว้ให้ ถ้ายังไม่ได้เลือกอะไรเลย */
            suggest: function (m) {
                if (m && !selected.length) { selected.push(m); render(); }
            },
            selected: function () { return selected.slice(); }
        };
    };
})();
