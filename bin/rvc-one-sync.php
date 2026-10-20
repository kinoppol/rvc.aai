<?php

declare(strict_types=1);

/*
 * ซิงค์รายชื่อผู้ใช้จาก RVC-ONE (User-sync API) — สำหรับตั้งเวลาด้วย cron บนเซิร์ฟเวอร์
 *
 *   php bin/rvc-one-sync.php                 ดึงเฉพาะที่เปลี่ยนตั้งแต่รอบก่อน (รอบแรก = ทั้งหมด)
 *   php bin/rvc-one-sync.php --full          ดึงทั้งหมดใหม่
 *   php bin/rvc-one-sync.php --type=staff    เฉพาะครูและบุคลากร (หรือ student) — เก็บ since แยกจากรอบปกติ
 *
 * ตัวอย่าง crontab (ทุก 30 นาที + ดึงทั้งหมดตีสามทุกวันอาทิตย์):
 *   0,30 * * * * php /path/to/app/bin/rvc-one-sync.php >> /path/to/app/storage/logs/rvc-one-sync.log 2>&1
 *   0 3 * * 0    php /path/to/app/bin/rvc-one-sync.php --full >> /path/to/app/storage/logs/rvc-one-sync.log 2>&1
 *
 * โฟลเดอร์ bin/ อยู่นอก public/ จึงเรียกผ่านเว็บไม่ได้ · exit code 0 = สำเร็จ, 1 = ไม่สำเร็จ, 2 = ใช้คำสั่งผิด
 */

use App\Auth\Auth;
use App\Auth\RvcOneException;
use App\Integration\RvcOneUserSync;
use App\Support\Config;
use Psr\Log\LoggerInterface;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

if (!Config::isInstalled()) {
    fwrite(STDERR, "ยังไม่ได้ติดตั้งระบบ\n");
    exit(1);
}

$options = getopt('', ['full', 'type:', 'help']);
if ($options === false || isset($options['help'])) {
    fwrite(STDOUT, "ใช้: php bin/rvc-one-sync.php [--full] [--type=staff|student]\n");
    exit(isset($options['help']) ? 0 : 2);
}
$full = isset($options['full']);
$type = isset($options['type']) ? (string) $options['type'] : null;
if ($type !== null && !in_array($type, RvcOneUserSync::TYPES, true)) {
    fwrite(STDERR, "--type ต้องเป็น staff หรือ student\n");
    exit(2);
}

$container = (require $root . '/app/bootstrap.php')->getContainer();
$stamp = static fn (): string => '[' . date('Y-m-d H:i:s') . '] ';

fwrite(STDOUT, $stamp() . 'เริ่มซิงค์ผู้ใช้จาก RVC-ONE (' . ($full ? 'ทั้งหมด' : 'เฉพาะที่เปลี่ยน') . ($type !== null ? ', ' . $type : '') . ")\n");
try {
    $stats = $container->get(RvcOneUserSync::class)->run($full, $type);
} catch (Throwable $e) {
    $message = $e instanceof RvcOneException ? $e->getMessage() : $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine();
    fwrite(STDERR, $stamp() . 'ไม่สำเร็จ: ' . $message . "\n");
    $container->get(LoggerInterface::class)->error('RVC-ONE user sync (cli) failed: ' . $e->getMessage(), [
        'error' => $e instanceof RvcOneException ? $e->error : get_class($e),
    ]);
    exit(1);
}

$container->get(Auth::class)->log('oa.sync.' . ($full ? 'full' : 'changes'), null, $stats + ['via' => 'cli']);
fwrite(STDOUT, $stamp() . sprintf(
    "สำเร็จ: %d หน้า ได้มา %d คน · เพิ่ม %d · อัปเดต %d (ผูกบัญชีเดิม %d) · ระงับ %d · เปิดคืน %d · ข้าม %d · since ถัดไป %s\n",
    $stats['pages'],
    $stats['fetched'],
    $stats['created'],
    $stats['updated'],
    $stats['linked'],
    $stats['suspended'],
    $stats['reactivated'],
    $stats['skipped'],
    $stats['server_time'] !== '' ? $stats['server_time'] : '-'
));
exit(0);
