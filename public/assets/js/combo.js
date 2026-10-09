/*
 * RvcCombo — เปลี่ยน <select> ที่ตัวเลือกยาว ๆ ให้เป็นช่อง autocomplete
 * พิมพ์ชื่อบางส่วนเพื่อกรอง (หลายคำคั่นช่องว่าง ต้องตรงทุกคำ) เลือกด้วยคลิกหรือลูกศร + Enter
 *
 * <select> เดิมยังอยู่ (ซ่อนไว้) และค่าถูกตั้งกลับไปที่มันเสมอ ฟอร์มและโค้ดที่อ่าน select.value จึงทำงานเหมือนเดิม
 * ใช้ได้สองแบบ: ใส่ data-combo บน <select> ในเทมเพลต หรือเรียก RvcCombo.enhance(selectEl) กับ select ที่สร้างด้วย JS
 */
(function () {
    var MAX_SHOWN = 200;

    function norm(s) {
        return String(s || '').toLowerCase().replace(/\s+/g, ' ').trim();
    }

    function enhance(select) {
        if (!select || select.dataset.comboReady) { return; }
        select.dataset.comboReady = '1';

        // ตัวเลือกค่าว่าง (เช่น "ยังไม่ระบุ") = ค่าเมื่อช่องว่าง ไม่ต้องแสดงในรายการ
        var blank = null;
        var items = [];
        Array.prototype.forEach.call(select.options, function (o) {
            var label = o.textContent.replace(/\s+/g, ' ').trim();
            if (o.value === '' || o.value === '0') { blank = blank || { value: o.value, label: label }; return; }
            items.push({ value: o.value, label: label, key: norm(label) });
        });

        var wrap = document.createElement('div');
        wrap.className = 'combo';
        var input = document.createElement('input');
        input.type = 'text';
        input.className = select.className;
        input.autocomplete = 'off';
        input.setAttribute('role', 'combobox');
        input.setAttribute('aria-autocomplete', 'list');
        input.setAttribute('aria-expanded', 'false');
        input.setAttribute('data-lpignore', 'true');
        input.setAttribute('data-1p-ignore', '');
        input.placeholder = select.dataset.comboPlaceholder || ('พิมพ์เพื่อค้นหา' + (blank ? ' · เว้นว่าง = ' + blank.label.replace(/^[—\s]+|[—\s]+$/g, '') : ''));
        if (select.id) {
            // ให้ <label for> ชี้มาที่ช่องพิมพ์แทน
            input.id = select.id;
            select.id = select.id + '__select';
        }
        var list = document.createElement('ul');
        list.className = 'combo-list';
        list.setAttribute('role', 'listbox');
        list.hidden = true;

        select.parentNode.insertBefore(wrap, select);
        wrap.appendChild(input);
        wrap.appendChild(list);
        wrap.appendChild(select);
        select.hidden = true;
        select.tabIndex = -1;

        var active = -1;

        function current() {
            for (var i = 0; i < items.length; i++) {
                if (items[i].value === select.value) { return items[i]; }
            }
            return null;
        }
        function showCurrent() {
            var c = current();
            input.value = c ? c.label : '';
        }

        function matches() {
            var c = current();
            var q = norm(input.value);
            // ข้อความในช่องคือรายการที่เลือกอยู่แล้ว → แสดงทั้งหมดให้เปลี่ยนได้ง่าย
            if (c && q === c.key) { q = ''; }
            var words = q.split(' ').filter(Boolean);
            return items.filter(function (it) {
                return words.every(function (w) { return it.key.indexOf(w) !== -1; });
            });
        }

        function render() {
            var found = matches();
            list.innerHTML = '';
            active = -1;
            found.slice(0, MAX_SHOWN).forEach(function (it) {
                var li = document.createElement('li');
                li.setAttribute('role', 'option');
                li.textContent = it.label;
                li.title = it.label;
                li.dataset.value = it.value;
                if (it.value === select.value) { li.className = 'is-current'; }
                li.addEventListener('mousedown', function (e) { e.preventDefault(); choose(it); });
                list.appendChild(li);
            });
            if (found.length > MAX_SHOWN) {
                note('และอีก ' + (found.length - MAX_SHOWN) + ' รายการ · พิมพ์เพิ่มเพื่อกรองให้แคบลง');
            }
            if (!found.length) { note('ไม่พบรายการที่ตรงกัน'); }
        }
        function note(text) {
            var li = document.createElement('li');
            li.className = 'combo-note';
            li.textContent = text;
            list.appendChild(li);
        }

        function open() {
            render();
            list.hidden = false;
            input.setAttribute('aria-expanded', 'true');
        }
        function close() {
            list.hidden = true;
            input.setAttribute('aria-expanded', 'false');
        }
        function setValue(value) {
            if (select.value === value) { return; }
            select.value = value;
            select.dispatchEvent(new Event('change', { bubbles: true }));
        }
        function choose(it) {
            setValue(it.value);
            showCurrent();
            close();
        }
        function highlight(i) {
            var opts = list.querySelectorAll('li[role=option]');
            if (!opts.length) { return; }
            active = (i + opts.length) % opts.length;
            opts.forEach(function (li, n) { li.classList.toggle('is-active', n === active); });
            opts[active].scrollIntoView({ block: 'nearest' });
        }

        input.addEventListener('focus', function () { input.select(); open(); });
        input.addEventListener('click', open);
        input.addEventListener('input', open);
        input.addEventListener('blur', function () {
            close();
            // ลบข้อความจนว่าง = กลับเป็นค่าว่าง, พิมพ์ค้างไว้แต่ไม่ได้เลือก = คืนค่าที่เลือกไว้เดิม
            if (input.value.trim() === '' && blank) { setValue(blank.value); }
            showCurrent();
        });
        input.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                if (list.hidden) { open(); }
                highlight(active + (e.key === 'ArrowDown' ? 1 : -1));
            } else if (e.key === 'Enter') {
                if (list.hidden) { return; }
                e.preventDefault(); // อย่าส่งฟอร์มตอนกำลังเลือก
                var opts = list.querySelectorAll('li[role=option]');
                var pick = opts[active >= 0 ? active : 0];
                if (pick) {
                    for (var i = 0; i < items.length; i++) {
                        if (items[i].value === pick.dataset.value) { choose(items[i]); break; }
                    }
                } else {
                    close();
                }
            } else if (e.key === 'Escape') {
                close();
                showCurrent();
            }
        });

        // โค้ดอื่นตั้งค่า select ตรง ๆ (เช่น เลือกไว้ล่วงหน้า) → อัปเดตข้อความในช่อง
        select.addEventListener('change', showCurrent);
        showCurrent();
    }

    window.RvcCombo = { enhance: enhance };

    function init() {
        document.querySelectorAll('select[data-combo]').forEach(enhance);
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
