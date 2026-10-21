/* เลือกโมเดลที่เปิดใช้ได้หลายตัว — ใช้ในหน้าตั้งค่า AI ของครูและของผู้ดูแล
 *
 *   var picker = RvcModelPicker(boxElement, { name: 'models[]', selected: ['a', 'b'] });
 *   picker.setModels(['a', 'b', 'c']);   // รายชื่อที่ดึงจากผู้ให้บริการ
 *
 * ตัวที่ติ๊กไว้ส่งไปกับฟอร์มเป็น hidden input ตามลำดับที่เลือก — ตัวแรกคือโมเดลหลัก
 * ส่ง unlimitedName: 'unlimited[]' (หน้าผู้ดูแล) เพื่อให้ติ๊ก "ใช้ได้ไม่จำกัด" เป็นรายโมเดล
 * (โมเดลฟรีที่ไม่ควรนับโควตาของครู) — ค่าเดิมส่งมาทาง unlimited: [...]
 * ส่ง aliasName: 'alias' (หน้าผู้ดูแล) เพื่อตั้งชื่อเล่นสั้น ๆ ให้ครูจำง่าย — ส่งไปเป็น alias_model[] คู่กับ alias_name[]
 * รายชื่อจากผู้ให้บริการบางรายยาวหลายร้อยตัว จึงมีช่องค้นหาและแสดงทีละไม่เกิน 150 ตัว
 */
(function () {
    'use strict';

    var MAX_SHOWN = 150;

    window.RvcModelPicker = function (box, opts) {
        opts = opts || {};
        var name = opts.name || 'models[]';
        var selected = (opts.selected || []).filter(function (m) { return m; });
        var unlimitedName = opts.unlimitedName || '';
        var unlimited = (opts.unlimited || []).slice();
        var aliasName = opts.aliasName || '';
        var aliases = opts.aliases || {};
        var models = [];

        box.classList.add('mp');
        box.innerHTML =
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
            return label;
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
