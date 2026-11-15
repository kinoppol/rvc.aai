<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\SubjectCatalogRepository;
use App\Support\View;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpNotFoundException;

/**
 * คลังรายวิชา สอศ. สำหรับครูและบุคลากรทุกคน
 *   GET /subjects            — สืบค้นรายวิชา (รหัส/ชื่อไทย/ชื่ออังกฤษ) ดูรายละเอียดทีละวิชา
 *   GET /subjects/{id}/print — ใบข้อมูลรายวิชา A4 แบบเดียวกับที่พิมพ์จาก ศธ.02
 */
final class SubjectBrowseController
{
    private const PER_PAGE = 50;

    public function __construct(
        private readonly View $view,
        private readonly SubjectCatalogRepository $catalog,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        return $this->view->render($response, 'subjects/index', [
            'page' => 'subjects',
            'stats' => $this->catalog->stats(),
        ] + $this->catalog->listing($request->getQueryParams(), self::PER_PAGE));
    }

    public function print(Request $request, Response $response, array $args): Response
    {
        $subject = $this->catalog->find((int) $args['id']);
        if ($subject === null) {
            throw new HttpNotFoundException($request, 'ไม่พบรายวิชานี้ในคลังรายวิชา สอศ.');
        }

        return $this->view->render($response, 'subjects/print', [
            'subject' => $subject,
            'objectives' => self::lines((string) $subject['objectives']),
            'competencies' => self::lines((string) $subject['competencies']),
        ]);
    }

    /**
     * จุดประสงค์/สมรรถนะเป็นข้อ ๆ — ตัดคำนำ "เพื่อให้" บรรทัดแรกออก (ใบพิมพ์มีอยู่ในหัวข้อแล้ว)
     * และต่อบรรทัดที่ไม่ได้ขึ้นต้นด้วยเลขข้อเข้ากับข้อก่อนหน้า
     * @return list<string>
     */
    private static function lines(string $text): array
    {
        $items = [];
        foreach (preg_split('/\R/u', trim($text)) ?: [] as $line) {
            $line = trim(preg_replace('/\s+/u', ' ', $line) ?? '');
            if ($line === '' || ($items === [] && preg_match('/^เพื่อให้$/u', $line) === 1)) {
                continue;
            }
            if ($items !== [] && preg_match('/^\d+[.)]/u', $line) !== 1) {
                $items[count($items) - 1] .= ' ' . $line;
                continue;
            }
            $items[] = $line;
        }

        return $items;
    }
}
