<?php

declare(strict_types=1);

namespace App\Controllers;

use App\AI\AiUnavailableException;
use App\AI\Drafter;
use App\AI\ModelRoles;
use App\Support\Csrf;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

/**
 * ปุ่ม "✦ ให้ AI จัดบทบาทให้" ในตัวเลือกโมเดล (ครูและผู้ดูแล)
 *
 * ส่งรายชื่อโมเดลให้ AI ของส่วนกลางพิจารณาก่อน ถ้าโควตาส่วนกลางหมดหรือเครื่องปิด
 * AiRouter สลับไปใช้ AI ตัวอื่นที่ใช้ได้ (คีย์ของครู หรือโมเดลไม่จำกัดของส่วนกลาง)
 * ตอบเป็น JSON — ยังไม่บันทึกอะไร ผู้ใช้ปรับต่อแล้วกดบันทึกในฟอร์มเอง
 */
final class ModelRolesController
{
    private const MAX_MODELS = 40;

    public function __construct(
        private readonly Drafter $drafter,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function suggest(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $data = (array) $request->getParsedBody();
        if (!Csrf::check($data['_token'] ?? null)) {
            return $this->json($response, ['ok' => false, 'message' => 'เซสชันหมดอายุ'], 419);
        }

        $models = array_slice(array_values(array_unique(array_filter(
            array_map(static fn ($m): string => mb_substr(trim((string) $m), 0, 191), (array) ($data['models'] ?? [])),
            static fn (string $m): bool => $m !== ''
        ))), 0, self::MAX_MODELS);
        if ($models === []) {
            return $this->json($response, ['ok' => false, 'message' => 'ยังไม่ได้เลือกโมเดล']);
        }

        try {
            $items = $this->drafter->objects(
                (int) $user['id'],
                'คุณคือผู้เชี่ยวชาญโมเดลภาษา AI ที่ช่วยวิทยาลัยเทคนิคเลือกโมเดลให้เหมาะกับงานสอน ตอบเป็น JSON ตามรูปแบบที่กำหนดเท่านั้น',
                ['task' => 'model_roles', 'models' => $models, 'provider' => mb_substr(trim((string) ($data['provider'] ?? '')), 0, 80)],
                count($models) + 5,
                prefer: 'college'
            );
        } catch (AiUnavailableException $e) {
            $this->logger->warning('model roles suggest: ' . $e->getMessage());

            return $this->json($response, ['ok' => false, 'message' => 'ให้ AI จัดบทบาทไม่ได้ · ' . $e->getMessage()]);
        } catch (\Throwable $e) {
            $this->logger->error('model roles suggest failed: ' . $e->getMessage());

            return $this->json($response, ['ok' => false, 'message' => 'ให้ AI จัดบทบาทไม่สำเร็จ ลองใหม่อีกครั้ง หรือกำหนดเองได้เลย']);
        }

        // จับคู่ชื่อแบบไม่สนตัวพิมพ์ — โมเดลบางตัวพิมพ์ชื่อกลับมาไม่ตรงตัวพิมพ์เดิม
        $lookup = [];
        foreach ($models as $m) {
            $lookup[mb_strtolower($m)] = $m;
        }
        $raw = [];
        foreach ($items as $item) {
            $name = $lookup[mb_strtolower(trim((string) ($item['model'] ?? '')))] ?? null;
            if ($name !== null) {
                $raw[$name] = ['roles' => (array) ($item['roles'] ?? []), 'note' => $item['note'] ?? ''];
            }
        }

        if ($raw === []) {
            return $this->json($response, ['ok' => false, 'message' => 'AI ตอบกลับมาไม่ตรงรูปแบบ ลองใหม่อีกครั้ง หรือกำหนดเองได้เลย']);
        }

        $roles = ModelRoles::clean($raw);

        return $this->json($response, [
            'ok' => true,
            'roles' => (object) $roles,
            'message' => sprintf('AI จัดบทบาทให้ %d จาก %d โมเดลแล้ว · ตรวจดูแล้วกดบันทึก', count($roles), count($models)),
        ]);
    }

    private function json(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write((string) json_encode($data, JSON_UNESCAPED_UNICODE));

        return $response->withHeader('Content-Type', 'application/json; charset=utf-8')->withStatus($status);
    }
}
