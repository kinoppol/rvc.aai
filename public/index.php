<?php

declare(strict_types=1);

use App\Support\Config;

$root = dirname(__DIR__);

if (!is_file($root . '/vendor/autoload.php')) {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    exit(<<<'HTML'
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ยังไม่ได้ติดตั้ง · RVC Learn</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Thai:wght@400;500;600;700&family=Sarabun:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500&display=swap">
    <style>
        :root{
            --bg:#F4F7F5; --surface:#fff; --surface2:#EDF2EF; --line:#DCE5E0;
            --ink:#16221F; --ink2:#4A5A55; --ink3:#7E8E88;
            --brand:#0E6B60; --brandInk:#fff; --brandSoft:#E3F0ED; --brandLine:#BFDCD6;
            --warn:#9C6206; --warnSoft:#FCF2DF; --warnLine:#EBD6A8;
            --sans:'IBM Plex Sans Thai',system-ui,sans-serif;
            --body:Sarabun,system-ui,sans-serif;
            --mono:'IBM Plex Mono',ui-monospace,monospace;
        }
        @media (prefers-color-scheme:dark){
            :root{
                --bg:#0E1513; --surface:#16211E; --surface2:#1C2925; --line:#2A3A35;
                --ink:#E9F1EE; --ink2:#A9BAB4; --ink3:#7E8E88;
                --brand:#4FB3A4; --brandInk:#08201C; --brandSoft:#14302B; --brandLine:#26463F;
                --warn:#E3AE55; --warnSoft:#2C2415; --warnLine:#4A3A1C;
            }
        }
        *{box-sizing:border-box}
        body{
            margin:0; min-height:100vh; background:var(--bg); color:var(--ink);
            font-family:var(--body); font-size:14px; -webkit-font-smoothing:antialiased;
            display:flex; align-items:center; justify-content:center; padding:32px 20px;
        }
        .panel{
            width:100%; max-width:460px; background:var(--surface);
            border:1px solid var(--line); border-radius:16px;
            box-shadow:0 1px 2px rgba(16,40,35,.06),0 12px 32px rgba(16,40,35,.08);
            padding:30px;
        }
        .brand{display:flex; align-items:center; gap:11px; margin-bottom:22px}
        .brand-mark{
            width:38px; height:38px; border-radius:10px; background:var(--brand);
            color:var(--brandInk); display:grid; place-items:center;
            font:600 13px/1 var(--mono); letter-spacing:.5px;
        }
        .brand-name{font:600 15px/1.3 var(--sans)}
        .brand-name small{display:block; color:var(--ink3); font-weight:400; font-size:12px; margin-top:2px}
        h1{font:600 20px/1.4 var(--sans); margin:0 0 8px}
        p{margin:0 0 18px; color:var(--ink2); line-height:1.7}
        .steps{list-style:none; margin:0 0 22px; padding:0; counter-reset:s}
        .steps li{
            position:relative; padding:0 0 16px 40px; counter-increment:s;
            line-height:1.6; color:var(--ink2);
        }
        .steps li:last-child{padding-bottom:0}
        .steps li::before{
            content:counter(s); position:absolute; left:0; top:-2px;
            width:26px; height:26px; border-radius:50%;
            background:var(--brandSoft); color:var(--brand); border:1px solid var(--brandLine);
            display:grid; place-items:center; font:600 12px/1 var(--mono);
        }
        .steps li::after{
            content:""; position:absolute; left:13px; top:26px; bottom:2px;
            width:1px; background:var(--line);
        }
        .steps li:last-child::after{display:none}
        .cmd{
            display:flex; align-items:center; gap:10px; margin-top:8px;
            background:var(--surface2); border:1px solid var(--line); border-radius:9px;
            padding:9px 12px; font:500 13px/1 var(--mono); color:var(--ink);
        }
        .cmd button{
            margin-left:auto; flex:none; border:1px solid var(--line); background:var(--surface);
            color:var(--ink2); border-radius:7px; padding:5px 10px; cursor:pointer;
            font:500 12px/1 var(--sans);
        }
        .cmd button:hover{border-color:var(--brandLine); color:var(--brand)}
        .note{
            display:flex; gap:9px; background:var(--warnSoft); border:1px solid var(--warnLine);
            color:var(--warn); border-radius:10px; padding:12px 14px;
            font-size:13px; line-height:1.6; margin-bottom:22px;
        }
        .btn{
            display:block; text-align:center; text-decoration:none;
            background:var(--brand); color:var(--brandInk); border-radius:10px;
            padding:12px 16px; font:600 14px/1 var(--sans);
        }
        .btn:hover{opacity:.92}
    </style>
</head>
<body>
    <div class="panel">
        <div class="brand">
            <div class="brand-mark">RV</div>
            <div class="brand-name">RVC Learn<small>ระบบจัดการเรียนรู้ วิทยาลัยเทคนิคร้อยเอ็ด</small></div>
        </div>

        <h1>ยังไม่ได้ติดตั้งไลบรารีของระบบ</h1>
        <p>ระบบยังไม่พร้อมใช้งาน เพราะยังไม่ได้ติดตั้งแพ็กเกจที่จำเป็นด้วย Composer ทำตามขั้นตอนนี้ให้ครบก่อน</p>

        <ol class="steps">
            <li>
                เปิด Command Prompt แล้วเข้าไปที่โฟลเดอร์ของระบบ
                <div class="cmd"><span>cd&nbsp;&quot;<span id="root"></span>&quot;</span><button type="button" data-copy="cd-cmd">คัดลอก</button></div>
            </li>
            <li>
                สั่งติดตั้งแพ็กเกจ แล้วรอจนเสร็จ
                <div class="cmd"><span id="composer-cmd">composer install</span><button type="button" data-copy="composer-cmd">คัดลอก</button></div>
            </li>
            <li>เปิดหน้าติดตั้งเพื่อตั้งค่าฐานข้อมูลและบัญชีผู้ดูแลระบบ</li>
        </ol>

        <div class="note"><span>△</span><div>ถ้ายังไม่มี Composer ให้ติดตั้งจาก <span style="font-family:var(--mono)">getcomposer.org</span> ก่อน</div></div>

        <a class="btn" href="install.php">ไปที่หน้าติดตั้ง</a>
    </div>

    <script>
        (function () {
            var root = window.location.pathname.replace(/\/[^\/]*$/, '') || '/';
            document.getElementById('root').textContent = 'C:\\xampp\\htdocs' + root.replace(/\//g, '\\');
            document.querySelectorAll('[data-copy]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var id = btn.getAttribute('data-copy');
                    var text = id === 'cd-cmd'
                        ? 'cd "' + document.getElementById('root').textContent + '"'
                        : document.getElementById(id).textContent;
                    navigator.clipboard && navigator.clipboard.writeText(text).then(function () {
                        var old = btn.textContent; btn.textContent = 'คัดลอกแล้ว';
                        setTimeout(function () { btn.textContent = old; }, 1500);
                    });
                });
            });
        })();
    </script>
</body>
</html>
HTML);
}

require $root . '/vendor/autoload.php';

if (!Config::isInstalled()) {
    header('Location: install.php');
    exit;
}

session_name('RVCSESSID');
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure' => ($_SERVER['HTTPS'] ?? '') === 'on',
]);
ini_set('session.use_strict_mode', '1');
session_start();

/** @var Slim\App $app */
$app = require $root . '/app/bootstrap.php';
$app->run();
