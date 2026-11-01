<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Auth\Auth;
use App\Security\Quarantine;
use App\Security\SecurityScanner;
use App\Support\Csrf;
use App\Support\Flash;
use App\Support\Paths;
use App\Support\Url;
use App\Support\View;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use RuntimeException;

/**
 * ตรวจช่องโหว่ของระบบ (แท็บ "ความปลอดภัย" ในตั้งค่าระบบ) — กดตรวจเมื่อไรก็ได้
 * ผลล่าสุดเก็บเป็นไฟล์ storage/cache/security-scan.json (ยาวเกินคอลัมน์ settings.value ได้)
 * ไฟล์ที่น่าสงสัยและไม่ได้มากับ git กดกักไว้ได้ (ย้ายไป storage/quarantine ไม่ลบ)
 */
final class SecurityController
{
    public function __construct(
        private readonly View $view,
        private readonly SecurityScanner $scanner,
        private readonly Auth $auth,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $last = $this->load();

        return $this->view->render($response, 'admin/security', [
            'page' => 'admin-security',
            'scan' => is_array($last) ? $last : null,
            'severities' => SecurityScanner::SEVERITIES,
            'groups' => ['files' => 'ไฟล์', 'config' => 'ค่าตั้งของระบบ', 'accounts' => 'บัญชีผู้ใช้', 'web' => 'การเข้าถึงจากเว็บ'],
        ]);
    }

    public function scan(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();
        if (!Csrf::check($data['_token'] ?? null)) {
            Flash::error('เซสชันหมดอายุ กรุณาลองใหม่');

            return $this->redirect($response);
        }

        // เดินทุกไฟล์และตรวจรหัสผ่านทีละบัญชี ใช้เวลาได้ถึงหลายสิบวินาที — ไม่ต้องถือ session ไว้ระหว่างนั้น
        session_write_close();
        @set_time_limit(300);
        $result = $this->scanner->run();
        session_start();

        $this->store($result);
        $this->auth->log('security.scan', null, ['counts' => $result['counts'], 'seconds' => $result['seconds']]);
        $bad = $result['counts']['high'] + $result['counts']['medium'];
        $bad > 0
            ? Flash::error('ตรวจเสร็จแล้ว พบ ' . $bad . ' รายการที่ต้องดูแล')
            : Flash::success('ตรวจเสร็จแล้ว ไม่พบรายการอันตรายหรือน่าสงสัย');

        return $this->redirect($response);
    }

    public function quarantine(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();
        if (!Csrf::check($data['_token'] ?? null)) {
            Flash::error('เซสชันหมดอายุ กรุณาลองใหม่');

            return $this->redirect($response);
        }

        $path = (string) ($data['path'] ?? '');
        $scan = $this->load();
        $findings = is_array($scan) ? (array) ($scan['findings'] ?? []) : [];
        $allowed = false;
        foreach ($findings as $f) {
            if (($f['path'] ?? null) === $path && !empty($f['quarantine'])) {
                $allowed = true;
            }
        }
        if (!$allowed) {
            Flash::error('กักได้เฉพาะไฟล์ที่ผลการตรวจล่าสุดแนะนำให้กัก กรุณาตรวจใหม่อีกครั้ง');

            return $this->redirect($response);
        }

        try {
            $moved = Quarantine::move($path);
        } catch (RuntimeException $e) {
            Flash::error($e->getMessage());

            return $this->redirect($response);
        }

        // เอารายการนี้ออกจากผลล่าสุด และนับใหม่
        $scan['findings'] = array_values(array_filter($findings, static fn (array $f): bool => ($f['path'] ?? null) !== $path));
        $scan['counts'] = array_fill_keys(array_keys(SecurityScanner::SEVERITIES), 0);
        foreach ($scan['findings'] as $f) {
            $scan['counts'][$f['severity']]++;
        }
        $scan['quarantined'][] = ['path' => $path, 'to' => $moved, 'at' => date('Y-m-d H:i:s')];
        $this->store($scan);
        $this->auth->log('security.quarantine', $path, ['to' => $moved]);
        Flash::success('กักไฟล์ ' . $path . ' แล้ว ย้ายไปไว้ที่ ' . $moved);

        return $this->redirect($response);
    }

    private function file(): string
    {
        return Paths::storage('cache/security-scan.json');
    }

    /** @return array<string,mixed>|null */
    private function load(): ?array
    {
        $raw = is_file($this->file()) ? (string) file_get_contents($this->file()) : '';
        $data = $raw !== '' ? json_decode($raw, true) : null;

        return is_array($data) ? $data : null;
    }

    /** @param array<string,mixed> $data */
    private function store(array $data): void
    {
        if (@file_put_contents($this->file(), (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX) === false) {
            Flash::error('บันทึกผลการตรวจไม่ได้ ตรวจสิทธิ์เขียนของ storage/cache');
        }
    }

    private function redirect(Response $response): Response
    {
        return $response->withHeader('Location', Url::to('/admin/security'))->withStatus(302);
    }
}
