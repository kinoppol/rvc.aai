<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Auth\Auth;
use App\Install\DemoSeeder;
use App\Support\Csrf;
use App\Support\Flash;
use App\Support\Url;
use App\Support\View;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;

final class DemoController
{
    private const RESULT_KEY = '_demo_seed_result';

    public function __construct(
        private readonly View $view,
        private readonly DemoSeeder $seeder,
        private readonly Auth $auth,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $result = $_SESSION[self::RESULT_KEY] ?? null;
        unset($_SESSION[self::RESULT_KEY]);

        return $this->view->render($response, 'admin/demo', [
            'user' => $request->getAttribute('user'),
            'page' => 'admin-demo',
            'alreadySeeded' => $this->seeder->alreadySeeded(),
            'demoPassword' => DemoSeeder::DEMO_PASSWORD,
            'result' => $result,
        ]);
    }

    public function seed(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();

        if (!Csrf::check($data['_token'] ?? null)) {
            Flash::error('เซสชันหมดอายุ กรุณาลองใหม่อีกครั้ง');

            return $this->back($response);
        }

        if (trim((string) ($data['confirm'] ?? '')) !== 'ใส่ข้อมูลตัวอย่าง') {
            Flash::error('ต้องพิมพ์คำว่า ใส่ข้อมูลตัวอย่าง ให้ตรงก่อนจึงจะดำเนินการได้');

            return $this->back($response);
        }

        try {
            $log = $this->seeder->run();
            $_SESSION[self::RESULT_KEY] = $log;
            $this->auth->log('demo.seed', null, ['lines' => count($log)]);
            Flash::success('ใส่ข้อมูลตัวอย่างเรียบร้อยแล้ว');
        } catch (Throwable $e) {
            Flash::error('ใส่ข้อมูลตัวอย่างไม่สำเร็จ: ' . $e->getMessage());
            $this->auth->log('demo.seed.failed', null, ['error' => $e->getMessage()]);
        }

        return $this->back($response);
    }

    private function back(Response $response): Response
    {
        return $response->withHeader('Location', Url::to('/admin/demo'))->withStatus(302);
    }
}
