<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Auth\Auth;
use App\Auth\RvcOneException;
use App\Domain\SettingsRepository;
use App\Integration\RmsClient;
use App\Integration\RmsException;
use App\Integration\RmsSync;
use App\Integration\RvcOneUserSync;
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
        private readonly RvcOneUserSync $oaSync,
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
            'oa'      => $this->oaSync->status(),
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

    /**
     * ซิงค์รายชื่อผู้ใช้จาก RVC-ONE — `mode=full` ดึงทั้งหมดใหม่ ไม่งั้นดึงเฉพาะที่เปลี่ยนตั้งแต่รอบก่อน
     * (งานตั้งเวลาใช้ bin/rvc-one-sync.php ตัวเดียวกัน)
     */
    public function rvcOne(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();
        $back = $response->withHeader('Location', Url::to('/admin/rms'))->withStatus(302);
        if (!Csrf::check($data['_token'] ?? null)) {
            Flash::add('error', 'เซสชันหมดอายุ กรุณาลองใหม่');

            return $back;
        }
        $full = ($data['mode'] ?? '') === 'full';

        @set_time_limit(600);
        // ปล่อย session ระหว่างรอ RVC-ONE ไม่ให้หน้าอื่นของผู้ดูแลค้างตาม
        session_write_close();
        try {
            $stats = $this->oaSync->run($full);
            $error = null;
        } catch (RvcOneException $e) {
            $error = $e->getMessage();
            $this->logger->warning('RVC-ONE user sync failed: ' . $e->getMessage(), ['error' => $e->error]);
        } catch (Throwable $e) {
            $error = 'บันทึกข้อมูลไม่สำเร็จ ดูรายละเอียดที่หน้าบันทึกข้อขัดข้อง';
            $this->logger->error('RVC-ONE user sync failed: ' . $e->getMessage(), ['exception' => $e]);
        }
        session_start();

        if ($error !== null) {
            Flash::add('error', 'ซิงค์ผู้ใช้จาก RVC-ONE ไม่สำเร็จ: ' . $error);

            return $back;
        }
        $this->auth->log('oa.sync.' . ($full ? 'full' : 'changes'), null, $stats);
        Flash::add('success', sprintf(
            'ซิงค์ผู้ใช้จาก RVC-ONE แล้ว (%s): ได้มา %s คน · เพิ่ม %s · อัปเดต %s (ผูกบัญชีเดิม %s) · ระงับ %s · เปิดคืน %s · ข้าม %s',
            $full ? 'ทั้งหมด' : 'เฉพาะที่เปลี่ยน',
            number_format($stats['fetched']),
            number_format($stats['created']),
            number_format($stats['updated']),
            number_format($stats['linked']),
            number_format($stats['suspended']),
            number_format($stats['reactivated']),
            number_format($stats['skipped'])
        ));

        return $back;
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
