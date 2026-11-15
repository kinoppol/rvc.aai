<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\SubjectCatalogRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * ค้นคลังรายวิชา สอศ. จากฟอร์มรายวิชาของครู (ตอบ JSON)
 *   GET /courses/subjects/search?q=  — ตัวเลือกเติมอัตโนมัติ (รหัสขึ้นต้น หรือชื่อวิชาบางส่วน)
 *   GET /courses/subjects/lookup?code= — รายละเอียดเต็มของรหัสนั้น (ทุกปีหลักสูตร ล่าสุดก่อน)
 * ก่อนรัน migration 000138 ตอบ ready:false ให้หน้าเว็บเงียบไป
 */
final class SubjectLookupController
{
    public function __construct(private readonly SubjectCatalogRepository $catalog)
    {
    }

    public function search(Request $request, Response $response): Response
    {
        $q = mb_substr(trim((string) ($request->getQueryParams()['q'] ?? '')), 0, 60);
        if (!$this->catalog->ready()) {
            return $this->json($response, ['ready' => false, 'items' => []]);
        }
        $items = array_map(static fn (array $r): array => [
            'code' => $r['code'],
            'name' => $r['name_th'],
            'year' => (int) $r['curriculum_year'],
            'tpn' => $r['theory_hours'] . '-' . $r['practice_hours'] . '-' . ($r['credits'] + 0),
        ], $this->catalog->search($q));

        return $this->json($response, ['ready' => true, 'items' => $items]);
    }

    public function lookup(Request $request, Response $response): Response
    {
        $code = (string) ($request->getQueryParams()['code'] ?? '');
        if (!$this->catalog->ready()) {
            return $this->json($response, ['ready' => false, 'subjects' => []]);
        }
        $subjects = array_map(static fn (array $r): array => [
            'code' => $r['code'],
            'year' => (int) $r['curriculum_year'],
            'name' => $r['name_th'],
            'name_en' => $r['name_en'],
            'theory' => (int) $r['theory_hours'],
            'practice' => (int) $r['practice_hours'],
            'credits' => $r['credits'] + 0,
            'description' => (string) $r['description'],
            'objectives' => (string) $r['objectives'],
            'competencies' => (string) $r['competencies'],
        ], $this->catalog->byCode($code));

        return $this->json($response, ['ready' => true, 'subjects' => $subjects]);
    }

    /** @param array<string,mixed> $body */
    private function json(Response $response, array $body): Response
    {
        $response->getBody()->write((string) json_encode($body, JSON_UNESCAPED_UNICODE));

        return $response->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Cache-Control', 'private, max-age=300');
    }
}
