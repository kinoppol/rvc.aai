<?php

declare(strict_types=1);

/*
 * ให้ AI อ่าน PDF สแกนในหน่วยการเรียน (OCR) ที่ยังค้างคิว — สำหรับตั้งเวลาด้วย cron บนเซิร์ฟเวอร์
 * ปกติหน้าแก้ไขหน่วยสั่งอ่านให้เองเมื่อครูเปิดหน้า สคริปต์นี้เก็บไฟล์ที่ครูอัปโหลดแล้วไม่ได้เปิดหน้านั้นอีก
 *
 *   php bin/unit-ocr.php              อ่านไฟล์ที่รอคิว (และที่ค้าง "กำลังอ่าน" นานเกินไป)
 *   php bin/unit-ocr.php --retry      อ่านไฟล์ที่เคยล้มด้วย (เช่น หลังติดตั้ง poppler-utils หรือเปิดโมเดล OCR แล้ว)
 *   php bin/unit-ocr.php --limit=5    อ่านไม่เกิน 5 ไฟล์ต่อรอบ (ค่าเริ่มต้น 20)
 *
 * ตัวอย่าง crontab (ทุก 10 นาที):
 *   0,10,20,30,40,50 * * * * php /path/to/app/bin/unit-ocr.php >> /path/to/app/storage/logs/unit-ocr.log 2>&1
 *
 * ใช้เส้นทาง AI และโควตาของครูเจ้าของรายวิชา (ของส่วนกลางก่อน หมดโควตาแล้วสลับไปคีย์ของครู)
 * exit code 0 = ทำครบ (รวมกรณีไม่มีงาน), 1 = มีไฟล์ที่อ่านไม่สำเร็จ, 2 = ใช้คำสั่งผิด
 */

use App\AI\UnitKnowledge;
use App\Domain\CourseRepository;
use App\Domain\UnitRepository;
use App\Support\Config;
use App\Support\Paths;

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

$options = getopt('', ['retry', 'limit:', 'help']);
if ($options === false || isset($options['help'])) {
    fwrite(STDOUT, "ใช้: php bin/unit-ocr.php [--retry] [--limit=20]\n");
    exit(isset($options['help']) ? 0 : 2);
}
$retry = isset($options['retry']);
$limit = isset($options['limit']) ? max(1, (int) $options['limit']) : 20;

$stamp = static fn (): string => '[' . date('Y-m-d H:i:s') . '] ';

// กันรอบ cron ซ้อนกัน (ไฟล์หนึ่ง ๆ มีล็อกของตัวเองใน UnitKnowledge::ocr() อยู่แล้ว)
$lock = fopen(Paths::storage('cache/unit-ocr.lock'), 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDOUT, $stamp() . "รอบก่อนยังทำงานอยู่ ข้ามรอบนี้\n");
    exit(0);
}

$container = (require $root . '/app/bootstrap.php')->getContainer();
$knowledge = $container->get(UnitKnowledge::class);
$units = $container->get(UnitRepository::class);
$courses = $container->get(CourseRepository::class);

$jobs = array_slice(UnitKnowledge::queued($retry), 0, $limit);
if ($jobs === []) {
    exit(0);
}

$failed = 0;
foreach ($jobs as $job) {
    $section = $units->findSection($job['section_id']);
    $unit = $section !== null ? $units->find((int) $section['unit_id']) : null;
    $course = $unit !== null ? $courses->find((int) $unit['course_id']) : null;
    if ($section === null || $course === null) {
        // ส่วนเนื้อหาถูกลบไปแล้วแต่ไฟล์สถานะยังค้าง — ล้างทิ้ง
        $knowledge->forget(['id' => $job['section_id'], 'unit_id' => $job['unit_id']]);
        continue;
    }

    $label = sprintf('ส่วน #%d "%s" (หน่วย #%d)', $section['id'], $section['title'], $unit['id']);
    $started = microtime(true);
    $result = $knowledge->ocr($section, (int) $course['teacher_id'], $retry);
    $took = sprintf('%.0f วินาที', microtime(true) - $started);

    if ($result['state'] === 'ready') {
        fwrite(STDOUT, $stamp() . "อ่านแล้ว {$label}: " . number_format($result['chars']) . " ตัวอักษร · {$took}\n");
    } elseif ($result['state'] === 'running') {
        fwrite(STDOUT, $stamp() . "ข้าม {$label}: มีคำขออื่นกำลังอ่านอยู่\n");
    } else {
        $failed++;
        fwrite(STDERR, $stamp() . "ไม่สำเร็จ {$label}: {$result['message']}\n");
    }
}

exit($failed > 0 ? 1 : 0);
