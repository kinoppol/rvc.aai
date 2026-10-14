<?php

declare(strict_types=1);

namespace App\Controllers;

use App\AI\AiUnavailableException;
use App\AI\Drafter;
use App\Auth\Auth;
use App\Domain\CourseRepository;
use App\Support\Csrf;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * ให้ผู้ช่วย AI สรุป "ผลลัพธ์การเรียนรู้ระดับรายวิชา" จากข้อมูลที่ครูกรอกอยู่บนฟอร์มรายวิชา
 * (ส่งค่าจากฟอร์มมาตรง ๆ จึงใช้ได้ทั้งตอนสร้างวิชาใหม่และตอนแก้ไข โดยไม่ต้องบันทึกก่อน)
 * ตอบเป็น JSON ให้สคริปต์บนหน้าเติมลงช่อง — ยังไม่บันทึกอะไร ครูแก้แล้วกดบันทึกเอง
 */
final class CourseOutcomesController
{
    public function __construct(
        private readonly Drafter $drafter,
        private readonly CourseRepository $courses,
        private readonly Auth $auth,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function draft(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $data = (array) $request->getParsedBody();

        if (!Csrf::check($data['_token'] ?? null)) {
            return $this->json($response, ['ok' => false, 'error' => 'เซสชันหมดอายุ กรุณารีเฟรชหน้าแล้วลองใหม่'], 419);
        }

        // แก้ไขวิชาเดิม: เคารพสวิตช์ปิด AI รายวิชาของผู้ดูแลระบบ
        $courseId = (int) ($data['course_id'] ?? 0);
        if ($courseId > 0) {
            $course = $this->courses->find($courseId);
            if ($course === null || (int) $course['teacher_id'] !== (int) $user['id']) {
                return $this->json($response, ['ok' => false, 'error' => 'ไม่พบรายวิชานี้'], 404);
            }
            if ((int) ($course['ai_lesson_plan_enabled'] ?? 1) !== 1) {
                return $this->json($response, ['ok' => false, 'error' => 'ผู้ดูแลระบบปิดฟังก์ชัน AI ของรายวิชานี้ไว้'], 403);
            }
        }

        $field = static fn (string $k, int $max = 8000): string => mb_substr(trim((string) ($data[$k] ?? '')), 0, $max);
        $spec = [
            'task' => 'course_outcomes',
            'course_code' => $field('code', 32),
            'course_name' => $field('name', 191),
            'description' => $field('description'),
            'objectives' => $field('objectives'),
            'competencies' => $field('competencies'),
            'count' => max(2, min(8, (int) ($data['count'] ?? 4))),
        ];
        if ($spec['course_name'] === '' && $spec['description'] === '') {
            return $this->json($response, ['ok' => false, 'error' => 'กรอกชื่อวิชาหรือคำอธิบายรายวิชาก่อน ผู้ช่วยจึงสรุปได้'], 422);
        }

        // ต้องมีข้อความกำกับระบบเสมอ — ผู้ให้บริการบางรายปฏิเสธคำขอที่ system prompt ว่างเปล่า
        $system = 'คุณเป็นผู้เชี่ยวชาญหลักสูตรอาชีวศึกษา สอศ. เขียนผลลัพธ์การเรียนรู้ระดับรายวิชาเป็นภาษาไทย '
            . 'ตอบกลับเป็น NDJSON หนึ่งข้อต่อหนึ่งบรรทัด';

        try {
            $objects = $this->drafter->objects((int) $user['id'], $system, $spec, $spec['count'] + 2);
        } catch (AiUnavailableException $e) {
            return $this->json($response, ['ok' => false, 'error' => $e->getMessage()], 503);
        } catch (Throwable $e) {
            $this->logger->error('ร่างผลลัพธ์การเรียนรู้ระดับรายวิชาไม่สำเร็จ: ' . $e->getMessage(), [
                'course_id' => $courseId ?: null,
                'file' => $e->getFile() . ':' . $e->getLine(),
            ]);

            return $this->json($response, ['ok' => false, 'error' => 'ผู้ช่วยสรุปไม่สำเร็จ กรุณาลองใหม่อีกครั้ง'], 500);
        }

        // โมเดลมักตั้งชื่อคีย์ต่างกันไป — รับข้อความจากคีย์ที่พบบ่อย ตัดเลขข้อนำหน้า และไม่เอาซ้ำ
        $lines = [];
        foreach ($objects as $obj) {
            $text = Drafter::text($obj['outcome'] ?? $obj['clo'] ?? $obj['text'] ?? $obj['outcomes'] ?? '');
            foreach (preg_split('/\R+/u', $text) ?: [] as $line) {
                $line = trim((string) preg_replace('/^\s*(?:[-•*]|\d+[.)]|[๑-๙]+[.)]|CLO\s*\d+[.:)]?)\s*/iu', '', $line));
                if ($line !== '' && !in_array($line, $lines, true)) {
                    $lines[] = mb_substr($line, 0, 500);
                }
            }
        }

        if ($lines === []) {
            return $this->json($response, ['ok' => false, 'error' => 'ผู้ช่วยไม่ได้ส่งผลลัพธ์การเรียนรู้กลับมา กรุณาลองใหม่'], 502);
        }

        $this->auth->log('ai.course_outcomes', $courseId > 0 ? 'course#' . $courseId : 'course#new', ['items' => count($lines)]);

        return $this->json($response, ['ok' => true, 'text' => implode("\n", array_slice($lines, 0, 8))]);
    }

    /** @param array<string,mixed> $body */
    private function json(Response $response, array $body, int $status = 200): Response
    {
        $response->getBody()->write((string) json_encode($body, JSON_UNESCAPED_UNICODE));

        return $response->withHeader('Content-Type', 'application/json; charset=utf-8')->withStatus($status);
    }
}
