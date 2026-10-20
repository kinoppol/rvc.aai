/*
 * ฟอร์มส่งงานของนักเรียน (_partials/submission.latte)
 *   • เลือกไฟล์ได้หลายรอบ (มือถือมักเลือกได้ทีละรูป) — สะสมไว้ในรายการ กด × เอาออกได้ แล้วใส่กลับเข้า input ก่อนส่ง
 *   • รูปถ่ายจากมือถือ (หลาย MB) ย่อเป็น JPEG ด้านยาวไม่เกิน 2000px ในเบราว์เซอร์ก่อนส่ง — เร็วขึ้นและไม่ชนเพดานอัปโหลดของเซิร์ฟเวอร์
 *   • ตรวจจำนวน / ชนิด / ขนาดไฟล์ก่อนส่ง (เซิร์ฟเวอร์ตรวจซ้ำอีกครั้งเสมอ)
 */
(function () {
    'use strict';

    var TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf'];
    var MAX_SIDE = 2000;
    var SHRINK_OVER = 1.5 * 1024 * 1024;

    document.querySelectorAll('form[data-submission]').forEach(function (form) {
        var input = form.querySelector('[data-sub-input]');
        var list = form.querySelector('[data-sub-list]');
        var submit = form.querySelector('[data-sub-submit]');
        var maxFiles = parseInt(form.dataset.maxFiles, 10) || 5;
        var maxBytes = (parseInt(form.dataset.maxMb, 10) || 10) * 1024 * 1024;
        var canSet = typeof DataTransfer === 'function';
        var picked = [];
        var busy = 0;

        function kept() {
            var total = parseInt(form.dataset.kept, 10) || 0;
            form.querySelectorAll('[data-sub-remove]:checked').forEach(function () { total--; });
            return total;
        }

        function size(n) {
            return n >= 1048576 ? (n / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(n / 1024)) + ' KB';
        }

        function sync() {
            if (!canSet) return;
            var dt = new DataTransfer();
            picked.forEach(function (f) { dt.items.add(f); });
            input.files = dt.files;
        }

        function render(note) {
            list.textContent = '';
            picked.forEach(function (f, i) {
                var row = document.createElement('div');
                row.className = 'sub-new-row';
                var icon = document.createElement('span');
                icon.className = 'msi';
                icon.setAttribute('aria-hidden', 'true');
                icon.textContent = f.type === 'application/pdf' ? 'picture_as_pdf' : 'image';
                var name = document.createElement('span');
                name.className = 'sub-new-name';
                name.textContent = f.name + ' · ' + size(f.size);
                var del = document.createElement('button');
                del.type = 'button';
                del.className = 'sub-new-del';
                del.setAttribute('aria-label', 'เอา ' + f.name + ' ออก');
                del.textContent = '×';
                del.addEventListener('click', function () { picked.splice(i, 1); sync(); render(); });
                row.append(icon, name, del);
                list.appendChild(row);
            });
            if (note) {
                var p = document.createElement('div');
                p.className = 'sub-new-note';
                p.textContent = note;
                list.appendChild(p);
            }
            submit.disabled = busy > 0;
        }

        // ย่อรูปใหญ่เป็น JPEG — GIF (อาจเคลื่อนไหว) และรูปเล็กส่งตามเดิม · ย่อไม่ได้ก็ส่งไฟล์เดิม
        function shrink(file) {
            if (!/^image\/(jpeg|png|webp)$/.test(file.type) || file.size <= SHRINK_OVER || !window.createImageBitmap) {
                return Promise.resolve(file);
            }
            return createImageBitmap(file).then(function (bmp) {
                var scale = Math.min(1, MAX_SIDE / Math.max(bmp.width, bmp.height));
                var c = document.createElement('canvas');
                c.width = Math.round(bmp.width * scale);
                c.height = Math.round(bmp.height * scale);
                var ctx = c.getContext('2d');
                ctx.fillStyle = '#fff';
                ctx.fillRect(0, 0, c.width, c.height);
                ctx.drawImage(bmp, 0, 0, c.width, c.height);
                return new Promise(function (resolve) {
                    c.toBlob(function (blob) {
                        if (!blob || blob.size >= file.size) { resolve(file); return; }
                        resolve(new File([blob], file.name.replace(/\.[^.]+$/, '') + '.jpg', { type: 'image/jpeg', lastModified: Date.now() }));
                    }, 'image/jpeg', 0.85);
                });
            }).catch(function () { return file; });
        }

        input.addEventListener('change', function () {
            // เบราว์เซอร์เก่าที่ตั้งค่า input.files เองไม่ได้ → ใช้ไฟล์ที่เลือกครั้งล่าสุดตามปกติ
            if (!canSet) return;
            var chosen = Array.prototype.slice.call(input.files);
            var problems = [];
            chosen.forEach(function (f) {
                if (TYPES.indexOf(f.type) === -1) { problems.push(f.name + ': รับเฉพาะรูปภาพหรือ PDF'); return; }
                if (picked.length + kept() + busy >= maxFiles) { problems.push(f.name + ': เกิน ' + maxFiles + ' ไฟล์'); return; }
                busy++;
                render('กำลังเตรียมไฟล์…');
                shrink(f).then(function (out) {
                    busy--;
                    if (out.size > maxBytes) {
                        problems.push(f.name + ': ใหญ่เกิน ' + (maxBytes / 1048576) + ' MB');
                    } else {
                        picked.push(out);
                    }
                    sync();
                    render(problems.join(' · '));
                });
            });
            sync();
            render(problems.join(' · '));
        });

        form.addEventListener('change', function (e) {
            if (e.target.matches('[data-sub-remove]')) render();
        });

        form.addEventListener('submit', function (e) {
            if (busy > 0) { e.preventDefault(); return; }
            var text = form.querySelector('textarea[name="content"]').value.trim();
            if (!text && kept() + (canSet ? picked.length : input.files.length) === 0) {
                e.preventDefault();
                render('กรุณาพิมพ์คำตอบ หรือแนบไฟล์อย่างน้อยหนึ่งไฟล์');
                return;
            }
            submit.disabled = true;
            submit.lastChild.textContent = 'กำลังส่ง…';
        });
    });
})();
