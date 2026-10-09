<?php

/**
 * ตัวติดตั้งระบบ RVC Learn
 *
 * ทำงานได้ตั้งแต่ยังไม่มีไฟล์ตั้งค่าและยังไม่ได้ติดตั้งไลบรารี
 * รองรับการติดตั้งซ้ำ โดยเลือกได้ว่าจะเก็บข้อมูลเดิมหรือล้างแล้วติดตั้งใหม่
 */

declare(strict_types=1);

use App\Auth\Auth;
use App\Install\Installer;
use App\Install\Requirements;
use App\Migration\Migrator;
use App\Support\Config;
use App\Support\Database;
use App\Support\Paths;

$root = dirname(__DIR__);
$hasVendor = is_file($root . '/vendor/autoload.php');

if ($hasVendor) {
    require $root . '/vendor/autoload.php';
} else {
    // ยังไม่ได้ composer install — โหลดเท่าที่ต้องใช้เพื่อแสดงหน้าตรวจความพร้อม
    require $root . '/app/Support/Paths.php';
    require $root . '/app/Install/Requirements.php';
}

session_name('RVCINSTALL');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();

mb_internal_encoding('UTF-8');
date_default_timezone_set('Asia/Bangkok');

const STEP_LABELS = [
    1 => 'ตรวจความพร้อม',
    2 => 'ฐานข้อมูล',
    3 => 'เว็บและผู้ดูแลระบบ',
    4 => 'ติดตั้ง',
];

$_SESSION['install'] ??= [];
$state = &$_SESSION['install'];
$errors = [];
$notices = [];

$action = (string) ($_POST['action'] ?? '');
$step = max(1, min(4, (int) ($_GET['step'] ?? 1)));

// ---------------------------------------------------------------- ฟังก์ชันช่วย

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function csrf_token(): string
{
    $_SESSION['install_csrf'] ??= bin2hex(random_bytes(32));

    return $_SESSION['install_csrf'];
}

function csrf_ok(): bool
{
    return isset($_POST['_token'], $_SESSION['install_csrf'])
        && hash_equals($_SESSION['install_csrf'], (string) $_POST['_token']);
}

function client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

function guess_base_url(): string
{
    $scheme = (($_SERVER['HTTPS'] ?? 'off') !== 'off') ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $path = rtrim(dirname((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH)), '/');

    return $scheme . '://' . $host . $path;
}

function redirect_step(int $step): never
{
    header('Location: install.php?step=' . $step);
    exit;
}

function json_out(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/** @return array{ok:bool,message:string,details:list<string>} */
function inspect_database(array $db): array
{
    try {
        $server = Database::connectServer($db);
    } catch (Throwable $e) {
        return [
            'ok' => false,
            'message' => 'เชื่อมต่อเซิร์ฟเวอร์ฐานข้อมูลไม่สำเร็จ: ' . $e->getMessage(),
            'details' => ['ตรวจสอบว่า MariaDB ทำงานอยู่ และชื่อผู้ใช้กับรหัสผ่านถูกต้อง'],
        ];
    }

    $info = Database::inspectServer($server);
    if (!$info['supported']) {
        return ['ok' => false, 'message' => $info['message'], 'details' => []];
    }

    $details = [$info['message']];

    if (!Database::databaseExists($server, $db['database'])) {
        $details[] = sprintf('ยังไม่มีฐานข้อมูล "%s" ระบบจะสร้างให้ตอนติดตั้ง', $db['database']);

        return ['ok' => true, 'message' => 'เชื่อมต่อได้', 'details' => $details];
    }

    $connection = Database::connect($db);
    $migrator = new Migrator($connection, $db['prefix']);
    $existing = Installer::existingAppTables($connection, $migrator);

    $details[] = $existing === []
        ? sprintf('พบฐานข้อมูล "%s" และยังไม่มีตารางของระบบนี้', $db['database'])
        : sprintf('พบตารางของระบบนี้อยู่แล้ว %d ตาราง จะให้เลือกในขั้นถัดไปว่าจะเก็บหรือล้าง', count($existing));

    return ['ok' => true, 'message' => 'เชื่อมต่อได้', 'details' => $details];
}

// ---------------------------------------------------------------- ล็อกการติดตั้งซ้ำ

$locked = $hasVendor && Installer::isLocked();
$authorized = ($_SESSION['install_authorized'] ?? false) === true;

if ($locked && !$authorized && $action === 'unlock' && csrf_ok()) {
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    try {
        $config = Config::load();
        $pdo = Database::connect($config->get('db'));
        $auth = new Auth($pdo, (string) $config->get('db.prefix', ''));

        if ($auth->isThrottled($username, client_ip(), 'install')) {
            $errors[] = 'พยายามยืนยันตัวตนผิดหลายครั้งเกินไป กรุณารอ 15 นาทีแล้วลองใหม่';
        } else {
            $user = $auth->findByUsername($username);
            $valid = $user !== null
                && $user['role'] === 'admin'
                && $user['status'] === 'active'
                && password_verify($password, $user['password_hash']);

            $auth->record($username, client_ip(), 'install', $valid);

            if ($valid) {
                $_SESSION['install_authorized'] = true;
                $auth->log('install.unlock', $username);
                redirect_step(1);
            }

            $errors[] = 'ชื่อผู้ใช้หรือรหัสผ่านของผู้ดูแลระบบไม่ถูกต้อง';
        }
    } catch (Throwable $e) {
        $errors[] = 'ตรวจสอบบัญชีผู้ดูแลระบบไม่ได้ เพราะเชื่อมต่อฐานข้อมูลเดิมไม่สำเร็จ: ' . $e->getMessage();
        $errors[] = 'หากต้องการติดตั้งใหม่ ให้ลบไฟล์ ' . Paths::lockFile() . ' ด้วยตนเอง';
    }
}

// ---------------------------------------------------------------- จัดการฟอร์มแต่ละขั้น

if ($action !== '' && $action !== 'unlock' && (!$locked || $authorized)) {
    if (!csrf_ok()) {
        $errors[] = 'เซสชันหมดอายุ กรุณาเริ่มขั้นตอนนี้ใหม่';
        $action = '';
    }
}

if ($action === 'testdb' && (!$locked || $authorized)) {
    json_out(inspect_database([
        'host' => trim((string) ($_POST['host'] ?? 'localhost')),
        'port' => (int) ($_POST['port'] ?? 3306),
        'database' => trim((string) ($_POST['database'] ?? '')),
        'username' => trim((string) ($_POST['username'] ?? '')),
        'password' => (string) ($_POST['password'] ?? ''),
        'prefix' => trim((string) ($_POST['prefix'] ?? 'rvc_')),
    ]));
}

if ($action === 'step1') {
    if (Requirements::passes()) {
        redirect_step(2);
    }
    $errors[] = 'ยังมีข้อกำหนดที่ไม่ผ่าน กรุณาแก้ไขตามคำแนะนำแล้วกดตรวจสอบใหม่';
    $step = 1;
}

if ($action === 'step2') {
    $db = [
        'host' => trim((string) ($_POST['host'] ?? '')),
        'port' => (int) ($_POST['port'] ?? 3306),
        'database' => trim((string) ($_POST['database'] ?? '')),
        'username' => trim((string) ($_POST['username'] ?? '')),
        'password' => (string) ($_POST['password'] ?? ''),
        'prefix' => trim((string) ($_POST['prefix'] ?? '')),
    ];

    if ($db['host'] === '' || $db['database'] === '' || $db['username'] === '') {
        $errors[] = 'กรุณากรอกที่อยู่เซิร์ฟเวอร์ ชื่อฐานข้อมูล และชื่อผู้ใช้ให้ครบ';
    }

    if (preg_match('/^[A-Za-z0-9_]+$/', $db['database']) !== 1) {
        $errors[] = 'ชื่อฐานข้อมูลใช้ได้เฉพาะตัวอักษรภาษาอังกฤษ ตัวเลข และขีดล่าง';
    }

    if ($db['prefix'] !== '' && preg_match('/^[a-z0-9_]+$/', $db['prefix']) !== 1) {
        $errors[] = 'คำนำหน้าตารางใช้ได้เฉพาะตัวอักษรพิมพ์เล็ก ตัวเลข และขีดล่าง';
    }

    if ($errors === []) {
        $check = inspect_database($db);
        if (!$check['ok']) {
            $errors[] = $check['message'];
        } else {
            $state['db'] = $db;
            redirect_step(3);
        }
    }

    $state['db'] = $db;
    $step = 2;
}

if ($action === 'step3') {
    $site = [
        'name' => trim((string) ($_POST['site_name'] ?? '')),
        'college' => trim((string) ($_POST['college'] ?? '')),
        'url' => trim((string) ($_POST['url'] ?? '')),
        'timezone' => trim((string) ($_POST['timezone'] ?? 'Asia/Bangkok')),
        'academic_year' => (int) ($_POST['academic_year'] ?? 0),
        'semester' => (int) ($_POST['semester'] ?? 1),
    ];

    $admin = [
        'username' => trim((string) ($_POST['username'] ?? '')),
        'full_name' => trim((string) ($_POST['full_name'] ?? '')),
        'email' => trim((string) ($_POST['email'] ?? '')),
        'password' => (string) ($_POST['password'] ?? ''),
    ];
    $confirm = (string) ($_POST['password_confirm'] ?? '');
    $mode = ($_POST['mode'] ?? 'keep') === 'fresh' ? 'fresh' : 'keep';

    if ($site['name'] === '' || $site['college'] === '' || $site['url'] === '') {
        $errors[] = 'กรุณากรอกชื่อระบบ ชื่อวิทยาลัย และที่อยู่เว็บให้ครบ';
    }

    if (!in_array($site['timezone'], timezone_identifiers_list(), true)) {
        $errors[] = 'เขตเวลาไม่ถูกต้อง';
    }

    if ($site['academic_year'] < 2500 || $site['academic_year'] > 2700) {
        $errors[] = 'ปีการศึกษาต้องเป็นปี พ.ศ. เช่น 2569';
    }

    if (preg_match('/^[a-z0-9._-]{3,64}$/', $admin['username']) !== 1) {
        $errors[] = 'ชื่อผู้ใช้ของผู้ดูแลระบบต้องยาว 3 ตัวขึ้นไป ใช้ได้เฉพาะ a-z 0-9 . _ -';
    }

    if ($admin['full_name'] === '') {
        $errors[] = 'กรุณากรอกชื่อ-สกุลของผู้ดูแลระบบ';
    }

    if ($admin['email'] !== '' && !filter_var($admin['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'รูปแบบอีเมลไม่ถูกต้อง';
    }

    if (mb_strlen($admin['password']) < 8) {
        $errors[] = 'รหัสผ่านต้องยาวอย่างน้อย 8 ตัวอักษร';
    } elseif (preg_match('/[A-Za-z]/', $admin['password']) !== 1 || preg_match('/\d/', $admin['password']) !== 1) {
        $errors[] = 'รหัสผ่านต้องมีทั้งตัวอักษรและตัวเลข';
    }

    if ($admin['password'] !== $confirm) {
        $errors[] = 'รหัสผ่านทั้งสองช่องไม่ตรงกัน';
    }

    if ($mode === 'fresh' && trim((string) ($_POST['drop_confirm'] ?? '')) !== ($state['db']['database'] ?? '')) {
        $errors[] = 'ถ้าเลือกล้างข้อมูลเดิม ต้องพิมพ์ชื่อฐานข้อมูลให้ตรงเพื่อยืนยัน';
    }

    $state['site'] = $site;
    $state['admin'] = $admin;
    $state['mode'] = $mode;

    if ($errors === []) {
        redirect_step(4);
    }

    $step = 3;
}

$installResult = null;
$summary = $state;

if ($action === 'install' && isset($state['db'], $state['site'], $state['admin'])) {
    $installResult = run_installation($state);
    $summary = $state;
    $step = 4;

    if ($installResult['ok']) {
        unset($_SESSION['install'], $_SESSION['install_authorized']);
    }
}

// กันการข้ามขั้นตอนด้วยการแก้ URL
if ($installResult === null && (!$locked || $authorized)) {
    if ($step >= 3 && !isset($state['db'])) {
        redirect_step(2);
    }
    if ($step === 4 && !isset($state['site'], $state['admin'])) {
        redirect_step(3);
    }
}

/**
 * ขั้นตอนติดตั้งจริง — คืน log ทีละบรรทัดเพื่อแสดงให้ผู้ติดตั้งเห็นว่าเกิดอะไรขึ้น
 *
 * @return array{ok:bool,log:list<array{ok:bool,text:string,detail:?string}>}
 */
function run_installation(array $state): array
{
    $log = [];
    $add = static function (bool $ok, string $text, ?string $detail = null) use (&$log): void {
        $log[] = ['ok' => $ok, 'text' => $text, 'detail' => $detail];
    };

    $db = $state['db'];
    $site = $state['site'];
    $mode = $state['mode'] ?? 'keep';

    try {
        $server = Database::connectServer($db);
        if (!Database::databaseExists($server, $db['database'])) {
            Database::createDatabase($server, $db['database']);
            $add(true, sprintf('สร้างฐานข้อมูล "%s" แล้ว', $db['database']));
        } else {
            $add(true, sprintf('พบฐานข้อมูล "%s" อยู่แล้ว', $db['database']));
        }

        $connection = Database::connect($db);
        $migrator = new Migrator($connection, $db['prefix']);

        if ($mode === 'fresh') {
            $dropped = Installer::dropAppTables($connection, $migrator);
            $add(true, $dropped === []
                ? 'ไม่มีตารางเดิมของระบบให้ล้าง'
                : sprintf('ล้างตารางเดิมของระบบแล้ว %d ตาราง', count($dropped)));
        } else {
            $add(true, 'เก็บข้อมูลเดิมไว้ จะปรับปรุงเฉพาะโครงสร้างที่ยังไม่ได้ทำ');
        }

        // เก็บกุญแจเข้ารหัสเดิมไว้ ไม่งั้นคีย์ AI ของครูที่เข้ารหัสไว้แล้วจะอ่านไม่ออก
        $existingKey = null;
        if ($mode === 'keep' && is_file(Paths::configFile())) {
            try {
                $existingKey = (string) Config::load()->get('app.key') ?: null;
            } catch (Throwable) {
                $existingKey = null;
            }
        }

        Config::write(Installer::buildConfig(['db' => $db, 'site' => $site], $existingKey));
        $add(true, 'เขียนไฟล์ตั้งค่าระบบแล้ว', Paths::configFile());

        $steps = $migrator->migrate();
        if ($steps === []) {
            $add(true, 'โครงสร้างฐานข้อมูลเป็นรุ่นล่าสุดอยู่แล้ว');
        }

        foreach ($steps as $migration) {
            $add(
                $migration['ok'],
                sprintf('%s (%d ms)', $migration['description'] ?: $migration['name'], $migration['duration_ms']),
                $migration['error']
            );

            if (!$migration['ok']) {
                $add(false, 'หยุดการติดตั้งไว้ก่อน เพราะปรับปรุงโครงสร้างฐานข้อมูลไม่สำเร็จ');

                return ['ok' => false, 'log' => $log];
            }
        }

        $adminId = Installer::createAdmin($connection, $db['prefix'], $state['admin']);
        $add(true, sprintf('ตั้งค่าบัญชีผู้ดูแลระบบ "%s" แล้ว', $state['admin']['username']));

        Installer::seedSettings($connection, $db['prefix'], $site);
        Installer::seedCurrentTerm($connection, $db['prefix'], $site['academic_year'], $site['semester']);
        $add(true, sprintf('ตั้งค่าเริ่มต้นและภาคเรียนที่ %d / %d แล้ว', $site['semester'], $site['academic_year']));

        $serverInfo = Database::inspectServer($connection);
        Installer::writeLock([
            'version' => Installer::VERSION,
            'installed_at' => date('Y-m-d H:i:s'),
            'installed_by' => $state['admin']['username'],
            'admin_id' => $adminId,
            'php' => PHP_VERSION,
            'database' => $serverInfo['name'] . ' ' . $serverInfo['version'],
            'mode' => $mode,
        ]);
        $add(true, 'ติดตั้งเสร็จสมบูรณ์');

        return ['ok' => true, 'log' => $log];
    } catch (Throwable $e) {
        $add(false, 'เกิดข้อผิดพลาดระหว่างติดตั้ง', $e->getMessage());

        return ['ok' => false, 'log' => $log];
    }
}

// ---------------------------------------------------------------- ส่วนแสดงผล

$baseAssets = 'assets/css/app.css';
$defaults = [
    'db' => $state['db'] ?? [
        'host' => 'localhost',
        'port' => 3306,
        'database' => 'rvc_learn',
        'username' => 'root',
        'password' => '',
        'prefix' => 'rvc_',
    ],
    'site' => $state['site'] ?? [
        'name' => 'RVC Learn',
        'college' => 'วิทยาลัยเทคนิคร้อยเอ็ด',
        'url' => guess_base_url(),
        'timezone' => 'Asia/Bangkok',
        'academic_year' => 2569,
        'semester' => 1,
    ],
    'admin' => $state['admin'] ?? ['username' => '', 'full_name' => '', 'email' => '', 'password' => ''],
];

?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ติดตั้งระบบ · RVC Learn</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Thai:wght@400;500;600;700&family=Sarabun:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500&display=swap">
    <link rel="stylesheet" href="<?= h($baseAssets) ?>">
</head>
<body>
<div class="wizard">
    <div class="wizard-head">
        <div class="brand-mark">RV</div>
        <div>
            <h1>ติดตั้งระบบ RVC Learn</h1>
            <div class="text-muted" style="font-size:13px;margin-top:4px">ระบบจัดการเรียนรู้ของวิทยาลัยเทคนิคร้อยเอ็ด</div>
        </div>
    </div>

    <?php foreach ($errors as $message): ?>
        <div class="alert alert-error"><span>✕</span><div><?= h($message) ?></div></div>
    <?php endforeach; ?>
    <?php foreach ($notices as $message): ?>
        <div class="alert alert-info"><span>ℹ</span><div><?= h($message) ?></div></div>
    <?php endforeach; ?>

<?php if ($locked && !$authorized): ?>

    <div class="alert alert-warning">
        <span>△</span>
        <div>
            <strong>ระบบนี้ติดตั้งไว้แล้ว</strong><br>
            การติดตั้งซ้ำจะเปลี่ยนแปลงไฟล์ตั้งค่าและอาจกระทบข้อมูลเดิม
            จึงต้องยืนยันตัวตนด้วยบัญชีผู้ดูแลระบบก่อน
        </div>
    </div>

    <div class="card" style="max-width:420px">
        <h2>ยืนยันตัวตนผู้ดูแลระบบ</h2>
        <div class="card-sub">ใช้บัญชีผู้ดูแลระบบของการติดตั้งเดิม</div>
        <form method="post">
            <input type="hidden" name="_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="unlock">
            <div class="field">
                <label for="u">ชื่อผู้ใช้</label>
                <input class="input" type="text" id="u" name="username" autocomplete="username" required autofocus>
            </div>
            <div class="field">
                <label for="p">รหัสผ่าน</label>
                <input class="input" type="password" id="p" name="password" autocomplete="current-password" required>
            </div>
            <button type="submit" class="btn btn-primary">ยืนยันและเข้าสู่ตัวติดตั้ง</button>
        </form>
        <div class="hint text-muted" style="font-size:12.5px;margin-top:14px;line-height:1.7">
            ถ้าลืมรหัสผ่านผู้ดูแลระบบ ให้ลบไฟล์นี้ออกด้วยตนเองแล้วเปิดหน้านี้ใหม่<br>
            <code style="word-break:break-all"><?= h(Paths::lockFile()) ?></code>
        </div>
    </div>

<?php else: ?>

    <div class="steps">
        <?php foreach (STEP_LABELS as $number => $label): ?>
            <div class="step <?= $number === $step ? 'is-current' : ($number < $step ? 'is-done' : '') ?>">
                <span class="no"><?= $number < $step ? '✓' : $number ?></span><?= h($label) ?>
            </div>
        <?php endforeach; ?>
    </div>

<?php if ($step === 1):
    $groups = Requirements::all();
    $renderGroup = static function (array $checks, bool $optional = false): void {
        echo '<ul class="check-list">';
        foreach ($checks as $check) {
            $mark = $check['ok'] ? 'pass' : ($optional ? 'skip' : 'fail');
            $symbol = $check['ok'] ? '✓' : ($optional ? '○' : '✕');
            echo '<li><span class="mark ' . $mark . '">' . $symbol . '</span><div class="body">';
            echo '<div class="label">' . h($check['label']) . '</div>';
            if (!$check['ok'] && !empty($check['hint'])) {
                echo '<div class="hint">' . preg_replace('/\s\s(.+)$/u', ' <code>$1</code>', h($check['hint'])) . '</div>';
            }
            echo '</div><div class="value">' . h($check['value']) . '</div></li>';
        }
        echo '</ul>';
    };
    ?>

    <div class="card">
        <h2>ความพร้อมของ PHP</h2>
        <div class="card-sub">ต้องผ่านทุกข้อจึงจะติดตั้งต่อได้</div>
        <?php $renderGroup($groups['php']); ?>
    </div>

    <div class="card">
        <h2>ส่วนขยายที่จำเป็น</h2>
        <div class="card-sub">เปิดใช้ใน php.ini แล้วรีสตาร์ท Apache</div>
        <?php $renderGroup($groups['extensions']); ?>
    </div>

    <div class="card">
        <h2>สิทธิ์การเขียนไฟล์</h2>
        <div class="card-sub">ทดสอบด้วยการเขียนไฟล์จริงลงในแต่ละโฟลเดอร์</div>
        <?php $renderGroup($groups['writable']); ?>
    </div>

    <div class="card">
        <h2>ส่วนขยายที่แนะนำ</h2>
        <div class="card-sub">ไม่มีก็ติดตั้งต่อได้ แต่บางฟีเจอร์จะใช้ไม่ได้</div>
        <?php $renderGroup($groups['optional'], true); ?>
    </div>

    <div class="card">
        <h2>การตั้งค่าเซิร์ฟเวอร์</h2>
        <div class="card-sub">เป็นคำแนะนำ ไม่บังคับ</div>
        <?php $renderGroup($groups['server'], true); ?>
    </div>

    <form method="post" class="form-actions">
        <input type="hidden" name="_token" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="step1">
        <button type="button" class="btn" onclick="location.reload()">ตรวจสอบใหม่</button>
        <div class="spacer"></div>
        <button type="submit" class="btn btn-primary" <?= Requirements::passes() ? '' : 'disabled' ?>>
            ถัดไป · ตั้งค่าฐานข้อมูล
        </button>
    </form>

<?php elseif ($step === 2): $db = $defaults['db']; ?>

    <div class="card">
        <h2>เชื่อมต่อฐานข้อมูล MariaDB</h2>
        <div class="card-sub">ต้องการ MariaDB 10.4 ขึ้นไป ถ้ายังไม่มีฐานข้อมูล ระบบจะสร้างให้อัตโนมัติ</div>

        <form method="post" id="dbForm">
            <input type="hidden" name="_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="step2">

            <div class="grid-2">
                <div class="field">
                    <label for="host">ที่อยู่เซิร์ฟเวอร์</label>
                    <input class="input mono" type="text" id="host" name="host" value="<?= h((string) $db['host']) ?>" required>
                </div>
                <div class="field">
                    <label for="port">พอร์ต</label>
                    <input class="input mono" type="number" id="port" name="port" value="<?= h((string) $db['port']) ?>" required>
                </div>
            </div>

            <div class="field">
                <label for="database">ชื่อฐานข้อมูล</label>
                <input class="input mono" type="text" id="database" name="database" value="<?= h((string) $db['database']) ?>" required>
            </div>

            <div class="grid-2">
                <div class="field">
                    <label for="username">ชื่อผู้ใช้ฐานข้อมูล</label>
                    <input class="input mono" type="text" id="username" name="username" value="<?= h((string) $db['username']) ?>" required>
                </div>
                <div class="field">
                    <label for="password">รหัสผ่านฐานข้อมูล</label>
                    <input class="input mono" type="password" id="password" name="password" value="<?= h((string) $db['password']) ?>">
                </div>
            </div>

            <div class="field">
                <label for="prefix">คำนำหน้าชื่อตาราง</label>
                <input class="input mono" type="text" id="prefix" name="prefix" value="<?= h((string) $db['prefix']) ?>">
                <div class="hint">ช่วยให้ใช้ฐานข้อมูลร่วมกับระบบอื่นได้ และทำให้ตอนล้างข้อมูลระบบแตะเฉพาะตารางของตัวเอง</div>
            </div>

            <div id="testResult"></div>

            <div class="form-actions">
                <button type="button" class="btn" onclick="rvcTestDb()">ทดสอบการเชื่อมต่อ</button>
                <div class="spacer"></div>
                <a class="btn" href="install.php?step=1">ย้อนกลับ</a>
                <button type="submit" class="btn btn-primary">ถัดไป · ตั้งค่าเว็บ</button>
            </div>
        </form>
    </div>

    <script>
        function rvcTestDb() {
            var form = document.getElementById('dbForm');
            var box = document.getElementById('testResult');
            var data = new FormData(form);
            data.set('action', 'testdb');
            box.innerHTML = '<div class="alert alert-info"><span>◌</span><div>กำลังทดสอบ...</div></div>';

            fetch('install.php', { method: 'POST', body: data })
                .then(function (r) { return r.json(); })
                .then(function (result) {
                    var cls = result.ok ? 'alert-success' : 'alert-error';
                    var mark = result.ok ? '✓' : '✕';
                    var lines = (result.details || []).map(function (d) { return '<div>' + d + '</div>'; }).join('');
                    box.innerHTML = '<div class="alert ' + cls + '"><span>' + mark + '</span><div><strong>'
                        + result.message + '</strong>' + lines + '</div></div>';
                })
                .catch(function () {
                    box.innerHTML = '<div class="alert alert-error"><span>✕</span><div>ทดสอบไม่สำเร็จ กรุณาลองใหม่</div></div>';
                });
        }
    </script>

<?php elseif ($step === 3):
    $site = $defaults['site'];
    $admin = $defaults['admin'];
    $existingTables = [];
    try {
        $connection = Database::connect($state['db']);
        $existingTables = Installer::existingAppTables($connection, new Migrator($connection, $state['db']['prefix']));
    } catch (Throwable) {
        $existingTables = [];
    }
    ?>

    <form method="post">
        <input type="hidden" name="_token" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="step3">

        <?php if ($existingTables !== []): ?>
            <div class="card">
                <h2>พบข้อมูลเดิมในฐานข้อมูลนี้</h2>
                <div class="card-sub">เลือกว่าจะทำอย่างไรกับตารางของระบบที่มีอยู่แล้ว <?= count($existingTables) ?> ตาราง</div>

                <label class="alert alert-info" style="cursor:pointer;align-items:flex-start">
                    <input type="radio" name="mode" value="keep" checked style="margin-top:4px">
                    <div>
                        <strong>เก็บข้อมูลเดิมไว้ (แนะนำ)</strong><br>
                        ปรับปรุงเฉพาะโครงสร้างที่ยังไม่ได้ทำ ผู้ใช้ รายวิชา และข้อสอบเดิมยังอยู่ครบ
                    </div>
                </label>

                <label class="alert alert-error" style="cursor:pointer;align-items:flex-start">
                    <input type="radio" name="mode" value="fresh" style="margin-top:4px" onchange="document.getElementById('dropBox').hidden = !this.checked">
                    <div>
                        <strong>ล้างข้อมูลเดิมแล้วติดตั้งใหม่</strong><br>
                        ลบเฉพาะตารางของระบบนี้ทั้งหมดแล้วสร้างใหม่ ข้อมูลที่หายจะกู้คืนไม่ได้
                    </div>
                </label>

                <div id="dropBox" hidden>
                    <div class="table-scroll" style="margin-bottom:12px">
                        <table class="data">
                            <thead><tr><th>ตาราง</th><th style="width:120px">จำนวนแถว</th></tr></thead>
                            <tbody>
                            <?php foreach ($existingTables as $table => $rows): ?>
                                <tr><td class="mono"><?= h($table) ?></td><td class="mono"><?= number_format($rows) ?></td></tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="field">
                        <label for="drop_confirm">พิมพ์ชื่อฐานข้อมูล <strong><?= h((string) $state['db']['database']) ?></strong> เพื่อยืนยันการล้าง</label>
                        <input class="input mono" type="text" id="drop_confirm" name="drop_confirm" autocomplete="off">
                    </div>
                </div>
            </div>
        <?php else: ?>
            <input type="hidden" name="mode" value="keep">
        <?php endif; ?>

        <div class="card">
            <h2>ข้อมูลเว็บ</h2>
            <div class="card-sub">แก้ไขภายหลังได้ในเมนูตั้งค่า</div>

            <div class="grid-2">
                <div class="field">
                    <label for="site_name">ชื่อระบบ</label>
                    <input class="input" type="text" id="site_name" name="site_name" value="<?= h((string) $site['name']) ?>" required>
                </div>
                <div class="field">
                    <label for="college">ชื่อวิทยาลัย</label>
                    <input class="input" type="text" id="college" name="college" value="<?= h((string) $site['college']) ?>" required>
                </div>
            </div>

            <div class="field">
                <label for="url">ที่อยู่เว็บ</label>
                <input class="input mono" type="text" id="url" name="url" value="<?= h((string) $site['url']) ?>" required>
                <div class="hint">ใช้สร้างลิงก์ภายในระบบ ต้องไม่มีเครื่องหมาย / ปิดท้าย</div>
            </div>

            <div class="grid-2">
                <div class="field">
                    <label for="academic_year">ปีการศึกษา (พ.ศ.)</label>
                    <input class="input mono" type="number" id="academic_year" name="academic_year" value="<?= h((string) $site['academic_year']) ?>" required>
                </div>
                <div class="field">
                    <label for="semester">ภาคเรียนที่</label>
                    <select class="input" id="semester" name="semester">
                        <?php foreach ([1, 2, 3] as $number): ?>
                            <option value="<?= $number ?>" <?= (int) $site['semester'] === $number ? 'selected' : '' ?>><?= $number ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="field">
                <label for="timezone">เขตเวลา</label>
                <input class="input mono" type="text" id="timezone" name="timezone" value="<?= h((string) $site['timezone']) ?>" required>
            </div>
        </div>

        <div class="card">
            <h2>บัญชีผู้ดูแลระบบ</h2>
            <div class="card-sub">ใช้เข้าสู่ระบบครั้งแรก ถ้ามีชื่อผู้ใช้นี้อยู่แล้วระบบจะตั้งรหัสผ่านใหม่ให้</div>

            <div class="grid-2">
                <div class="field">
                    <label for="admin_username">ชื่อผู้ใช้</label>
                    <input class="input mono" type="text" id="admin_username" name="username" value="<?= h((string) $admin['username']) ?>" autocomplete="username" required>
                    <div class="hint">ใช้ได้เฉพาะ a-z 0-9 . _ - ยาว 3 ตัวขึ้นไป</div>
                </div>
                <div class="field">
                    <label for="full_name">ชื่อ-สกุล</label>
                    <input class="input" type="text" id="full_name" name="full_name" value="<?= h((string) $admin['full_name']) ?>" required>
                </div>
            </div>

            <div class="field">
                <label for="email">อีเมล (ไม่บังคับ)</label>
                <input class="input mono" type="email" id="email" name="email" value="<?= h((string) $admin['email']) ?>">
            </div>

            <div class="grid-2">
                <div class="field">
                    <label for="admin_password">รหัสผ่าน</label>
                    <input class="input" type="password" id="admin_password" name="password" autocomplete="new-password" required oninput="rvcStrength(this.value)">
                    <div class="hint" id="strength">อย่างน้อย 8 ตัวอักษร มีทั้งตัวอักษรและตัวเลข</div>
                </div>
                <div class="field">
                    <label for="password_confirm">ยืนยันรหัสผ่าน</label>
                    <input class="input" type="password" id="password_confirm" name="password_confirm" autocomplete="new-password" required>
                </div>
            </div>
        </div>

        <div class="form-actions">
            <a class="btn" href="install.php?step=2">ย้อนกลับ</a>
            <div class="spacer"></div>
            <button type="submit" class="btn btn-primary">ถัดไป · ตรวจทานก่อนติดตั้ง</button>
        </div>
    </form>

    <script>
        function rvcStrength(value) {
            var box = document.getElementById('strength');
            var score = 0;
            if (value.length >= 8) score++;
            if (/[A-Za-z]/.test(value) && /\d/.test(value)) score++;
            if (value.length >= 14) score++;
            if (/[^A-Za-z0-9]/.test(value)) score++;

            var labels = ['ยังไม่ผ่านเกณฑ์', 'พอใช้ได้', 'ดี', 'ดีมาก', 'แข็งแรงมาก'];
            box.textContent = 'ความแข็งแรงของรหัสผ่าน: ' + labels[score];
        }
    </script>

<?php elseif ($step === 4 && $installResult === null): ?>

    <div class="card">
        <h2>ตรวจทานก่อนติดตั้ง</h2>
        <div class="card-sub">ตรวจสอบให้ถูกต้องแล้วกดเริ่มติดตั้ง</div>

        <div class="table-scroll">
            <table class="data">
                <tbody>
                <tr><td style="width:220px">ฐานข้อมูล</td><td class="mono"><?= h($state['db']['username']) ?>@<?= h($state['db']['host']) ?>:<?= h((string) $state['db']['port']) ?> · <?= h($state['db']['database']) ?></td></tr>
                <tr><td>คำนำหน้าตาราง</td><td class="mono"><?= h($state['db']['prefix']) ?: '<span class="text-muted">ไม่มี</span>' ?></td></tr>
                <tr><td>ข้อมูลเดิม</td><td>
                    <?php if (($state['mode'] ?? 'keep') === 'fresh'): ?>
                        <span class="chip chip-err">ล้างแล้วติดตั้งใหม่</span>
                    <?php else: ?>
                        <span class="chip chip-ok">เก็บข้อมูลเดิมไว้</span>
                    <?php endif; ?>
                </td></tr>
                <tr><td>ชื่อระบบ</td><td><?= h($state['site']['name']) ?> · <?= h($state['site']['college']) ?></td></tr>
                <tr><td>ที่อยู่เว็บ</td><td class="mono"><?= h($state['site']['url']) ?></td></tr>
                <tr><td>ภาคเรียน</td><td class="mono"><?= h((string) $state['site']['semester']) ?> / <?= h((string) $state['site']['academic_year']) ?></td></tr>
                <tr><td>ผู้ดูแลระบบ</td><td><?= h($state['admin']['full_name']) ?> (<span class="mono"><?= h($state['admin']['username']) ?></span>)</td></tr>
                </tbody>
            </table>
        </div>

        <?php if (($state['mode'] ?? 'keep') === 'fresh'): ?>
            <div class="alert alert-error mt-16">
                <span>✕</span>
                <div>ตารางของระบบในฐานข้อมูล <strong><?= h($state['db']['database']) ?></strong> จะถูกลบทั้งหมดก่อนสร้างใหม่</div>
            </div>
        <?php endif; ?>

        <form method="post" class="form-actions">
            <input type="hidden" name="_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="install">
            <a class="btn" href="install.php?step=3">ย้อนกลับ</a>
            <div class="spacer"></div>
            <button type="submit" class="btn btn-primary">เริ่มติดตั้ง</button>
        </form>
    </div>

<?php elseif ($step === 4): ?>

    <div class="card">
        <h2><?= $installResult['ok'] ? 'ติดตั้งสำเร็จ' : 'ติดตั้งไม่สำเร็จ' ?></h2>
        <div class="card-sub">รายละเอียดการทำงานทีละขั้น</div>

        <ul class="log">
            <?php foreach ($installResult['log'] as $line): ?>
                <li>
                    <span class="log-mark <?= $line['ok'] ? 'ok' : 'fail' ?>"><?= $line['ok'] ? '✓' : '✕' ?></span>
                    <div style="flex:1;min-width:0">
                        <div><?= h($line['text']) ?></div>
                        <?php if ($line['detail']): ?>
                            <div class="log-error"><?= h($line['detail']) ?></div>
                        <?php endif; ?>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>

    <?php if ($installResult['ok']): ?>
        <div class="alert alert-warning">
            <span>△</span>
            <div>
                <strong>ควรทำต่อเพื่อความปลอดภัย</strong><br>
                ตั้งไฟล์ <code><?= h(Paths::configFile()) ?></code> เป็นอ่านอย่างเดียว
                และจำกัดสิทธิ์การเข้าถึงหน้า install.php บนเซิร์ฟเวอร์จริง
                (ตัวติดตั้งจะขอรหัสผ่านผู้ดูแลระบบก่อนเสมอเมื่อมีการเปิดซ้ำ)
            </div>
        </div>

        <div class="form-actions">
            <div class="spacer"></div>
            <a class="btn btn-primary" href="<?= h(rtrim((string) ($summary['site']['url'] ?? ''), '/') ?: '.') ?>/login">เข้าสู่ระบบ</a>
        </div>
    <?php else: ?>
        <div class="form-actions">
            <a class="btn" href="install.php?step=2">แก้ไขการตั้งค่าฐานข้อมูล</a>
            <div class="spacer"></div>
            <form method="post">
                <input type="hidden" name="_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="install">
                <button type="submit" class="btn btn-primary">ลองติดตั้งอีกครั้ง</button>
            </form>
        </div>
    <?php endif; ?>

<?php endif; ?>

<?php endif; ?>
</div>
</body>
</html>
