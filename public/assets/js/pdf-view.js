/*
 * แสดงไฟล์ PDF ในหน้าเว็บด้วย PDF.js — นักเรียนเปิดอ่านได้ทันทีโดยไม่ต้องดาวน์โหลด
 * (มือถือ Android / แอปในตัวบางตัวไม่แสดง PDF ใน <iframe> แต่จะดาวน์โหลดไฟล์แทน จึงวาดลง <canvas> เอง)
 *
 * <div class="learn-pdf" data-pdf-src="…/file" data-pdf-title="…"></div>
 * โหลด PDF.js จาก cdnjs เฉพาะหน้าที่มี PDF · โหลดไม่ได้ (ออฟไลน์ / CDN ล่ม) → ถอยไปใช้ <iframe> ของเบราว์เซอร์
 */
(function () {
    'use strict';

    var VERSION = '3.11.174';
    var CDN = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/' + VERSION + '/';

    var boxes = document.querySelectorAll('[data-pdf-src]');
    if (!boxes.length) return;

    function loadLib() {
        if (window.pdfjsLib) return Promise.resolve(window.pdfjsLib);
        return new Promise(function (resolve, reject) {
            var s = document.createElement('script');
            s.src = CDN + 'pdf.min.js';
            s.onload = function () {
                if (!window.pdfjsLib) { reject(new Error('pdfjs')); return; }
                window.pdfjsLib.GlobalWorkerOptions.workerSrc = CDN + 'pdf.worker.min.js';
                resolve(window.pdfjsLib);
            };
            s.onerror = reject;
            document.head.appendChild(s);
        });
    }

    function fallback(box) {
        box.textContent = '';
        box.classList.add('is-frame');
        var f = document.createElement('iframe');
        f.src = box.dataset.pdfSrc;
        f.title = box.dataset.pdfTitle || 'ไฟล์ PDF';
        box.appendChild(f);
    }

    function status(box, text) {
        var el = box.querySelector('.learn-pdf-status');
        if (!el) {
            el = document.createElement('div');
            el.className = 'learn-pdf-status';
            box.appendChild(el);
        }
        el.textContent = text;
        el.hidden = !text;
    }

    // วาดทีละหน้าเมื่อเลื่อนมาใกล้ — ไฟล์หลายสิบหน้าไม่ทำให้มือถือค้าง
    function render(box, pdf) {
        var pages = box.querySelector('.learn-pdf-pages');
        var width = function () { return Math.max(240, pages.clientWidth); };
        var ratio = Math.min(window.devicePixelRatio || 1, 2);
        var drawn = {};

        function draw(num, holder) {
            if (drawn[num]) return;
            drawn[num] = true;
            pdf.getPage(num).then(function (page) {
                var base = page.getViewport({ scale: 1 });
                var scale = width() / base.width;
                var vp = page.getViewport({ scale: scale * ratio });
                var canvas = document.createElement('canvas');
                canvas.width = Math.floor(vp.width);
                canvas.height = Math.floor(vp.height);
                canvas.setAttribute('aria-label', 'หน้า ' + num);
                holder.style.aspectRatio = base.width + ' / ' + base.height;
                holder.appendChild(canvas);
                return page.render({ canvasContext: canvas.getContext('2d'), viewport: vp }).promise;
            }).catch(function () { holder.textContent = 'แสดงหน้า ' + num + ' ไม่ได้'; });
        }

        var observer = 'IntersectionObserver' in window
            ? new IntersectionObserver(function (entries) {
                entries.forEach(function (e) {
                    if (e.isIntersecting) { draw(+e.target.dataset.page, e.target); observer.unobserve(e.target); }
                });
            }, { rootMargin: '600px 0px' })
            : null;

        for (var i = 1; i <= pdf.numPages; i++) {
            var holder = document.createElement('div');
            holder.className = 'learn-pdf-page';
            holder.dataset.page = i;
            holder.style.aspectRatio = '1 / 1.414'; // A4 ไว้ก่อน วาดจริงแล้วปรับตามหน้า
            pages.appendChild(holder);
            if (observer) observer.observe(holder); else draw(i, holder);
        }
        status(box, '');
        var count = (box.parentElement || document).querySelector('.learn-pdf-count');
        if (count) count.textContent = pdf.numPages + ' หน้า';
    }

    boxes.forEach(function (box) {
        box.innerHTML = '<div class="learn-pdf-pages"></div>';
        status(box, 'กำลังเปิดเอกสาร…');
        loadLib()
            .then(function (lib) {
                return lib.getDocument({ url: box.dataset.pdfSrc, withCredentials: true }).promise;
            })
            .then(function (pdf) { render(box, pdf); })
            .catch(function () { fallback(box); });
    });
})();
