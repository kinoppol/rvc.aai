/* ตั้งค่าหน้าปกรายวิชา (หน้าแก้ไขรายวิชา)
 *
 * - เลือกสีจากชุดสี เลือกสีเอง หรือกด "สุ่มสี"
 * - อัปโหลดรูปหน้าปก แล้วระบบวิเคราะห์สีในรูป (ทำในเบราว์เซอร์ ไม่ส่งรูปไปที่ใด)
 *   เสนอสีที่เข้ากับรูป และเลือกสีที่เด่นที่สุดให้อัตโนมัติ — ปรับให้เข้มพอที่ตัวอักษรสีขาวอ่านชัด
 * - ตัวอย่างหน้าปกเปลี่ยนตามทันที
 *
 * สีคู่ (--ca) คำนวณแบบเดียวกับ Palette::accentFor() ฝั่ง PHP (หมุนเฉดสี 30°)
 */
(function () {
    'use strict';

    var root = document.getElementById('coverEditor');
    if (!root) { return; }

    var preview = document.getElementById('coverPreview');
    var previewImg = document.getElementById('coverPreviewImg');
    var input = document.getElementById('coverColor');
    var picker = document.getElementById('coverPicker');
    var fileInput = document.getElementById('coverFile');
    var removeBox = document.getElementById('coverRemove');
    var suggestBox = document.getElementById('coverSuggest');
    var suggestList = document.getElementById('coverSuggestList');
    var palette = JSON.parse(root.dataset.palette || '[]');
    var autoColor = root.dataset.auto;   // สีที่ระบบสุ่มให้วิชานี้เมื่อไม่ได้เลือกเอง

    /* ---------- เครื่องมือสี ---------- */
    function rgbOf(hex) {
        return [0, 2, 4].map(function (i) { return parseInt(hex.substr(i, 2), 16); });
    }
    function hexOf(rgb) {
        return rgb.map(function (v) { return Math.max(0, Math.min(255, Math.round(v))).toString(16).padStart(2, '0'); }).join('').toUpperCase();
    }
    function mix(rgb, to, t) { return rgb.map(function (v, i) { return v + (to[i] - v) * t; }); }
    function luminance(rgb) { return (0.299 * rgb[0] + 0.587 * rgb[1] + 0.114 * rgb[2]) / 255; }
    function hsl(rgb) {
        var r = rgb[0] / 255, g = rgb[1] / 255, b = rgb[2] / 255;
        var max = Math.max(r, g, b), min = Math.min(r, g, b), l = (max + min) / 2, d = max - min, h = 0, s = 0;
        if (d) {
            s = l > 0.5 ? d / (2 - max - min) : d / (max + min);
            h = max === r ? ((g - b) / d + (g < b ? 6 : 0)) : (max === g ? (b - r) / d + 2 : (r - g) / d + 4);
            h *= 60;
        }
        return [h, s, l];
    }
    function fromHsl(h, s, l) {
        var c = (1 - Math.abs(2 * l - 1)) * s, x = c * (1 - Math.abs((h / 60) % 2 - 1)), m = l - c / 2;
        var p = h < 60 ? [c, x, 0] : h < 120 ? [x, c, 0] : h < 180 ? [0, c, x] : h < 240 ? [0, x, c] : h < 300 ? [x, 0, c] : [c, 0, x];
        return p.map(function (v) { return (v + m) * 255; });
    }
    function accentOf(hex) {
        var rgb = rgbOf(hex), c = hsl(rgb);
        var acc = c[1] < 0.15 ? mix(rgb, [255, 255, 255], 0.3)
            : fromHsl((c[0] + 30) % 360, Math.min(1, c[1] * 1.05), Math.min(0.62, c[2] + 0.1));
        acc = rgbOf(hexOf(acc));
        // สว่างเกิน (เช่น เหลือง) ดึงกลับเข้าหาสีหลัก — ตรงกับ Cover::accent() ฝั่ง PHP
        if (luminance(acc) > 0.6) { acc = mix(acc, rgb, 0.45); }
        return hexOf(acc);
    }
    /* ทำให้เข้มพอสำหรับตัวอักษรสีขาว — ลดความสว่างแต่คงเฉดและความสดไว้ (ผสมสีดำตรง ๆ จะหม่นเป็นเทา) */
    function readable(rgb) {
        var c = hsl(rgb), out = rgb.slice();
        for (var i = 0; i < 20 && luminance(out) > 0.45; i++) {
            c[2] = Math.max(0.12, c[2] - 0.04);
            out = fromHsl(c[0], Math.min(1, c[1] * 1.02), c[2]);
        }
        return out;
    }

    /* ---------- ตัวอย่างหน้าปก ---------- */
    function show(hex) {
        preview.style.setProperty('--cc', '#' + hex);
        preview.style.setProperty('--ca', '#' + accentOf(hex));
        if (picker) { picker.value = '#' + hex.toLowerCase(); }
        root.querySelectorAll('[data-hex]').forEach(function (b) {
            b.classList.toggle('is-on', b.dataset.hex === input.value || (b.dataset.hex === '' && input.value === ''));
        });
    }
    function choose(hex) {
        input.value = hex;
        show(hex || autoColor);
    }

    root.addEventListener('click', function (e) {
        var b = e.target.closest('[data-hex]');
        if (b) { e.preventDefault(); choose(b.dataset.hex); }
    });
    if (picker) {
        picker.addEventListener('input', function () { choose(picker.value.replace('#', '').toUpperCase()); });
    }
    document.getElementById('coverRandom').addEventListener('click', function () {
        var choices = palette.filter(function (h) { return h !== input.value; });
        choose(choices[Math.floor(Math.random() * choices.length)]);
    });

    /* ---------- วิเคราะห์สีจากรูป ---------- */
    function analyse(img) {
        var size = 72;
        var canvas = document.createElement('canvas');
        canvas.width = size; canvas.height = size;
        var ctx = canvas.getContext('2d', { willReadFrequently: true });
        try {
            ctx.drawImage(img, 0, 0, size, size);
        } catch (err) { return []; }

        var data;
        try { data = ctx.getImageData(0, 0, size, size).data; } catch (err) { return []; }

        // จัดกลุ่มสีแบบหยาบ (4 บิตต่อช่องสี) แล้วให้คะแนนตามจำนวนพิกเซล × ความสด
        var buckets = Object.create(null);
        for (var i = 0; i < data.length; i += 4) {
            if (data[i + 3] < 128) { continue; }
            var key = (data[i] >> 4) + ',' + (data[i + 1] >> 4) + ',' + (data[i + 2] >> 4);
            var bk = buckets[key] || (buckets[key] = { n: 0, r: 0, g: 0, b: 0 });
            bk.n++; bk.r += data[i]; bk.g += data[i + 1]; bk.b += data[i + 2];
        }

        var list = Object.keys(buckets).map(function (k) {
            var bk = buckets[k], rgb = [bk.r / bk.n, bk.g / bk.n, bk.b / bk.n], c = hsl(rgb);
            // ให้น้ำหนักสีสดมาก ๆ (สีเด่นของรูปมักไม่ใช่สีพื้นที่กินที่มากที่สุด) · สีอ่อนจัดหรือขาว/ดำแทบไม่นับ
            var weight = 0.12 + 1.5 * c[1] * c[1];
            if (c[2] > 0.8) { weight *= 0.5; }
            if (c[2] > 0.93 || c[2] < 0.07) { weight *= 0.1; }
            return { rgb: rgb, score: bk.n * weight };
        }).sort(function (a, b) { return b.score - a.score; });

        // เลือกสีที่ต่างกันพอสมควร ได้สูงสุด 5 สี
        var picked = [];
        list.forEach(function (c) {
            if (picked.length >= 5) { return; }
            var far = picked.every(function (p) {
                var d = Math.sqrt(Math.pow(p[0] - c.rgb[0], 2) + Math.pow(p[1] - c.rgb[1], 2) + Math.pow(p[2] - c.rgb[2], 2));
                return d > 60;
            });
            if (far) { picked.push(c.rgb); }
        });

        return picked.map(function (rgb) { return hexOf(readable(rgb)); });
    }

    function suggest(img, autoPick) {
        var colors = analyse(img);
        suggestList.innerHTML = '';
        suggestBox.hidden = !colors.length;
        colors.forEach(function (hex) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'cover-swatch';
            b.dataset.hex = hex;
            b.title = '#' + hex;
            b.style.setProperty('--sw', '#' + hex);
            suggestList.appendChild(b);
        });
        if (autoPick && colors.length) { choose(colors[0]); } else { show(input.value || autoColor); }
    }

    if (fileInput) {
        fileInput.addEventListener('change', function () {
            var file = fileInput.files && fileInput.files[0];
            if (!file) { return; }
            if (removeBox) { removeBox.checked = false; }
            var url = URL.createObjectURL(file);
            previewImg.onload = function () { suggest(previewImg, true); };
            previewImg.src = url;
            previewImg.hidden = false;
        });
    }
    if (removeBox) {
        removeBox.addEventListener('change', function () {
            previewImg.hidden = removeBox.checked;
            suggestBox.hidden = removeBox.checked || !suggestList.children.length;
        });
    }

    // มีรูปหน้าปกอยู่แล้ว — เสนอสีที่เข้ากับรูปไว้ให้ (ไม่เปลี่ยนสีที่บันทึกไว้เอง)
    if (previewImg.getAttribute('src')) {
        if (previewImg.complete) { suggest(previewImg, false); } else { previewImg.addEventListener('load', function () { suggest(previewImg, false); }); }
    }

    show(input.value || autoColor);
})();
