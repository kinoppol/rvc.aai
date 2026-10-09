<?php

declare(strict_types=1);

use App\AI\AiRouter;
use App\AI\KeyCipher;
use App\AI\QuizGenerator;
use App\Auth\Auth;
use App\Domain\AiRepository;
use App\Domain\CourseRepository;
use App\Domain\EnrollmentRepository;
use App\Domain\UnitRepository;
use App\Domain\QuizRepository;
use App\Domain\ReviewRepository;
use App\Domain\SettingsRepository;
use App\Install\DemoSeeder;
use App\Migration\Migrator;
use App\Support\Config;
use App\Support\Database;
use App\Support\Db;
use App\Support\Palette;
use App\Support\Paths;
use App\Support\Url;
use App\Support\View;
use DI\ContainerBuilder;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpException;
use Slim\Factory\AppFactory;

require_once Paths::vendorAutoload();

$config = Config::load();

date_default_timezone_set((string) $config->get('app.timezone', 'Asia/Bangkok'));

$builder = new ContainerBuilder();
$builder->addDefinitions([
    Config::class => static fn (): Config => $config,

    PDO::class => static fn (Config $config): PDO => Database::connect($config->get('db')),

    Auth::class => static fn (PDO $db, Config $config): Auth => new Auth($db, (string) $config->get('db.prefix', '')),

    Migrator::class => static fn (PDO $db, Config $config): Migrator => new Migrator($db, (string) $config->get('db.prefix', '')),

    Db::class => static fn (PDO $db, Config $config): Db => new Db($db, (string) $config->get('db.prefix', '')),

    SettingsRepository::class => static fn (Db $db): SettingsRepository => new SettingsRepository($db),
    CourseRepository::class => static fn (Db $db): CourseRepository => new CourseRepository($db),
    UnitRepository::class => static fn (Db $db): UnitRepository => new UnitRepository($db),
    QuizRepository::class => static fn (Db $db): QuizRepository => new QuizRepository($db),
    EnrollmentRepository::class => static fn (Db $db): EnrollmentRepository => new EnrollmentRepository($db),
    ReviewRepository::class => static fn (Db $db): ReviewRepository => new ReviewRepository($db),
    DemoSeeder::class => static fn (Db $db, SettingsRepository $s): DemoSeeder => new DemoSeeder($db, $s),

    AiRepository::class => static fn (Db $db): AiRepository => new AiRepository($db),
    KeyCipher::class => static fn (Config $config): KeyCipher => new KeyCipher((string) $config->get('app.key', '')),
    AiRouter::class => static fn (AiRepository $ai, SettingsRepository $s, KeyCipher $c): AiRouter => new AiRouter($ai, $s, $c),
    QuizGenerator::class => static fn (): QuizGenerator => new QuizGenerator(),

    LoggerInterface::class => static function (): LoggerInterface {
        $logger = new Logger('rvc');
        $logger->pushHandler(new StreamHandler(Paths::storage('logs/app.log'), Logger::WARNING));

        return $logger;
    },

    // สีหลักของ "ระบบ" (ทุกหน้ายกเว้นหน้าแรกสาธารณะ ซึ่งใช้จานสี --lp-* ของตัวเองแยกต่างหาก)
    // เริ่มจากค่าเริ่มต้นของทั้งเว็บที่ผู้ดูแลตั้งไว้ — หน้าก่อนล็อกอิน (เข้าสู่ระบบ/สมัครสมาชิก/หน้า error)
    // ยังไม่รู้ว่าใครคือผู้ใช้ จึงเห็นสีนี้เสมอ ส่วนหลังล็อกอิน ViewContext จะสลับเป็นสีของผู้ใช้คนนั้นให้
    View::class => static fn (Config $config, SettingsRepository $settings): View => new View([
        'appName' => (string) $settings->get('site_name') ?: $config->get('app.name'),
        'college' => (string) $settings->get('college_name') ?: $config->get('app.college'),
        'version' => $config->get('app.version'),
        'base' => Url::base(),
        'user' => null,
        'ui' => Palette::tokens($settings->uiPrimary()['hex']),
    ]),
]);

$container = $builder->build();

AppFactory::setContainer($container);
$app = AppFactory::create();

$basePath = rtrim((string) (parse_url((string) $config->get('app.url'), PHP_URL_PATH) ?: ''), '/');
$app->setBasePath($basePath);
Url::setBase($basePath);

$app->addBodyParsingMiddleware();
$app->addRoutingMiddleware();

/*
 * เส้นทางในระบบเขียนไว้แบบไม่มี / ปิดท้าย เช่น /courses
 * ถ้าผู้ใช้พิมพ์ /courses/ มาเอง Slim จะหาเส้นทางไม่เจอและขึ้น "ไม่พบหน้าที่ต้องการ"
 * จึงตัด / ท้ายออกแล้วพาไปหน้าที่ถูกต้องให้ (middleware ที่เพิ่มทีหลังจะทำงานก่อน routing)
 */
$app->add(function (ServerRequestInterface $request, RequestHandlerInterface $handler) use ($app, $basePath): ResponseInterface {
    $uri = $request->getUri();
    $path = $uri->getPath();
    $target = rtrim($path, '/');

    // รากของระบบแบบไม่มี / ท้าย (เช่น /web) — Slim ลงทะเบียนเส้นทางไว้เป็น /web/ จึงต้องเติม / ให้ก่อน
    if ($basePath !== '' && $path === $basePath) {
        $to = $basePath . '/' . ($uri->getQuery() !== '' ? '?' . $uri->getQuery() : '');

        return $app->getResponseFactory()->createResponse(301)->withHeader('Location', $to);
    }

    // หน้าแรกของระบบ (/ หรือ /cvm.kmr/) ปล่อยผ่านตามปกติ
    if ($path === $target || $target === '' || $target === $basePath) {
        return $handler->handle($request);
    }

    // URL ที่ตรงกับโฟลเดอร์จริงในโปรเจกต์ (เช่น /web/app/) ห้ามตัด / ท้าย เพราะ mod_dir จะเติมกลับมาให้
    // ทุกครั้งจนวน redirect ไม่จบ — ปล่อยให้ Slim ตอบ "ไม่พบหน้าที่ต้องการ" ไปตามปกติ
    $root = dirname(__DIR__);
    $inside = realpath($root . '/' . trim(substr($target, strlen($basePath)), '/'));
    if ($inside !== false && is_dir($inside) && str_starts_with($inside, $root)) {
        return $handler->handle($request);
    }

    if ($uri->getQuery() !== '') {
        $target .= '?' . $uri->getQuery();
    }

    // 308 คงเมธอดและเนื้อหาเดิมไว้ กรณีที่ไม่ใช่การเปิดหน้าธรรมดา
    $status = in_array($request->getMethod(), ['GET', 'HEAD'], true) ? 301 : 308;

    return $app->getResponseFactory()->createResponse($status)->withHeader('Location', $target);
});

$debug = (bool) $config->get('app.debug', false);
// ไม่ให้ middleware บันทึกซ้ำ เพราะตัวจัดการด้านล่างบันทึกเองพร้อมหมายเลขอ้างอิงและรายละเอียดครบกว่า
$errorMiddleware = $app->addErrorMiddleware($debug, false, true, $container->get(LoggerInterface::class));

$errorMiddleware->setDefaultErrorHandler(
    function (ServerRequestInterface $request, Throwable $exception) use ($app, $container, $debug): ResponseInterface {
        $status = $exception instanceof HttpException ? $exception->getCode() : 500;

        [$title, $message] = match ($status) {
            403 => ['ไม่มีสิทธิ์เข้าหน้านี้', 'บัญชีของคุณไม่ได้รับสิทธิ์ให้เข้าถึงส่วนนี้ กรุณาติดต่อผู้ดูแลระบบ'],
            404 => ['ไม่พบหน้าที่ต้องการ', 'ลิงก์อาจพิมพ์ผิดหรือหน้านี้ถูกย้ายไปแล้ว'],
            405 => ['เรียกใช้งานไม่ถูกวิธี', 'กรุณากลับไปเริ่มจากเมนูของระบบ'],
            default => ['ระบบขัดข้อง', 'เกิดข้อผิดพลาดที่ไม่คาดคิด ระบบได้บันทึกไว้แล้ว กรุณาลองใหม่อีกครั้ง'],
        };

        // ข้อขัดข้องของระบบ (ไม่ใช่ 404/403 ที่ผู้ใช้พิมพ์ผิดเอง) ได้หมายเลขอ้างอิงติดตัวไว้
        // ผู้ใช้แจ้งหมายเลขนี้มา ผู้ดูแลก็ค้นรายละเอียดเต็ม ๆ ได้จาก /admin/logs
        $ref = null;
        if ($status >= 500) {
            $ref = strtoupper(bin2hex(random_bytes(3)));

            // เขียนบันทึกไม่ได้ (เช่น storage/logs ไม่มีสิทธิ์เขียน) ก็ยังต้องได้หน้าแจ้งเตือนภาษาไทยตามปกติ
            try {
                $container->get(LoggerInterface::class)->error($exception->getMessage(), [
                    'ref' => $ref,
                    'method' => $request->getMethod(),
                    'uri' => (string) $request->getUri(),
                    'user_id' => $_SESSION['user_id'] ?? null,
                    'ip' => $request->getServerParams()['REMOTE_ADDR'] ?? null,
                    'file' => $exception->getFile() . ':' . $exception->getLine(),
                    'trace' => $exception->getTraceAsString(),
                ]);
            } catch (Throwable) {
                $ref = null;
            }
        }

        return $container->get(View::class)->render(
            $app->getResponseFactory()->createResponse($status),
            'error',
            [
                'title' => $title,
                'message' => $message,
                'detail' => $debug ? $exception->getMessage() : null,
                'ref' => $ref,
            ]
        );
    }
);

(require Paths::root() . '/app/routes.php')($app);

return $app;
