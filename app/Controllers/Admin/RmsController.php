<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Auth\Auth;
use App\Domain\SettingsRepository;
use App\Integration\RmsClient;
use App\Integration\RmsException;
use App\Integration\RmsSync;
use App\Support\Csrf;
use App\Support\Flash;
use App\Support\Url;
use App\Support\View;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * หน้าโอนข้อมูลจาก RMS (ผู้ดูแลระบบเท่านั้น) — บุคลากร กลุ่มเรียน และนักเรียน
 * endpoint ฝั่งโอนคืน JSON {success, data, message} ให้สคริปต์บนหน้าเรียกทีละขั้น/ทีละท่อน
 */
final class RmsController
{
    private const MAX_ROW = 1000;

    public function __construct(
        private readonly View $view,
        private readonly RmsClient $rms,
        private readonly RmsSync $sync,
        private readonly SettingsRepository $settings,
        private readonly Auth $auth,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        return $this->view->render($response, 'admin/rms', [
            'page'    => 'admin-rms',
            'rmsUrl'  => $this->rms->baseUrl(),
            'totals'  => $this->sync->totals(),
        ]);
    }

    public function saveUrl(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();
        if (!Csrf::check($data['_token'] ?? null)) {
            Flash::add('error', 'เซสชันหมดอายุ กรุณาลองใหม่');
        } elseif (($url = RmsClient::normalizeUrl((string) ($data['rms_base_url'] ?? ''))) === null) {
            Flash::add('error', 'URL ของระบบ RMS ต้องขึ้นต้นด้วย http:// หรือ https:// และไม่มี ? หรือ #');
        } else {
            $this->settings->set(RmsClient::SETTING, $url, 'string', 'rms');
            $this->auth->log('rms.url.save', null, ['url' => $url]);
            Flash::add('success', 'บันทึก URL ของระบบ RMS แล้ว');
        }

        return $response->withHeader('Location', Url::to('/admin/rms'))->withStatus(302);
    }

    public function people(Request $request, Response $response): Response
    {
        return $this->run($request, $response, 'people', fn () => $this->sync->people());
    }

    public function groups(Request $request, Response $response): Response
    {
        return $this->run($request, $response, 'groups', fn () => $this->sync->groups());
    }

    public function studentCount(Request $request, Response $response): Response
    {
        return $this->run($request, $response, null, fn () => ['total' => $this->sync->studentCount()]);
    }

    public function studentBatch(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();
        // ไม่เชื่อค่าจาก client — บีบให้อยู่ในช่วงที่ปลอดภัยเสมอ
        $offset = max(0, (int) ($data['offset'] ?? 0));
        $row = (int) ($data['row'] ?? 100);
        $row = $row < 1 ? 100 : min($row, self::MAX_ROW);

        return $this->run(
            $request,
            $response,
            $offset === 0 ? 'students' : null,
            fn () => $this->sync->studentBatch($offset, $row)
        );
    }

    /** ตรวจ CSRF เรียกงาน แล้วห่อผลเป็น JSON — บันทึก audit เฉพาะครั้งที่ระบุชื่อ (ท่อนแรกของนักเรียน) */
    private function run(Request $request, Response $response, ?string $audit, callable $work): Response
    {
        $data = (array) $request->getParsedBody();
        if (!Csrf::check($data['_token'] ?? null)) {
            return $this->json($response, false, null, 'เซสชันหมดอายุ กรุณาโหลดหน้าใหม่', 419);
        }

        @set_time_limit(300);
        try {
            $result = $work();
        } catch (RmsException $e) {
            return $this->json($response, false, null, $e->getMessage(), 502);
        } catch (Throwable $e) {
            $this->logger->error('RMS sync failed: ' . $e->getMessage(), ['exception' => $e]);

            return $this->json($response, false, null, 'บันทึกข้อมูลไม่สำเร็จ ดูรายละเอียดที่หน้าบันทึกข้อขัดข้อง', 500);
        }

        if ($audit !== null) {
            $this->auth->log('rms.sync.' . $audit, null, $result);
        }

        return $this->json($response, true, $result, '');
    }

    private function json(Response $response, bool $success, ?array $data, string $message, int $status = 200): Response
    {
        $response->getBody()->write((string) json_encode(
            ['success' => $success, 'data' => $data, 'message' => $message],
            JSON_UNESCAPED_UNICODE
        ));

        return $response->withHeader('Content-Type', 'application/json; charset=utf-8')->withStatus($status);
    }
}
