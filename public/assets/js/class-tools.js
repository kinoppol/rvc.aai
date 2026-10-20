/*
 * แท็บ "เครื่องมือ" ในหน้าต่างผู้ช่วย AI — ตัวช่วยจัดกิจกรรมในชั้นเรียน
 *   • นาฬิกา: นับถอยหลัง (เสียงเตือนเมื่อหมดเวลา) / จับเวลา (บันทึกรอบได้)
 *   • วงล้อสุ่มชื่อ: รายชื่อนักเรียนจาก /tools/roster หรือพิมพ์เพิ่มเอง
 *
 * สถานะ (นาฬิกาที่กำลังเดิน รายชื่อบนวงล้อ) เก็บใน sessionStorage แยกตามผู้ใช้ — เปลี่ยนหน้าแล้วนาฬิกายังเดินต่อ
 * และไม่ค้างไว้บนเครื่องห้องเรียนที่ใช้ร่วมกัน · ตัวเลือกเสียงเก็บใน localStorage (ไม่ใช่ข้อมูลส่วนตัว)
 * เสียงทั้งหมดสังเคราะห์ด้วย Web Audio จึงไม่มีไฟล์เสียงให้โหลด
 */
(function () {
    'use strict';

    var panel = document.getElementById('aichatPanel');
    var root = document.getElementById('ctools');
    if (!panel || !root) return;

    var base = panel.dataset.base;
    var storeKey = 'rvc.tools.' + (panel.dataset.user || '0');
    var prefKey = 'rvc.tools.sound';
    var $ = function (id) { return document.getElementById(id); };

    /* ---------- สถานะ ---------- */
    var state = {
        pane: 'chat', tool: 'timer',
        timer: { mode: 'down', dur: 300, endAt: 0, left: 300, running: false, startAt: 0, acc: 0, laps: [], rang: false },
        wheel: { course: 0, names: [], out: [], auto: false, seq: 0 }
    };
    try {
        var saved = JSON.parse(sessionStorage.getItem(storeKey) || 'null');
        if (saved && typeof saved === 'object') {
            state.pane = saved.pane || state.pane;
            state.tool = saved.tool || state.tool;
            Object.assign(state.timer, saved.timer || {});
            Object.assign(state.wheel, saved.wheel || {});
        }
    } catch (e) { /* sessionStorage ใช้ไม่ได้ — ทำงานต่อโดยไม่จำค่า */ }

    function save() {
        try { sessionStorage.setItem(storeKey, JSON.stringify(state)); } catch (e) { /* เต็มหรือถูกปิด */ }
    }

    var prefs = { alarm: 'bell', music: 'tick', effect: 'fanfare', speak: true };
    try { Object.assign(prefs, JSON.parse(localStorage.getItem(prefKey) || '{}')); } catch (e) { /* ใช้ค่าเริ่มต้น */ }
    function savePrefs() {
        try { localStorage.setItem(prefKey, JSON.stringify(prefs)); } catch (e) { /* ไม่จำก็ได้ */ }
    }

    /* =====================================================================
     * เสียง (Web Audio)
     * ===================================================================== */
    var actx = null, master = null;
    function audio() {
        if (!actx) {
            var AC = window.AudioContext || window.webkitAudioContext;
            if (!AC) return null;
            actx = new AC();
            master = actx.createGain();
            master.gain.value = 0.55;
            master.connect(actx.destination);
        }
        if (actx.state === 'suspended') actx.resume();
        return actx;
    }

    // เสียงโน้ตหนึ่งตัว: ความถี่ เวลาเริ่ม (วินาทีจากนี้) ความยาว ชนิดคลื่น ความดัง
    function tone(freq, at, dur, type, vol, glideTo) {
        var c = audio(); if (!c) return;
        var t = c.currentTime + (at || 0);
        var o = c.createOscillator(), g = c.createGain();
        o.type = type || 'sine';
        o.frequency.setValueAtTime(freq, t);
        if (glideTo) o.frequency.linearRampToValueAtTime(glideTo, t + dur);
        g.gain.setValueAtTime(0.0001, t);
        g.gain.exponentialRampToValueAtTime(vol || 0.3, t + 0.012);
        g.gain.exponentialRampToValueAtTime(0.0001, t + dur);
        o.connect(g); g.connect(master);
        o.start(t); o.stop(t + dur + 0.05);
    }

    var noiseBuf = null;
    function noise(at, dur, vol, freq, q) {
        var c = audio(); if (!c) return;
        if (!noiseBuf) {
            noiseBuf = c.createBuffer(1, c.sampleRate, c.sampleRate);
            var d = noiseBuf.getChannelData(0);
            for (var i = 0; i < d.length; i++) d[i] = Math.random() * 2 - 1;
        }
        var t = c.currentTime + (at || 0);
        var s = c.createBufferSource(), f = c.createBiquadFilter(), g = c.createGain();
        s.buffer = noiseBuf;
        f.type = 'bandpass'; f.frequency.value = freq || 1500; f.Q.value = q || 0.8;
        g.gain.setValueAtTime(vol || 0.3, t);
        g.gain.exponentialRampToValueAtTime(0.0001, t + dur);
        s.connect(f); f.connect(g); g.connect(master);
        s.start(t, Math.random() * 0.5); s.stop(t + dur + 0.05);
    }

    // ระฆัง: โน้ตหลักกับฮาร์มอนิกที่ไม่ลงตัว เสียงกังวานแบบโลหะ
    function bell(at, f) {
        [1, 2.76, 5.4].forEach(function (m, i) { tone(f * m, at, 1.6 - i * 0.4, 'sine', [0.32, 0.12, 0.06][i]); });
    }

    var ALARMS = {
        bell: function () { for (var i = 0; i < 6; i++) bell(i * 0.42, 880); },
        chime: function () {
            [659, 523, 587, 392, 392, 587, 659, 523].forEach(function (f, i) { bell(i * 0.55, f); });
        },
        beep: function () {
            for (var r = 0; r < 4; r++) for (var i = 0; i < 4; i++) tone(1046, r * 0.9 + i * 0.13, 0.08, 'square', 0.12);
        },
        melody: function () {
            [523, 659, 784, 1046, 784, 1046].forEach(function (f, i) {
                tone(f, i * 0.18, i === 5 ? 0.8 : 0.22, 'triangle', 0.3);
            });
        },
        siren: function () {
            for (var i = 0; i < 4; i++) {
                tone(600, i * 0.8, 0.4, 'sawtooth', 0.09, 1200);
                tone(1200, i * 0.8 + 0.4, 0.4, 'sawtooth', 0.09, 600);
            }
        },
        none: function () {}
    };

    var EFFECTS = {
        fanfare: function () {
            [[392, 0, 0.14], [523, 0.15, 0.14], [659, 0.3, 0.14], [784, 0.45, 0.5], [659, 0.68, 0.14], [784, 0.82, 0.8]]
                .forEach(function (n) { tone(n[0], n[1], n[2], 'sawtooth', 0.12); tone(n[0] * 2, n[1], n[2], 'square', 0.03); });
        },
        applause: function () {
            for (var i = 0; i < 70; i++) noise(Math.random() * 2.2, 0.05 + Math.random() * 0.06, 0.25 + Math.random() * 0.2, 1200 + Math.random() * 1800, 1.2);
        },
        tada: function () {
            [523, 659, 784].forEach(function (f) { tone(f, 0, 0.12, 'triangle', 0.18); });
            [587, 740, 880, 1175].forEach(function (f) { tone(f, 0.16, 1.1, 'triangle', 0.16); });
        },
        ding: function () { bell(0, 1318); bell(0.15, 1760); },
        none: function () {}
    };

    /* เพลงขณะหมุน: เล่นวนเป็นจังหวะจนกว่าจะเรียก stopMusic() · แบบ tick เล่นตามช่องที่ผ่านลูกศร (ดู wheel) */
    var musicTimer = null, musicStep = 0;
    var MUSIC = {
        drum: { every: 55, play: function (n) { noise(0, 0.06, 0.35 + (n % 4 === 0 ? 0.2 : 0), 900, 0.7); } },
        fun: {
            every: 140,
            play: function (n) {
                var seq = [523, 659, 784, 659, 587, 698, 880, 698, 523, 659, 784, 1046, 988, 784, 659, 587];
                tone(seq[n % seq.length], 0, 0.13, 'square', 0.07);
                if (n % 4 === 0) tone(131, 0, 0.2, 'triangle', 0.25);
            }
        },
        game: {
            every: 120,
            play: function (n) {
                var seq = [392, 392, 466, 392, 523, 466, 392, 349];
                tone(seq[n % 8], 0, 0.1, 'sawtooth', 0.06);
                if (n % 2 === 0) noise(0, 0.04, 0.2, 5000, 1);
            }
        }
    };
    function startMusic(kind) {
        stopMusic();
        var m = MUSIC[kind];
        if (!m) return;
        musicStep = 0;
        musicTimer = setInterval(function () { m.play(musicStep++); }, m.every);
    }
    function stopMusic() { if (musicTimer) { clearInterval(musicTimer); musicTimer = null; } }
    function tick() { tone(1800, 0, 0.03, 'square', 0.07); }

    /* =====================================================================
     * สลับแท็บผู้ช่วย AI / เครื่องมือ และสลับเครื่องมือ
     * ===================================================================== */
    var panes = panel.querySelectorAll('[data-pane]');
    var paneBtns = panel.querySelectorAll('[data-pane-btn]');
    function showPane(name) {
        state.pane = name; save();
        panes.forEach(function (p) { p.hidden = p.dataset.pane !== name; });
        paneBtns.forEach(function (b) {
            var on = b.dataset.paneBtn === name;
            b.classList.toggle('is-on', on);
            b.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        panel.classList.toggle('is-tools', name === 'tools');
        // ปุ่มล้างบทสนทนาอยู่บนหัวหน้าต่าง — ใช้ได้เฉพาะแท็บผู้ช่วย AI
        var clearBtn = $('aichatClear');
        if (clearBtn) clearBtn.hidden = name !== 'chat';
        if (name === 'tools' && state.tool === 'wheel') wheelInit();
        if (name === 'tools') requestAnimationFrame(drawWheel);
    }
    paneBtns.forEach(function (b) { b.addEventListener('click', function () { showPane(b.dataset.paneBtn); }); });

    var toolBtns = root.querySelectorAll('[data-tool-btn]');
    var tools = root.querySelectorAll('[data-tool]');
    function showTool(name) {
        state.tool = name; save();
        tools.forEach(function (t) { t.hidden = t.dataset.tool !== name; });
        toolBtns.forEach(function (b) { b.classList.toggle('is-on', b.dataset.toolBtn === name); });
        if (name === 'wheel') { wheelInit(); requestAnimationFrame(drawWheel); }
    }
    toolBtns.forEach(function (b) { b.addEventListener('click', function () { showTool(b.dataset.toolBtn); }); });

    // เต็มจอ — ให้แสดงนาฬิกา/วงล้อบนโปรเจกเตอร์ได้ชัด
    var fullBtn = $('ctoolsFull');
    if (!root.requestFullscreen) {
        fullBtn.hidden = true;
    } else {
        fullBtn.addEventListener('click', function () {
            if (document.fullscreenElement) document.exitFullscreen();
            else root.requestFullscreen().catch(function () { /* เบราว์เซอร์ไม่อนุญาต */ });
        });
        document.addEventListener('fullscreenchange', function () {
            var on = document.fullscreenElement === root;
            fullBtn.querySelector('.msi').textContent = on ? 'fullscreen_exit' : 'fullscreen';
            fullBtn.title = on ? 'ออกจากเต็มจอ' : 'ขยายเต็มจอ (แสดงบนจอโปรเจกเตอร์)';
            requestAnimationFrame(drawWheel);
        });
    }

    root.querySelectorAll('[data-preview]').forEach(function (b) {
        b.addEventListener('click', function () {
            var k = b.dataset.preview;
            if (k === 'alarm') ALARMS[prefs.alarm]();
            if (k === 'effect') EFFECTS[prefs.effect]();
            if (k === 'music') {
                if (prefs.music === 'tick') { for (var i = 0; i < 12; i++) setTimeout(tick, i * (60 + i * 12)); }
                else { startMusic(prefs.music); setTimeout(stopMusic, 2200); }
            }
        });
    });

    /* =====================================================================
     * นาฬิกา
     * ===================================================================== */
    var T = state.timer;
    var timeEl = $('ctimerTime'), subEl = $('ctimerSub'), arc = $('ctimerArc'), face = $('ctimerFace');
    var goBtn = $('ctimerGo'), lapBtn = $('ctimerLap'), lapsEl = $('ctimerLaps');
    var minIn = $('ctimerMin'), secIn = $('ctimerSec'), alarmSel = $('ctimerAlarm');
    var CIRC = 2 * Math.PI * 54;
    arc.style.strokeDasharray = CIRC;

    // ปุ่มเวลาบนปุ่มลอย — เห็นเวลาที่เหลือแม้ปิดหน้าต่างอยู่
    var fab = $('aichatFab');
    var fabTime = document.createElement('span');
    fabTime.className = 'aichat-fab-timer';
    fabTime.hidden = true;
    if (fab) fab.appendChild(fabTime);

    function fmt(sec, tenths) {
        sec = Math.max(0, sec);
        var h = Math.floor(sec / 3600), m = Math.floor(sec % 3600 / 60), s = Math.floor(sec % 60);
        var out = (h ? h + ':' + String(m).padStart(2, '0') : String(m).padStart(2, '0')) + ':' + String(s).padStart(2, '0');
        if (tenths) out += '.' + Math.floor(sec * 10 % 10);
        return out;
    }

    function elapsed() { return T.acc + (T.running ? (Date.now() - T.startAt) / 1000 : 0); }
    function left() { return T.running ? Math.max(0, (T.endAt - Date.now()) / 1000) : T.left; }

    function renderTimer() {
        var down = T.mode === 'down';
        root.querySelectorAll('[data-timer-mode]').forEach(function (b) { b.classList.toggle('is-on', b.dataset.timerMode === T.mode); });
        $('ctimerSet').hidden = !down || T.running || (down && T.left < T.dur && T.left > 0);
        $('ctimerAlarmRow').hidden = !down;
        lapBtn.hidden = down || !T.running;
        goBtn.querySelector('.msi').textContent = T.running ? 'pause' : 'play_arrow';
        goBtn.lastElementChild.textContent = T.running ? 'หยุดชั่วคราว' : (down ? (T.left < T.dur && T.left > 0 ? 'เดินต่อ' : 'เริ่ม') : (T.acc > 0 ? 'เดินต่อ' : 'เริ่ม'));

        var text, frac, sub;
        if (down) {
            var l = left();
            text = fmt(Math.ceil(l - 0.001));
            frac = T.dur > 0 ? l / T.dur : 0;
            var done = l <= 0 && T.dur > 0 && T.rang;
            sub = done ? 'หมดเวลา!' : (T.running ? 'กำลังนับถอยหลัง' : (T.left < T.dur ? 'หยุดชั่วคราว' : 'พร้อมเริ่ม'));
            face.classList.toggle('is-done', done);
            face.classList.toggle('is-low', !done && T.running && l <= 10);
        } else {
            var e = elapsed();
            text = fmt(e, true);
            frac = (e % 60) / 60;
            sub = T.running ? 'กำลังจับเวลา' : (T.acc > 0 ? 'หยุดชั่วคราว' : 'พร้อมเริ่ม');
            face.classList.remove('is-done', 'is-low');
        }
        timeEl.textContent = text;
        subEl.textContent = sub;
        arc.style.strokeDashoffset = CIRC * (1 - frac);

        // แสดงเวลาบนปุ่มลอยเมื่อหน้าต่างปิดอยู่และนาฬิกากำลังเดิน
        fabTime.hidden = !(T.running && panel.hidden);
        if (!fabTime.hidden) fabTime.textContent = down ? fmt(Math.ceil(left() - 0.001)) : fmt(elapsed());
    }

    function setDuration(sec) {
        sec = Math.max(1, Math.min(180 * 60 + 59, Math.round(sec)));
        T.dur = sec; T.left = sec; T.running = false; T.rang = false;
        minIn.value = Math.floor(sec / 60); secIn.value = sec % 60;
        save(); renderTimer();
    }

    function finish() {
        T.running = false; T.left = 0; T.rang = true; save();
        ALARMS[prefs.alarm]();
        renderTimer();
        // หน้าต่างปิดอยู่ก็เปิดให้เห็นว่าหมดเวลา
        if (panel.hidden && fab) fab.click();
        if (state.pane !== 'tools') showPane('tools');
        if (state.tool !== 'timer') showTool('timer');
    }

    var loop = null;
    function ensureLoop() {
        if (loop) return;
        loop = setInterval(function () {
            if (!T.running) { renderTimer(); clearInterval(loop); loop = null; return; }
            if (T.mode === 'down' && left() <= 0) { finish(); return; }
            renderTimer();
        }, 100);
    }

    goBtn.addEventListener('click', function () {
        audio(); // ปลดล็อกเสียงด้วยการกดของผู้ใช้ ไม่งั้นเบราว์เซอร์ไม่ยอมให้เตือนตอนหมดเวลา
        if (T.mode === 'down') {
            if (T.running) { T.left = left(); T.running = false; }
            else {
                if (T.left <= 0 || T.left >= T.dur) {
                    var sec = (parseInt(minIn.value, 10) || 0) * 60 + (parseInt(secIn.value, 10) || 0);
                    if (sec <= 0) { minIn.focus(); return; }
                    T.dur = sec; T.left = sec;
                }
                T.endAt = Date.now() + T.left * 1000; T.running = true; T.rang = false;
            }
        } else {
            if (T.running) { T.acc = elapsed(); T.running = false; }
            else { T.startAt = Date.now(); T.running = true; }
        }
        save(); renderTimer(); ensureLoop();
    });

    $('ctimerReset').addEventListener('click', function () {
        T.running = false; T.rang = false;
        if (T.mode === 'down') T.left = T.dur; else { T.acc = 0; T.laps = []; renderLaps(); }
        save(); renderTimer();
    });

    lapBtn.addEventListener('click', function () {
        T.laps.push(elapsed()); save(); renderLaps();
    });
    function renderLaps() {
        lapsEl.textContent = '';
        T.laps.forEach(function (sec, i) {
            var li = document.createElement('li');
            li.textContent = 'รอบที่ ' + (i + 1) + ' · ' + fmt(sec, true);
            lapsEl.appendChild(li);
        });
        lapsEl.hidden = T.mode === 'down' || T.laps.length === 0;
    }

    root.querySelectorAll('[data-timer-mode]').forEach(function (b) {
        b.addEventListener('click', function () {
            if (T.running || b.dataset.timerMode === T.mode) return;
            T.mode = b.dataset.timerMode; save(); renderLaps(); renderTimer();
        });
    });
    root.querySelectorAll('[data-preset]').forEach(function (b) {
        b.addEventListener('click', function () { setDuration(parseInt(b.dataset.preset, 10)); });
    });
    [minIn, secIn].forEach(function (inp) {
        inp.addEventListener('change', function () {
            setDuration((parseInt(minIn.value, 10) || 0) * 60 + (parseInt(secIn.value, 10) || 0));
        });
    });

    alarmSel.value = prefs.alarm;
    alarmSel.addEventListener('change', function () { prefs.alarm = alarmSel.value; savePrefs(); });

    // ปุ่มลอยถูกกดเปิด/ปิด — อัปเดตป้ายเวลาบนปุ่ม
    if (fab) fab.addEventListener('click', function () { setTimeout(renderTimer, 0); });
    $('aichatClose').addEventListener('click', function () { setTimeout(renderTimer, 0); });

    // นาฬิกาที่หมดเวลาระหว่างเปลี่ยนหน้า: แสดงว่าหมดเวลาแล้ว (เบราว์เซอร์ไม่ให้เล่นเสียงเองก่อนผู้ใช้กดอะไร)
    if (T.running && T.mode === 'down' && left() <= 0) { T.running = false; T.left = 0; T.rang = true; save(); }
    if (T.mode === 'down') { minIn.value = Math.floor(T.dur / 60); secIn.value = T.dur % 60; }
    renderLaps(); renderTimer();
    if (T.running) ensureLoop();

    /* =====================================================================
     * วงล้อสุ่มชื่อ
     * ===================================================================== */
    var W = state.wheel;
    var canvas = $('cwheelCanvas'), ctx = canvas.getContext('2d');
    var stage = $('cwheelStage'), spinBtn = $('cwheelSpin'), courseSel = $('cwheelCourse');
    var resultEl = $('cwheelResult'), winnerEl = $('cwheelWinner');
    var angle = 0, spinning = false, lastWinner = null, loaded = false;
    var canRoster = root.dataset.roster === '1';

    function colorFor(i, n) {
        // ไล่สีรอบวงให้ช่องติดกันต่างสีชัด ๆ (กระโดดทีละมุมทองคำ)
        var h = Math.round((i * 137.508) % 360);
        return 'hsl(' + h + ' 78% ' + (n > 24 ? 60 : 58) + '%)';
    }

    function drawWheel() {
        var size = Math.max(160, Math.min(stage.clientWidth, stage.clientHeight || stage.clientWidth));
        if (!size || stage.offsetParent === null) return;
        var dpr = window.devicePixelRatio || 1;
        if (canvas.width !== size * dpr) {
            canvas.width = canvas.height = size * dpr;
            canvas.style.width = canvas.style.height = size + 'px';
        }
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        ctx.clearRect(0, 0, size, size);

        var n = W.names.length, r = size / 2 - 4, cx = size / 2, cy = size / 2;
        $('cwheelEmpty').hidden = n > 0;
        spinBtn.disabled = n === 0 || spinning;
        if (n === 0) {
            ctx.beginPath(); ctx.arc(cx, cy, r, 0, Math.PI * 2);
            ctx.fillStyle = 'rgba(148,163,184,.18)'; ctx.fill();
            return;
        }

        var seg = Math.PI * 2 / n;
        var font = Math.max(9, Math.min(18, Math.floor(r * 0.9 * seg / 1.2), Math.floor(size / 22)));
        for (var i = 0; i < n; i++) {
            var a0 = angle + i * seg - Math.PI / 2;
            ctx.beginPath(); ctx.moveTo(cx, cy);
            ctx.arc(cx, cy, r, a0, a0 + seg); ctx.closePath();
            ctx.fillStyle = n === 1 ? 'hsl(230 78% 62%)' : colorFor(i, n);
            ctx.fill();
            ctx.strokeStyle = 'rgba(255,255,255,.85)'; ctx.lineWidth = n > 40 ? 0.6 : 1.5; ctx.stroke();

            ctx.save();
            ctx.translate(cx, cy); ctx.rotate(a0 + seg / 2);
            ctx.textAlign = 'right'; ctx.textBaseline = 'middle';
            ctx.font = '600 ' + font + 'px "Noto Sans Thai", "Plus Jakarta Sans", sans-serif';
            ctx.fillStyle = '#fff';
            ctx.shadowColor = 'rgba(0,0,0,.35)'; ctx.shadowBlur = 3;
            var label = W.names[i].name, maxW = r * 0.68;
            while (label.length > 2 && ctx.measureText(label).width > maxW) label = label.slice(0, -2) + '…';
            ctx.fillText(label, r - 12, 0);
            ctx.restore();
        }
        ctx.beginPath(); ctx.arc(cx, cy, r, 0, Math.PI * 2);
        ctx.lineWidth = 4; ctx.strokeStyle = 'rgba(255,255,255,.9)'; ctx.stroke();
    }

    // ช่องที่อยู่ใต้ลูกศร (ด้านบนของวงล้อ) ณ มุมปัจจุบัน
    function indexAtPointer() {
        var n = W.names.length, seg = Math.PI * 2 / n;
        var a = ((-angle) % (Math.PI * 2) + Math.PI * 2) % (Math.PI * 2);
        return Math.floor(a / seg) % n;
    }

    function spin() {
        if (spinning || W.names.length === 0) return;
        audio();
        if (lastWinner && W.auto) removeName(lastWinner.id);
        resultEl.hidden = true; lastWinner = null;

        var n = W.names.length, seg = Math.PI * 2 / n;
        var pick = Math.floor(Math.random() * n);
        // หมุนให้กลางช่องที่สุ่มได้มาอยู่ใต้ลูกศร (ขยับในช่องนิดหน่อยให้ดูเป็นธรรมชาติ) + 5–7 รอบ
        var inSeg = (0.15 + Math.random() * 0.7) * seg;
        var target = -(pick * seg + inSeg);
        var turns = (5 + Math.floor(Math.random() * 3)) * Math.PI * 2;
        var start = angle, cur = ((start % (Math.PI * 2)) + Math.PI * 2) % (Math.PI * 2);
        var delta = turns + ((target - cur) % (Math.PI * 2) + Math.PI * 2) % (Math.PI * 2);
        var dur = 5200 + Math.random() * 1200, t0 = performance.now();
        var lastIdx = indexAtPointer();

        spinning = true; stage.classList.add('is-spinning'); drawWheel();
        if (prefs.music !== 'tick') startMusic(prefs.music);

        function frame(now) {
            var p = Math.min(1, (now - t0) / dur);
            var ease = 1 - Math.pow(1 - p, 4);
            angle = start + delta * ease;
            drawWheel();
            var idx = indexAtPointer();
            if (idx !== lastIdx) { lastIdx = idx; if (prefs.music === 'tick') tick(); }
            if (p < 1) { requestAnimationFrame(frame); return; }

            spinning = false; stage.classList.remove('is-spinning'); stopMusic();
            angle = angle % (Math.PI * 2);
            lastWinner = W.names[indexAtPointer()];
            winnerEl.textContent = lastWinner.name;
            resultEl.hidden = false;
            $('cwheelRemove').hidden = W.auto;
            resultEl.classList.remove('is-pop'); void resultEl.offsetWidth; resultEl.classList.add('is-pop');
            EFFECTS[prefs.effect]();
            drawWheel();
            announce(lastWinner);
        }
        requestAnimationFrame(frame);
    }

    /* ---------- ขานชื่อ: ประกาศชื่อตัวใหญ่กลางจอ + โปรยกระดาษสี + อ่านออกเสียง (ถ้าเปิดไว้) ---------- */
    var shout = null, confettiRaf = 0;
    function buildShout() {
        shout = document.createElement('div');
        shout.className = 'cwheel-shout';
        shout.setAttribute('role', 'dialog');
        shout.setAttribute('aria-modal', 'true');
        shout.setAttribute('aria-label', 'ประกาศชื่อผู้ถูกสุ่ม');
        shout.innerHTML =
            '<canvas class="cwheel-confetti" aria-hidden="true"></canvas>' +
            '<div class="cwheel-shout-card">' +
            '<span class="msi cwheel-shout-icon" aria-hidden="true">emoji_events</span>' +
            '<div class="cwheel-shout-label">ผู้ถูกเลือกคือ</div>' +
            '<div class="cwheel-shout-name" aria-live="assertive"></div>' +
            '<div class="cwheel-shout-btns">' +
            '<button type="button" class="btn" data-shout="remove"><span class="msi" aria-hidden="true">close</span>นำชื่อออก</button>' +
            '<button type="button" class="btn btn-primary" data-shout="again"><span class="msi" aria-hidden="true">casino</span>หมุนอีกครั้ง</button>' +
            '<button type="button" class="btn" data-shout="close">ปิด</button>' +
            '</div></div>';
        shout.addEventListener('click', function (e) {
            var b = e.target.closest('[data-shout]');
            if (!b) { if (e.target === shout || e.target.classList.contains('cwheel-confetti')) closeShout(); return; }
            var act = b.dataset.shout;
            if (act === 'remove' && lastWinner) { removeName(lastWinner.id); lastWinner = null; resultEl.hidden = true; }
            closeShout();
            if (act === 'again') setTimeout(spin, 150);
        });
        // จับ Esc ก่อนหน้าต่างผู้ช่วย (ซึ่งจะปิดทั้งหน้าต่าง) — ปิดแค่ป้ายประกาศ
        document.addEventListener('keydown', function (e) {
            if (shout.hidden || !shout.isConnected) return;
            if (e.key === 'Escape') { e.stopImmediatePropagation(); e.preventDefault(); closeShout(); }
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); closeShout(); setTimeout(spin, 150); }
        }, true);
    }

    function announce(item) {
        if (!shout) buildShout();
        // เต็มจอ → ต้องอยู่ในองค์ประกอบที่เต็มจอ ไม่งั้นมองไม่เห็น · ปกติวางไว้ที่ body ให้กลางหน้าจอจริง
        // (ใน .aichat-panel มี backdrop-filter ทำให้ position:fixed อิงกรอบหน้าต่างแทนจอ)
        var host = document.fullscreenElement === root ? root : document.body;
        if (shout.parentNode !== host) host.appendChild(shout);
        shout.querySelector('.cwheel-shout-name').textContent = item.name;
        shout.querySelector('[data-shout="remove"]').hidden = W.auto;
        shout.hidden = false;
        shout.classList.remove('is-in'); void shout.offsetWidth; shout.classList.add('is-in');
        shout.querySelector('[data-shout="again"]').focus({ preventScroll: true });
        confetti(shout.querySelector('.cwheel-confetti'));
        speak(item.name);
    }

    function closeShout() {
        if (!shout) return;
        shout.hidden = true;
        cancelAnimationFrame(confettiRaf);
        if (window.speechSynthesis) speechSynthesis.cancel();
    }

    function confetti(cv) {
        cancelAnimationFrame(confettiRaf);
        if (window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches) return;
        var dpr = window.devicePixelRatio || 1, w = cv.clientWidth, h = cv.clientHeight;
        cv.width = w * dpr; cv.height = h * dpr;
        var c = cv.getContext('2d');
        c.setTransform(dpr, 0, 0, dpr, 0, 0);
        var bits = [];
        for (var i = 0; i < 160; i++) {
            bits.push({
                x: w / 2 + (Math.random() - 0.5) * w * 0.2, y: h * 0.45,
                vx: (Math.random() - 0.5) * 16, vy: -Math.random() * 15 - 5,
                r: Math.random() * Math.PI, vr: (Math.random() - 0.5) * 0.3,
                s: 6 + Math.random() * 7, col: 'hsl(' + Math.floor(Math.random() * 360) + ' 85% 60%)'
            });
        }
        var t0 = performance.now();
        (function step(now) {
            c.clearRect(0, 0, w, h);
            bits.forEach(function (b) {
                b.vy += 0.35; b.vx *= 0.99; b.x += b.vx; b.y += b.vy; b.r += b.vr;
                c.save(); c.translate(b.x, b.y); c.rotate(b.r);
                c.fillStyle = b.col; c.fillRect(-b.s / 2, -b.s / 4, b.s, b.s / 2);
                c.restore();
            });
            if (now - t0 < 4000 && !shout.hidden) confettiRaf = requestAnimationFrame(step);
            else c.clearRect(0, 0, w, h);
        })(t0);
    }

    // เสียงอ่านภาษาไทยที่มีในเครื่อง (Edge มีเสียงออนไลน์ในตัว · Windows ต้องติดตั้งเสียงไทยเพิ่ม)
    function thaiVoices() {
        if (!window.speechSynthesis) return [];
        return speechSynthesis.getVoices().filter(function (v) { return /^th(-|_|$)/i.test(v.lang); });
    }
    function chosenVoice() {
        var list = thaiVoices();
        return list.find(function (v) { return v.voiceURI === prefs.voice; }) || list[0] || null;
    }

    // อ่านชื่อออกเสียงด้วยเสียงภาษาไทย — ไม่มีเสียงไทยก็ไม่อ่าน (เสียงภาษาอื่นอ่านชื่อไทยไม่ออก)
    function speak(name, now) {
        if ((!prefs.speak && !now) || !window.speechSynthesis) return;
        var voice = chosenVoice();
        if (!voice) return;
        speechSynthesis.cancel();
        var u = new SpeechSynthesisUtterance(name);
        u.voice = voice; u.lang = voice.lang; u.rate = parseFloat(prefs.rate) || 0.9;
        // รอให้เสียงฉลองเริ่มก่อนนิดหนึ่ง ไม่ให้ทับกันจนฟังชื่อไม่ชัด
        if (now) { speechSynthesis.speak(u); return; }
        setTimeout(function () { if (shout && !shout.hidden) speechSynthesis.speak(u); }, 700);
    }

    function removeName(id) {
        var i = W.names.findIndex(function (x) { return x.id === id; });
        if (i < 0) return;
        W.out.push(W.names.splice(i, 1)[0]);
        save(); renderNames(); drawWheel();
    }
    function restoreName(id) {
        var i = W.out.findIndex(function (x) { return x.id === id; });
        if (i < 0) return;
        W.names.push(W.out.splice(i, 1)[0]);
        save(); renderNames(); drawWheel();
    }

    function chip(item, out) {
        var b = document.createElement('button');
        b.type = 'button'; b.className = 'cwheel-chip';
        b.textContent = item.name;
        b.title = out ? 'คืนชื่อนี้กลับวงล้อ' : 'นำชื่อนี้ออกจากวงล้อ';
        var x = document.createElement('span');
        x.className = 'msi'; x.setAttribute('aria-hidden', 'true');
        x.textContent = out ? 'undo' : 'close';
        b.appendChild(x);
        b.addEventListener('click', function () {
            if (spinning) return;
            if (out) restoreName(item.id); else { removeName(item.id); if (lastWinner && lastWinner.id === item.id) resultEl.hidden = true; }
        });
        return b;
    }

    function renderNames() {
        var inEl = $('cwheelIn'), outEl = $('cwheelOut');
        inEl.textContent = ''; outEl.textContent = '';
        W.names.forEach(function (it) { inEl.appendChild(chip(it, false)); });
        W.out.forEach(function (it) { outEl.appendChild(chip(it, true)); });
        $('cwheelCount').textContent = W.names.length;
        $('cwheelOutCount').textContent = W.out.length;
        $('cwheelOutHead').hidden = W.out.length === 0;
    }

    function loadRoster(courseId, fresh, soft) {
        if (!canRoster) return Promise.resolve();
        return fetch(base + '/tools/roster?course=' + (courseId || 0), { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); })
            .then(function (data) {
                courseSel.textContent = '';
                var none = document.createElement('option');
                none.value = '0'; none.textContent = data.courses.length ? '— เลือกรายวิชา —' : 'ยังไม่มีรายวิชาที่เปิดสอน';
                courseSel.appendChild(none);
                data.courses.forEach(function (c) {
                    var o = document.createElement('option');
                    o.value = c.id; o.textContent = c.label + ' (' + c.count + ' คน)';
                    courseSel.appendChild(o);
                });
                // soft = ลองวิชาของหน้าที่เปิดอยู่ — ถ้าไม่ใช่วิชาของครูคนนี้ คงรายชื่อเดิมบนวงล้อไว้
                if (soft && !data.course) {
                    courseSel.value = String(W.course || 0);
                    renderNames(); drawWheel();
                    return;
                }
                courseSel.value = String(data.course || 0);
                if (fresh || W.course !== data.course) {
                    W.course = data.course;
                    W.names = data.students.map(function (s) { return { id: 's' + s.id, name: s.name }; });
                    W.out = [];
                    resultEl.hidden = true; lastWinner = null;
                    save();
                }
                renderNames(); drawWheel();
            })
            .catch(function () {
                courseSel.textContent = '';
                var o = document.createElement('option');
                o.value = '0'; o.textContent = 'โหลดรายชื่อไม่สำเร็จ · พิมพ์ชื่อเพิ่มเองได้';
                courseSel.appendChild(o);
            });
    }

    // วิชาเริ่มต้น: วิชาของหน้าที่เปิดอยู่ (/courses/{id}…) ไม่งั้นวิชาที่เลือกไว้ก่อน
    function pageCourse() {
        var m = location.pathname.match(/\/courses\/(\d+)(?:\/|$)/);
        return m ? parseInt(m[1], 10) : 0;
    }

    function wheelInit() {
        if (loaded) return;
        loaded = true;
        renderNames();
        if (!canRoster) {
            $('cwheelCourseRow').hidden = true;
            $('cwheelNames').open = true;
            drawWheel();
            return;
        }
        // อยู่หน้าของรายวิชาอื่น → เปลี่ยนวงล้อเป็นนักเรียนทุกคนของวิชานั้น (รายชื่อมาจากการลงทะเบียนเรียน ไม่ต้องมีกลุ่มเรียน)
        var page = pageCourse();
        if (page && page !== W.course) {
            loadRoster(page, true, true);
            return;
        }
        loadRoster(W.course, !W.course && W.names.length === 0);
    }

    courseSel.addEventListener('change', function () {
        if (spinning) return;
        loadRoster(parseInt(courseSel.value, 10) || 0, true);
    });
    $('cwheelReload').addEventListener('click', function () {
        if (spinning) return;
        loadRoster(W.course || parseInt(courseSel.value, 10) || 0, true);
    });
    spinBtn.addEventListener('click', spin);
    canvas.addEventListener('click', spin);
    $('cwheelRemove').addEventListener('click', function () {
        if (lastWinner) { removeName(lastWinner.id); lastWinner = null; resultEl.hidden = true; }
    });
    $('cwheelRestoreAll').addEventListener('click', function () {
        if (spinning) return;
        W.names = W.names.concat(W.out); W.out = [];
        save(); renderNames(); drawWheel();
    });
    $('cwheelAdd').addEventListener('submit', function (e) {
        e.preventDefault();
        var inp = $('cwheelAddName'), v = inp.value.trim();
        if (!v || spinning) return;
        // วางหลายชื่อทีเดียวได้ (ขึ้นบรรทัด/จุลภาคคั่น)
        v.split(/[\n,]+/).map(function (s) { return s.trim(); }).filter(Boolean).forEach(function (name) {
            W.names.push({ id: 'm' + (++W.seq), name: name.slice(0, 80) });
        });
        inp.value = '';
        save(); renderNames(); drawWheel();
    });

    /* ---------- ตั้งค่าการขานชื่อด้วยเสียงอ่าน ---------- */
    var speakBox = $('cwheelSpeak'), voiceSel = $('cspeakVoice'), rateSel = $('cspeakRate');
    speakBox.checked = !!prefs.speak;
    rateSel.value = String(prefs.rate || 0.9);
    if (!rateSel.value) rateSel.value = '0.9';
    speakBox.addEventListener('change', function () {
        prefs.speak = speakBox.checked; savePrefs(); renderVoices();
        if (prefs.speak) speak('ทดสอบเสียงขานชื่อ', true);
    });
    voiceSel.addEventListener('change', function () { prefs.voice = voiceSel.value; savePrefs(); speak('ทดสอบเสียงขานชื่อ', true); });
    rateSel.addEventListener('change', function () { prefs.rate = rateSel.value; savePrefs(); speak('ทดสอบเสียงขานชื่อ', true); });
    $('cspeakTest').addEventListener('click', function () {
        var sample = (lastWinner && lastWinner.name) || (W.names[0] && W.names[0].name) || 'ทดสอบเสียงขานชื่อ';
        speak(sample, true);
    });

    // รายชื่อเสียงโหลดช้าในบางเบราว์เซอร์ (Chrome/Edge ส่ง voiceschanged ทีหลัง) — วาดใหม่ทุกครั้งที่เปลี่ยน
    function renderVoices() {
        var list = thaiVoices(), has = list.length > 0;
        $('cspeak').hidden = !window.speechSynthesis;
        $('cspeakHelp').hidden = has || !prefs.speak;
        $('cspeakVoiceRow').hidden = !has || !prefs.speak;
        $('cspeakRateRow').hidden = !has || !prefs.speak;
        voiceSel.textContent = '';
        list.forEach(function (v) {
            var o = document.createElement('option');
            o.value = v.voiceURI;
            o.textContent = v.name.replace(/^Microsoft\s+/i, '').replace(/\s*-\s*Thai.*$/i, '') + (v.localService ? '' : ' · ออนไลน์');
            voiceSel.appendChild(o);
        });
        var cur = chosenVoice();
        if (cur) voiceSel.value = cur.voiceURI;
    }
    if (window.speechSynthesis) {
        renderVoices();
        speechSynthesis.addEventListener('voiceschanged', renderVoices);
    }

    var autoBox = $('cwheelAuto');
    autoBox.checked = !!W.auto;
    autoBox.addEventListener('change', function () { W.auto = autoBox.checked; save(); });

    var musicSel = $('cwheelMusic'), effectSel = $('cwheelEffect');
    musicSel.value = prefs.music; effectSel.value = prefs.effect;
    musicSel.addEventListener('change', function () { prefs.music = musicSel.value; savePrefs(); });
    effectSel.addEventListener('change', function () { prefs.effect = effectSel.value; savePrefs(); });

    if (window.ResizeObserver) new ResizeObserver(function () { drawWheel(); }).observe(stage);

    /* ---------- เริ่มต้น ---------- */
    showTool(state.tool === 'wheel' ? 'wheel' : 'timer');
    showPane(state.pane === 'tools' ? 'tools' : 'chat');
})();
