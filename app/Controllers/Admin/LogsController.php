<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Auth\Auth;
use App\Support\LogReader;
use App\Support\View;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Stream;

/**
 * บันทึกข้อขัดข้องของระบบสำหรับผู้ดูแล — ค้นจากหมายเลขอ้างอิงที่ผู้ใช้แจ้งมา หรือโหลดไฟล์ทั้งก้อนไปตรวจ
 */
final class LogsController
{
    private const LIMIT = 100;

    public function __construct(
        private readonly View $view,
        private readonly LogReader $logs,
        private readonly Auth $auth,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $query = trim((string) ($request->getQueryParams()['q'] ?? ''));

        return $this->view->render($response, 'admin/logs', [
            'page' => 'admin-logs',
            'entries' => $this->logs->recent(self::LIMIT, $query),
            'query' => $query,
            'exists' => $this->logs->exists(),
            'path' => $this->logs->path(),
            'sizeText' => $this->sizeText($this->logs->size()),
            'limit' => self::LIMIT,
        ]);
    }

    public function download(Request $request, Response $response): Response
    {
        if (!$this->logs->exists()) {
            return $response->withStatus(404);
        }

        $this->auth->log('logs.download');
        $handle = fopen($this->logs->path(), 'rb');

        return $response
            ->withBody(new Stream($handle))
            ->withHeader('Content-Type', 'text/plain; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="app-' . date('Ymd-His') . '.log"');
    }

    private function sizeText(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' ไบต์';
        }

        return $bytes < 1_048_576
            ? number_format($bytes / 1024, 1) . ' KB'
            : number_format($bytes / 1_048_576, 1) . ' MB';
    }
}
