<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Auth\Auth;
use App\Migration\Migrator;
use App\Support\Csrf;
use App\Support\Flash;
use App\Support\Url;
use App\Support\View;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;

final class MigrationController
{
    private const RESULT_KEY = '_migration_result';

    public function __construct(
        private readonly View $view,
        private readonly Migrator $migrator,
        private readonly Auth $auth,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $status = $this->migrator->status();
        $result = $_SESSION[self::RESULT_KEY] ?? null;
        unset($_SESSION[self::RESULT_KEY]);

        return $this->view->render($response, 'admin/migrations', [
            'user' => $request->getAttribute('user'),
            'page' => 'migrations',
            'status' => $status,
            'rows' => $this->rows($status),
            'result' => $result,
            'rollbackTargets' => $status['lastBatch'] > 0 ? $this->migrator->namesInBatch($status['lastBatch']) : [],
        ]);
    }

    public function run(Request $request, Response $response): Response
    {
        return $this->handle($request, $response, 'run', function (): array {
            $steps = $this->migrator->migrate();

            if ($steps === []) {
                Flash::success('ไม่มีรายการที่ต้องปรับปรุง ฐานข้อมูลเป็นรุ่นล่าสุดแล้ว');
            }

            return $steps;
        });
    }

    public function rollback(Request $request, Response $response): Response
    {
        return $this->handle($request, $response, 'rollback', function (): array {
            $steps = $this->migrator->rollback();

            if ($steps === []) {
                Flash::warning('ไม่มีรายการที่ย้อนกลับได้');
            }

            return $steps;
        });
    }

    public function reset(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();

        if (trim((string) ($data['confirm'] ?? '')) !== 'รีเซ็ต') {
            Flash::error('ยังไม่ได้ยืนยัน — ต้องพิมพ์คำว่า รีเซ็ต ให้ตรงก่อนจึงจะย้อนกลับทั้งหมดได้');

            return $this->back($response);
        }

        return $this->handle($request, $response, 'reset', function (): array {
            $steps = $this->migrator->reset();

            if ($steps === []) {
                Flash::warning('ไม่มีรายการที่ย้อนกลับได้');
            }

            return $steps;
        });
    }

    public function preview(Request $request, Response $response, array $args): Response
    {
        $name = (string) ($args['name'] ?? '');

        try {
            $payload = [
                'name' => $name,
                'up' => $this->migrator->preview($name, 'up'),
                'down' => $this->migrator->preview($name, 'down'),
            ];
            $status = 200;
        } catch (Throwable $e) {
            $payload = ['error' => $e->getMessage()];
            $status = 404;
        }

        $response->getBody()->write((string) json_encode($payload, JSON_UNESCAPED_UNICODE));

        return $response->withHeader('Content-Type', 'application/json; charset=utf-8')->withStatus($status);
    }

    /** @param callable():list<array> $action */
    private function handle(Request $request, Response $response, string $name, callable $action): Response
    {
        $data = (array) $request->getParsedBody();

        if (!Csrf::check($data['_token'] ?? null)) {
            Flash::error('เซสชันหมดอายุ กรุณาลองใหม่อีกครั้ง');

            return $this->back($response);
        }

        try {
            $steps = $action();
        } catch (Throwable $e) {
            Flash::error($e->getMessage());
            $this->auth->log('migration.' . $name . '.failed', null, ['error' => $e->getMessage()]);

            return $this->back($response);
        }

        if ($steps !== []) {
            $failed = array_filter($steps, static fn (array $s): bool => !$s['ok']);

            if ($failed === []) {
                Flash::success(sprintf('ดำเนินการสำเร็จ %d รายการ', count($steps)));
            } else {
                Flash::error('มีรายการที่ทำไม่สำเร็จ ระบบหยุดการทำงานไว้แล้ว กรุณาตรวจสอบรายละเอียดด้านล่าง');
            }

            $_SESSION[self::RESULT_KEY] = ['action' => $name, 'steps' => $steps];
            $this->auth->log('migration.' . $name, null, [
                'total' => count($steps),
                'failed' => count($failed),
            ]);
        }

        return $this->back($response);
    }

    /** รวมรายการที่รันแล้วกับที่ยังค้าง เรียงตามลำดับการรันเพื่อแสดงเป็นตารางเดียว */
    private function rows(array $status): array
    {
        $rows = [];

        foreach ($status['ran'] as $item) {
            $rows[] = $item + ['state' => 'ran'];
        }

        foreach ($status['pending'] as $item) {
            $rows[] = $item + ['state' => 'pending', 'batch' => null, 'ran_at' => null, 'duration_ms' => null];
        }

        usort($rows, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $rows;
    }

    private function back(Response $response): Response
    {
        return $response->withHeader('Location', Url::to('/admin/migrations'))->withStatus(302);
    }
}
