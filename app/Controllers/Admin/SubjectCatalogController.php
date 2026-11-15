<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Auth\Auth;
use App\Domain\SubjectCatalogRepository;
use App\Support\Csrf;
use App\Support\Flash;
use App\Support\Url;
use App\Support\View;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * หน้าตรวจสอบคลังรายวิชามาตรฐาน สอศ. (ผู้ดูแลระบบ, แท็บ "รายวิชา สอศ." ในตั้งค่าระบบ)
 * ค้นหา กรองปีหลักสูตร/ระดับ เปิดดูรายละเอียดทีละวิชา และนำเข้าใหม่จากไฟล์ในโปรเจกต์
 */
final class SubjectCatalogController
{
    private const PER_PAGE = 50;

    public function __construct(
        private readonly View $view,
        private readonly SubjectCatalogRepository $catalog,
        private readonly Auth $auth,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $query = $request->getQueryParams();
        $q = mb_substr(trim((string) ($query['q'] ?? '')), 0, 100);
        $year = (int) ($query['year'] ?? 0);
        $level = (string) ($query['level'] ?? '');
        $page = max(1, (int) ($query['page'] ?? 1));
        $ready = $this->catalog->ready();
        if ($ready) {
            $this->catalog->ensureImported();
        }
        $result = $ready ? $this->catalog->browse($q, $year, $level, $page, self::PER_PAGE) : ['rows' => [], 'total' => 0];
        $show = isset($query['show']) ? $this->catalog->find((int) $query['show']) : null;

        return $this->view->render($response, 'admin/subjects', [
            'page' => 'admin-subjects',
            'ready' => $ready,
            'stats' => $this->catalog->stats(),
            'gaps' => $ready ? $this->catalog->gaps() : [],
            'files' => array_map('basename', SubjectCatalogRepository::bundledFiles()),
            'rows' => $result['rows'],
            'total' => $result['total'],
            'pages' => max(1, (int) ceil($result['total'] / self::PER_PAGE)),
            'pageNo' => $page,
            'filters' => ['q' => $q, 'year' => $year, 'level' => $level],
            // ส่วน query ของตัวกรองปัจจุบัน — ใช้ต่อท้ายลิงก์หน้า/รายละเอียด
            'qs' => http_build_query(array_filter(['q' => $q, 'year' => $year ?: null, 'level' => $level, 'page' => $page > 1 ? $page : null])),
            'qsNoPage' => http_build_query(array_filter(['q' => $q, 'year' => $year ?: null, 'level' => $level])),
            'show' => $show,
        ]);
    }

    /** นำเข้า/ปรับปรุงจากไฟล์ database/data/vec-subjects-*.json.gz อีกครั้ง */
    public function import(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();
        if (!Csrf::check($data['_token'] ?? null)) {
            Flash::add('error', 'เซสชันหมดอายุ กรุณาลองใหม่');
        } elseif (!$this->catalog->ready()) {
            Flash::add('error', 'ยังไม่มีตารางคลังรายวิชา — รัน migration ที่หน้าฐานข้อมูลก่อน');
        } else {
            try {
                set_time_limit(300);
                $result = $this->catalog->importBundled();
                $this->auth->log('subject_catalog.import', 'subject_catalog', $result);
                Flash::success(sprintf('นำเข้า/ปรับปรุงรายวิชา %s รายการจาก %d ไฟล์แล้ว', number_format($result['rows']), $result['files']));
            } catch (Throwable $e) {
                $this->logger->error('นำเข้าคลังรายวิชาไม่สำเร็จ: ' . $e->getMessage());
                Flash::add('error', 'นำเข้าไม่สำเร็จ: ' . $e->getMessage());
            }
        }

        return $response->withHeader('Location', Url::to('/admin/subjects'))->withStatus(302);
    }
}
