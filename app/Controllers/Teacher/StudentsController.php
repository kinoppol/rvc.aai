<?php

declare(strict_types=1);

namespace App\Controllers\Teacher;

use App\Domain\StudentAccountRepository;
use App\Support\Csrf;
use App\Support\Db;
use App\Support\Flash;
use App\Support\Url;
use App\Support\View;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Exception\HttpNotFoundException;
use Throwable;

/**
 * ครูจัดการข้อมูลนักเรียนของสถานศึกษาตัวเอง — ครูจากสถานศึกษาเดียวกัน (institution_id ตรงกัน)
 * เห็นและแก้ไขรายชื่อร่วมกันได้ทั้งหมด เพิ่มทีละคนหรือนำเข้าจากไฟล์ Excel
 *
 * ชื่อผู้ใช้คือรหัสนักศึกษา (unique เฉพาะภายในสถานศึกษา) รหัสผ่านคือเลขประจำตัวประชาชน 13 หลัก
 */
final class StudentsController
{
    private const USERNAME_PATTERN = '/^[A-Za-z0-9._-]{2,64}$/';
    private const NATIONAL_ID_PATTERN = '/^\d{13}$/';

    private const PER_PAGE = 50;
    private const STATUSES = ['active', 'suspended'];

    private const TEMPLATE_HEADERS = ['รหัสนักศึกษา', 'ชื่อ-สกุล', 'เลขประจำตัวประชาชน', 'อีเมล', 'เบอร์โทรศัพท์'];

    public function __construct(
        private readonly View $view,
        private readonly Db $db,
        private readonly StudentAccountRepository $students,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $institutionId = $this->requireInstitution($request, $response);
        if ($institutionId === null) {
            return $response;
        }

        $query = $request->getQueryParams();
        $search = trim((string) ($query['q'] ?? ''));
        $status = in_array($query['status'] ?? '', self::STATUSES, true) ? (string) $query['status'] : '';

        $total = $this->students->countForInstitution($institutionId, $search, $status);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $pageNo = min($pages, max(1, (int) ($query['page'] ?? 1)));

        return $this->view->render($response, 'students/index', [
            'page' => 'students',
            'students' => $this->students->forInstitution(
                $institutionId,
                $search,
                $status,
                self::PER_PAGE,
                ($pageNo - 1) * self::PER_PAGE
            ),
            'search' => $search,
            'status' => $status,
            'total' => $total,
            'pageNo' => $pageNo,
            'pages' => $pages,
            'perPage' => self::PER_PAGE,
            'queryString' => http_build_query(array_filter([
                'q' => $search,
                'status' => $status,
            ], static fn (string $v): bool => $v !== '')),
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        if ($this->requireInstitution($request, $response) === null) {
            return $response;
        }

        return $this->view->render($response, 'students/form', [
            'page' => 'students',
            'student' => null,
            'form' => ['username' => '', 'full_name' => '', 'email' => '', 'phone' => ''],
        ]);
    }

    public function edit(Request $request, Response $response, array $args): Response
    {
        $institutionId = $this->requireInstitution($request, $response);
        if ($institutionId === null) {
            return $response;
        }

        $student = $this->students->find((int) $args['id'], $institutionId);
        if ($student === null) {
            throw new HttpNotFoundException($request, 'ไม่พบนักเรียนคนนี้');
        }

        return $this->view->render($response, 'students/form', [
            'page' => 'students',
            'student' => $student,
            'form' => $student,
        ]);
    }

    public function store(Request $request, Response $response): Response
    {
        $institutionId = $this->requireInstitution($request, $response);
        if ($institutionId === null) {
            return $response;
        }

        $data = (array) $request->getParsedBody();
        if (!Csrf::check($data['_token'] ?? null)) {
            Flash::error('เซสชันหมดอายุ กรุณาลองใหม่อีกครั้ง');

            return $this->redirect($response, '/students/new');
        }

        $form = [
            'username' => trim((string) ($data['username'] ?? '')),
            'full_name' => trim((string) ($data['full_name'] ?? '')),
            'national_id' => trim((string) ($data['national_id'] ?? '')),
            'email' => trim((string) ($data['email'] ?? '')),
            'phone' => trim((string) ($data['phone'] ?? '')),
        ];

        $error = $this->validateNew($institutionId, $form);
        if ($error !== null) {
            Flash::error($error);

            return $this->view->render($response, 'students/form', [
                'page' => 'students',
                'student' => null,
                'form' => $form,
            ]);
        }

        $this->students->create($institutionId, $form);
        Flash::success('เพิ่มนักเรียน ' . $form['full_name'] . ' แล้ว');

        return $this->redirect($response, '/students');
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $institutionId = $this->requireInstitution($request, $response);
        if ($institutionId === null) {
            return $response;
        }

        $id = (int) $args['id'];
        $student = $this->students->find($id, $institutionId);
        if ($student === null) {
            throw new HttpNotFoundException($request, 'ไม่พบนักเรียนคนนี้');
        }

        $data = (array) $request->getParsedBody();
        if (!Csrf::check($data['_token'] ?? null)) {
            Flash::error('เซสชันหมดอายุ กรุณาลองใหม่อีกครั้ง');

            return $this->redirect($response, '/students/' . $id . '/edit');
        }

        $form = [
            'full_name' => trim((string) ($data['full_name'] ?? '')),
            'email' => trim((string) ($data['email'] ?? '')),
            'phone' => trim((string) ($data['phone'] ?? '')),
            'national_id' => trim((string) ($data['national_id'] ?? '')),
        ];

        if ($form['full_name'] === '') {
            Flash::error('กรุณากรอกชื่อ-สกุล');

            return $this->view->render($response, 'students/form', [
                'page' => 'students', 'student' => $student, 'form' => $form + ['username' => $student['username']],
            ]);
        }

        if ($form['national_id'] !== '' && preg_match(self::NATIONAL_ID_PATTERN, $form['national_id']) !== 1) {
            Flash::error('เลขประจำตัวประชาชนต้องเป็นตัวเลข 13 หลัก');

            return $this->view->render($response, 'students/form', [
                'page' => 'students', 'student' => $student, 'form' => $form + ['username' => $student['username']],
            ]);
        }

        $this->students->update($id, $form);
        Flash::success('บันทึกข้อมูลนักเรียนแล้ว');

        return $this->redirect($response, '/students');
    }

    public function importForm(Request $request, Response $response): Response
    {
        if ($this->requireInstitution($request, $response) === null) {
            return $response;
        }

        return $this->view->render($response, 'students/import', ['page' => 'students', 'result' => null]);
    }

    /**
     * ดาวน์โหลดไฟล์ต้นแบบสำหรับนำเข้ารายชื่อนักเรียน
     * ถ้าเซิร์ฟเวอร์ยังไม่ได้ติดตั้งไลบรารี Excel จะส่งเป็น CSV แทน (Excel เปิดได้เหมือนกัน)
     */
    public function template(Request $request, Response $response): Response
    {
        if (!class_exists(Spreadsheet::class)) {
            return $this->csvTemplate($response);
        }

        $sheet = new Spreadsheet();
        $active = $sheet->getActiveSheet();
        $active->setTitle('นำเข้านักเรียน');
        $active->fromArray(self::TEMPLATE_HEADERS, null, 'A1');
        $active->fromArray(['66201001', 'สมชาย ใจดี', '1100500123456', 'somchai@example.com', '0812345678'], null, 'A2');
        foreach (range('A', 'E') as $col) {
            $active->getColumnDimension($col)->setAutoSize(true);
        }

        $stream = fopen('php://temp', 'w+');
        (new Xlsx($sheet))->save($stream);
        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);

        $response->getBody()->write($content);

        return $response
            ->withHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->withHeader('Content-Disposition', 'attachment; filename="student_import_template.xlsx"');
    }

    /** ไฟล์ต้นแบบแบบ CSV สำรอง — BOM นำหน้าเพื่อให้ Excel อ่านภาษาไทยถูก */
    private function csvTemplate(Response $response): Response
    {
        $rows = [
            self::TEMPLATE_HEADERS,
            ['66201001', 'สมชาย ใจดี', '1100500123456', 'somchai@example.com', '0812345678'],
        ];

        $csv = "\xEF\xBB\xBF";
        foreach ($rows as $row) {
            $csv .= implode(',', array_map(static fn (string $v): string => '"' . str_replace('"', '""', $v) . '"', $row)) . "\r\n";
        }

        $response->getBody()->write($csv);

        return $response
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="student_import_template.csv"');
    }

    /** นำเข้ารายชื่อนักเรียนจากไฟล์ Excel — เพิ่มใหม่หรืออัปเดตข้อมูลถ้ารหัสนักศึกษาซ้ำในสถานศึกษาเดียวกัน */
    public function import(Request $request, Response $response): Response
    {
        if (!class_exists(IOFactory::class)) {
            Flash::error('เซิร์ฟเวอร์ยังไม่ได้ติดตั้งไลบรารีอ่านไฟล์ Excel กรุณาแจ้งผู้ดูแลระบบให้รัน composer install');

            return $this->redirect($response, '/students/import');
        }

        $institutionId = $this->requireInstitution($request, $response);
        if ($institutionId === null) {
            return $response;
        }

        $data = (array) $request->getParsedBody();
        if (!Csrf::check($data['_token'] ?? null)) {
            Flash::error('เซสชันหมดอายุ กรุณาลองใหม่อีกครั้ง');

            return $this->redirect($response, '/students/import');
        }

        $file = $request->getUploadedFiles()['file'] ?? null;
        if (!$file instanceof UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
            Flash::error('กรุณาเลือกไฟล์ Excel (.xlsx) ที่ต้องการนำเข้า');

            return $this->redirect($response, '/students/import');
        }

        $tmpPath = tempnam(sys_get_temp_dir(), 'rvc_import_');
        $file->moveTo($tmpPath);

        try {
            $rows = $this->readRows($tmpPath);
        } catch (Throwable $e) {
            @unlink($tmpPath);
            Flash::error('อ่านไฟล์ไม่สำเร็จ — ตรวจสอบว่าเป็นไฟล์ .xlsx ที่ใช้โครงสร้างตามไฟล์ต้นแบบ');

            return $this->redirect($response, '/students/import');
        }
        @unlink($tmpPath);

        $created = 0;
        $updated = 0;
        $errors = [];

        foreach ($rows as $rowNumber => $row) {
            [$username, $fullName, $nationalId, $email, $phone] = $row;
            $username = trim((string) $username);
            $fullName = trim((string) $fullName);
            $nationalId = trim((string) $nationalId);
            $email = trim((string) $email);
            $phone = trim((string) $phone);

            if ($username === '' && $fullName === '' && $nationalId === '') {
                continue; // แถวว่าง
            }

            if (preg_match(self::USERNAME_PATTERN, $username) !== 1) {
                $errors[] = "แถว $rowNumber: รหัสนักศึกษาไม่ถูกต้อง";
                continue;
            }

            if ($fullName === '') {
                $errors[] = "แถว $rowNumber: ไม่ได้ระบุชื่อ-สกุล";
                continue;
            }

            $existing = $this->db->first(
                "SELECT id FROM {users} WHERE institution_id = ? AND username = ? AND role = 'student'",
                [$institutionId, $username]
            );

            if ($existing === null) {
                if (preg_match(self::NATIONAL_ID_PATTERN, $nationalId) !== 1) {
                    $errors[] = "แถว $rowNumber: เลขประจำตัวประชาชนต้องเป็นตัวเลข 13 หลัก";
                    continue;
                }

                if ($this->students->usernameTaken($institutionId, $username)) {
                    $errors[] = "แถว $rowNumber: รหัสนักศึกษา $username ซ้ำกับที่มีอยู่แล้ว";
                    continue;
                }

                $this->students->create($institutionId, [
                    'username' => $username, 'full_name' => $fullName,
                    'national_id' => $nationalId, 'email' => $email, 'phone' => $phone,
                ]);
                $created++;
            } else {
                if ($nationalId !== '' && preg_match(self::NATIONAL_ID_PATTERN, $nationalId) !== 1) {
                    $errors[] = "แถว $rowNumber: เลขประจำตัวประชาชนต้องเป็นตัวเลข 13 หลัก";
                    continue;
                }

                $this->students->update((int) $existing['id'], [
                    'full_name' => $fullName, 'email' => $email, 'phone' => $phone, 'national_id' => $nationalId,
                ]);
                $updated++;
            }
        }

        return $this->view->render($response, 'students/import', [
            'page' => 'students',
            'result' => ['created' => $created, 'updated' => $updated, 'errors' => $errors],
        ]);
    }

    /** @return list<array{0:mixed,1:mixed,2:mixed,3:mixed,4:mixed}> แถวข้อมูล (ไม่รวมหัวตาราง) คีย์ = เลขแถวจริงในไฟล์ */
    private function readRows(string $path): array
    {
        $spreadsheet = IOFactory::load($path);
        $active = $spreadsheet->getActiveSheet();
        $rows = [];

        foreach ($active->toArray(null, true, true, false) as $i => $cells) {
            if ($i === 0) {
                continue; // หัวตาราง
            }
            $rows[$i + 1] = [$cells[0] ?? '', $cells[1] ?? '', $cells[2] ?? '', $cells[3] ?? '', $cells[4] ?? ''];
        }

        return $rows;
    }

    private function validateNew(int $institutionId, array $form): ?string
    {
        if (preg_match(self::USERNAME_PATTERN, $form['username']) !== 1) {
            return 'รหัสนักศึกษาต้องยาว 2 ตัวขึ้นไป ใช้ได้เฉพาะ a-z A-Z 0-9 . _ -';
        }

        if ($this->students->usernameTaken($institutionId, $form['username'])) {
            return 'รหัสนักศึกษานี้มีอยู่แล้วในสถานศึกษาของคุณ';
        }

        if ($form['full_name'] === '') {
            return 'กรุณากรอกชื่อ-สกุล';
        }

        if (preg_match(self::NATIONAL_ID_PATTERN, $form['national_id']) !== 1) {
            return 'เลขประจำตัวประชาชนต้องเป็นตัวเลข 13 หลัก';
        }

        return null;
    }

    /** คืน institution_id ของครูที่ล็อกอินอยู่ — ถ้ายังไม่มีให้แจ้งเตือนและเปลี่ยนเส้นทางกลับ */
    private function requireInstitution(Request $request, Response &$response): ?int
    {
        $user = $request->getAttribute('user');
        $row = $this->db->first('SELECT institution_id FROM {users} WHERE id = ?', [(int) $user['id']]);
        $institutionId = $row['institution_id'] ?? null;

        if ($institutionId === null) {
            Flash::error('บัญชีของคุณยังไม่ได้ระบุสถานศึกษา กรุณาติดต่อผู้ดูแลระบบเพื่อกำหนดสถานศึกษาก่อนจัดการข้อมูลนักเรียน');
            $response = $this->redirect($response, '/dashboard');

            return null;
        }

        return (int) $institutionId;
    }

    private function redirect(Response $response, string $path): Response
    {
        return $response->withHeader('Location', Url::to($path))->withStatus(302);
    }
}
