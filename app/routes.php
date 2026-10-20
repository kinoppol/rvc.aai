<?php

declare(strict_types=1);

use App\Auth\AuthMiddleware;
use App\Auth\RoleMiddleware;
use App\Auth\Roles;
use App\Controllers\Admin\AiController as AdminAiController;
use App\Controllers\Admin\DemoController;
use App\Controllers\Admin\CoursesController as AdminCoursesController;
use App\Controllers\Admin\GeneralController;
use App\Controllers\Admin\LogsController;
use App\Controllers\Admin\MigrationController;
use App\Controllers\Admin\RmsController;
use App\Controllers\Admin\UsersController as AdminUsersController;
use App\Controllers\AiChatController;
use App\Controllers\AppearanceController;
use App\Controllers\AssignmentController;
use App\Controllers\AuthController;
use App\Controllers\ClassToolsController;
use App\Controllers\CourseController;
use App\Controllers\CourseOutcomesController;
use App\Controllers\DashboardController;
use App\Controllers\ImpersonationController;
use App\Controllers\JoinController;
use App\Controllers\CourseCoverController;
use App\Controllers\LandingController;
use App\Controllers\BoardController;
use App\Controllers\UnitController;
use App\Controllers\UnitOutlineController;
use App\Controllers\LessonPlanController;
use App\Controllers\PlanPrintController;
use App\Controllers\PlanReviewController;
use App\Controllers\QuizWizardController;
use App\Controllers\PublicCourseController;
use App\Controllers\RegisterController;
use App\Controllers\ResetPasswordController;
use App\Controllers\ReviewController;
use App\Controllers\SettingsController;
use App\Controllers\SsoController;
use App\Controllers\SsoSignupController;
use App\Controllers\StudentController;
use App\Controllers\Teacher\StudentsController as TeacherStudentsController;
use App\Support\ViewContext;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return static function (App $app): void {
    $app->get('/', [LandingController::class, 'index'])->setName('home');

    $app->get('/login', [AuthController::class, 'show'])->setName('login');
    $app->post('/login', [AuthController::class, 'login']);
    // ทางเข้าฉุกเฉินของผู้ดูแลระบบเมื่อเปิดเฉพาะ RVC-ONE — ไม่พึ่ง RVC-ONE
    $app->get('/login/admin', [AuthController::class, 'adminLogin'])->setName('login.admin');
    $app->post('/logout', [AuthController::class, 'logout'])->setName('logout');

    // เข้าสู่ระบบผ่าน RVC-ONE (SSO) — /auth/callback ต้องตรงกับ redirect_uri ที่ลงทะเบียนไว้
    $app->get('/auth/login', [SsoController::class, 'start'])->setName('sso.start');
    $app->get('/auth/rvc-one', [SsoController::class, 'start']); // ลิงก์เดิม
    $app->map(['GET', 'POST'], '/auth/callback', [SsoController::class, 'callback'])->setName('sso.callback');
    // นักศึกษาปริญญาตรีที่ยืนยันตัวตนกับ RVC-ONE แล้วแต่ยังไม่มีบัญชี — กรอกข้อมูลเองก่อนเข้าใช้งาน
    $app->get('/auth/register-student', [SsoSignupController::class, 'show'])->setName('sso.register');
    $app->post('/auth/register-student', [SsoSignupController::class, 'save']);

    // ครูทั่วไปสมัครเข้าใช้ระบบเอง — บัญชีเริ่มที่สถานะ "รออนุมัติ" เสมอ
    $app->get('/register', [RegisterController::class, 'show'])->setName('register');
    $app->post('/register', [RegisterController::class, 'register']);

    // รายวิชาที่ครูเผยแพร่ — คนทั่วไปอ่านเนื้อหาได้โดยไม่ต้องสมัครหรือเข้าสู่ระบบ
    $app->get('/course/{id:[0-9]+}', [PublicCourseController::class, 'show'])->setName('public.course');
    $app->get('/course/{id:[0-9]+}/unit/{unitId:[0-9]+}', [PublicCourseController::class, 'unit'])->setName('public.unit');
    // รูปหน้าปกรายวิชา — ตรวจสิทธิ์ใน controller (ล็อกอิน หรือวิชาที่เปิดให้แสดงในหน้าแรก)
    $app->get('/course-cover/{id:[0-9]+}', [CourseCoverController::class, 'show']);

    // ลิงก์รีเซ็ตรหัสผ่านที่ผู้ดูแลระบบสร้างให้ครูที่ลืมรหัสผ่าน — ไม่ต้องล็อกอินก่อน
    $app->get('/reset-password/{token}', [ResetPasswordController::class, 'show'])->setName('reset-password');
    $app->post('/reset-password/{token}', [ResetPasswordController::class, 'update']);

    $app->group('', function (RouteCollectorProxy $group): void {
        $group->get('/dashboard', [DashboardController::class, 'index'])->setName('dashboard');

        // เลิกสวมสิทธิ์ — ต้องเข้าถึงได้ทุกบทบาทที่ล็อกอินอยู่ จึงอยู่นอกกลุ่มตามบทบาท
        $group->post('/impersonate/stop', [ImpersonationController::class, 'stop'])->setName('impersonate.stop');

        // ช่องสนทนาลอยสำหรับทดสอบว่าผู้ช่วย AI เชื่อมต่อและตอบกลับได้จริง (ไม่ตัดโควตา)
        $group->group('/ai/chat', function (RouteCollectorProxy $c): void {
            $c->get('/status', [AiChatController::class, 'status']);
            $c->get('/stream', [AiChatController::class, 'stream']);
            $c->post('/prepare', [AiChatController::class, 'prepare']);
            $c->post('/attach', [AiChatController::class, 'attach']);
        })->add(new RoleMiddleware(Roles::STAFF));

        // ผู้ช่วยสร้างรายวิชา/หน่วยการเรียนให้ตามคำขอ — ครูต้องกดยืนยันจากการ์ดในช่องสนทนาก่อนเสมอ
        $group->post('/ai/chat/create-course', [AiChatController::class, 'createCourse'])
            ->add(new RoleMiddleware(Roles::TEACHING));
        $group->post('/ai/chat/create-unit', [AiChatController::class, 'createUnit'])
            ->add(new RoleMiddleware(Roles::TEACHING));

        // แท็บเครื่องมือในหน้าต่างผู้ช่วย: รายชื่อนักเรียนของรายวิชาให้วงล้อสุ่มชื่อ
        $group->get('/tools/roster', [ClassToolsController::class, 'roster'])
            ->add(new RoleMiddleware(Roles::TEACHING));

        $group->group('/courses', function (RouteCollectorProxy $t): void {
            $t->get('', [CourseController::class, 'index'])->setName('courses');
            $t->post('/{id:[0-9]+}/landing', [CourseController::class, 'landingVisibility']);
            $t->get('/new', [CourseController::class, 'edit'])->setName('course.new');
            // ผู้ช่วยสรุปผลลัพธ์การเรียนรู้ระดับรายวิชาจากค่าบนฟอร์มรายวิชา (ตอบ JSON ยังไม่บันทึก)
            $t->post('/draft-outcomes', [CourseOutcomesController::class, 'draft']);
            $t->post('', [CourseController::class, 'save']);
            $t->get('/{id:[0-9]+}', [CourseController::class, 'show'])->setName('course.show');
            // พิมพ์แผนการจัดการเรียนรู้ตามแบบฟอร์ม สอศ. หลักสูตร พ.ศ. 2567
            $t->get('/{id:[0-9]+}/plan-print', [PlanPrintController::class, 'show']);
            $t->get('/{id:[0-9]+}/edit', [CourseController::class, 'edit']);
            $t->post('/{id:[0-9]+}', [CourseController::class, 'save']);
            $t->post('/{id:[0-9]+}/archive', [CourseController::class, 'archive']);
            $t->post('/{id:[0-9]+}/quizzes/{quizId:[0-9]+}/delete', [CourseController::class, 'deleteQuiz']);
            $t->post('/{id:[0-9]+}/quizzes/{quizId:[0-9]+}/restore', [CourseController::class, 'restoreQuiz']);
            $t->post('/{id:[0-9]+}/quizzes/{quizId:[0-9]+}/destroy', [CourseController::class, 'destroyQuiz']);
            // รหัส/ลิงก์ให้นักเรียนเข้าร่วมรายวิชาเอง — เปิด ปิด หรือสุ่มรหัสใหม่
            $t->post('/{id:[0-9]+}/join', [CourseController::class, 'joinSettings']);
            // ครูเพิ่มนักเรียนที่มีบัญชีอยู่แล้วเข้ารายวิชาโดยระบุอีเมล
            $t->post('/{id:[0-9]+}/students', [CourseController::class, 'addStudents']);
            // เพิ่มนักเรียนที่ติ๊กเลือกจากกลุ่มเรียนของรายวิชา
            $t->post('/{id:[0-9]+}/students/classroom', [CourseController::class, 'addClassroomStudents']);
            // ให้ AI ออกแบบรายชื่อหน่วยการเรียนให้ครอบคลุมคำอธิบายรายวิชา (ครูเลือกก่อนบันทึก)
            $t->get('/{courseId:[0-9]+}/units/design', [UnitOutlineController::class, 'form']);
            $t->post('/{courseId:[0-9]+}/units/design', [UnitOutlineController::class, 'generate']);
            $t->post('/{courseId:[0-9]+}/units/design/save', [UnitOutlineController::class, 'save']);

            $t->get('/{courseId:[0-9]+}/units/new', [UnitController::class, 'edit']);
            $t->get('/{courseId:[0-9]+}/units/{id:[0-9]+}/edit', [UnitController::class, 'edit']);
            $t->get('/{courseId:[0-9]+}/units/{id:[0-9]+}/export.md', [UnitController::class, 'exportMarkdown']);
            $t->get('/{courseId:[0-9]+}/preview', [StudentController::class, 'previewCourse']);
            $t->get('/{courseId:[0-9]+}/units/{id:[0-9]+}/preview', [StudentController::class, 'preview']);
            $t->get('/{courseId:[0-9]+}/units/{id:[0-9]+}/preview/{kind:section|worksheet}/{itemId:[0-9]+}', [StudentController::class, 'previewItem']);
            $t->post('/{courseId:[0-9]+}/units[/{id:[0-9]+}]', [UnitController::class, 'save']);
            // ใบงานของหน่วยการเรียน — เขียนเองหรือให้ผู้ช่วย AI ร่างให้แล้วแก้ต่อ
            $t->get('/{courseId:[0-9]+}/units/{unitId:[0-9]+}/assignments/new', [AssignmentController::class, 'edit']);
            $t->post('/{courseId:[0-9]+}/units/{unitId:[0-9]+}/assignments', [AssignmentController::class, 'save']);
            $t->post('/{courseId:[0-9]+}/units/{unitId:[0-9]+}/assignments/draft', [AssignmentController::class, 'draft']);
            $t->get('/{courseId:[0-9]+}/units/{unitId:[0-9]+}/assignments/{id:[0-9]+}/edit', [AssignmentController::class, 'edit']);
            $t->post('/{courseId:[0-9]+}/units/{unitId:[0-9]+}/assignments/{id:[0-9]+}', [AssignmentController::class, 'save']);
            $t->post('/{courseId:[0-9]+}/units/{unitId:[0-9]+}/assignments/{id:[0-9]+}/draft', [AssignmentController::class, 'draft']);
            $t->post('/{courseId:[0-9]+}/units/{unitId:[0-9]+}/assignments/{id:[0-9]+}/delete', [AssignmentController::class, 'delete']);
            // งานที่มอบหมายเพิ่มเติม (นอกเหนือจากใบงานของหน่วย) — ใช้ AssignmentController เดียวกัน
            $t->get('/{courseId:[0-9]+}/tasks/new', [AssignmentController::class, 'edit']);
            $t->post('/{courseId:[0-9]+}/tasks', [AssignmentController::class, 'save']);
            $t->post('/{courseId:[0-9]+}/tasks/draft', [AssignmentController::class, 'draft']);
            $t->get('/{courseId:[0-9]+}/tasks/{id:[0-9]+}/edit', [AssignmentController::class, 'edit']);
            $t->post('/{courseId:[0-9]+}/tasks/{id:[0-9]+}', [AssignmentController::class, 'save']);
            $t->post('/{courseId:[0-9]+}/tasks/{id:[0-9]+}/draft', [AssignmentController::class, 'draft']);
            $t->post('/{courseId:[0-9]+}/tasks/{id:[0-9]+}/delete', [AssignmentController::class, 'delete']);

            $t->post('/{courseId:[0-9]+}/units/{id:[0-9]+}/report', [UnitController::class, 'saveReport']);
            $t->post('/{courseId:[0-9]+}/units/{id:[0-9]+}/content-draft', [UnitController::class, 'draftContent']);
            $t->post('/{courseId:[0-9]+}/units/{id:[0-9]+}/content-draft/save', [UnitController::class, 'saveDraftContent']);
            $t->post('/{courseId:[0-9]+}/units/{id:[0-9]+}/sections', [UnitController::class, 'addSection']);
            $t->post('/{courseId:[0-9]+}/units/{id:[0-9]+}/sections/{sectionId:[0-9]+}', [UnitController::class, 'updateSection']);
            $t->post('/{courseId:[0-9]+}/units/{id:[0-9]+}/sections/{sectionId:[0-9]+}/delete', [UnitController::class, 'deleteSection']);

            $t->get('/{courseId:[0-9]+}/quizzes/create', [QuizWizardController::class, 'create']);
            $t->get('/{courseId:[0-9]+}/quizzes/workspace', [QuizWizardController::class, 'workspace']);
            $t->get('/{courseId:[0-9]+}/quizzes/stream', [QuizWizardController::class, 'stream']);
            $t->get('/{courseId:[0-9]+}/quizzes/{quizId:[0-9]+}/review', [QuizWizardController::class, 'review']);
            $t->post('/{courseId:[0-9]+}/quizzes/{quizId:[0-9]+}', [QuizWizardController::class, 'save']);
            $t->post('/{courseId:[0-9]+}/quizzes/{quizId:[0-9]+}/questions/{qid:[0-9]+}/regenerate', [QuizWizardController::class, 'regenerateOne']);
            $t->post('/{courseId:[0-9]+}/quizzes/{quizId:[0-9]+}/questions/{qid:[0-9]+}/delete', [QuizWizardController::class, 'deleteQuestion']);

            $t->get('/{courseId:[0-9]+}/lesson-plan', [LessonPlanController::class, 'form']);
            $t->get('/{courseId:[0-9]+}/lesson-plan/stream', [LessonPlanController::class, 'stream']);
            $t->get('/{courseId:[0-9]+}/lesson-plan/{id:[0-9]+}', [LessonPlanController::class, 'edit']);
            $t->get('/{courseId:[0-9]+}/lesson-plan/{id:[0-9]+}/export.{format:doc|pdf}', [LessonPlanController::class, 'export']);
            $t->post('/{courseId:[0-9]+}/lesson-plan/{id:[0-9]+}', [LessonPlanController::class, 'save']);
        })->add(new RoleMiddleware(Roles::TEACHING));

        $group->get('/review', [ReviewController::class, 'index'])
            ->setName('review')->add(new RoleMiddleware(Roles::TEACHING));

        // ครูจัดการข้อมูลนักเรียนของสถานศึกษาตัวเอง — เพิ่มทีละคนหรือนำเข้าจาก Excel
        $group->group('/students', function (RouteCollectorProxy $s): void {
            $s->get('', [TeacherStudentsController::class, 'index'])->setName('students');
            $s->get('/new', [TeacherStudentsController::class, 'create']);
            $s->post('', [TeacherStudentsController::class, 'store']);
            $s->get('/import', [TeacherStudentsController::class, 'importForm']);
            $s->get('/template', [TeacherStudentsController::class, 'template']);
            $s->post('/import', [TeacherStudentsController::class, 'import']);
            $s->get('/{id:[0-9]+}/edit', [TeacherStudentsController::class, 'edit']);
            $s->post('/{id:[0-9]+}', [TeacherStudentsController::class, 'update']);
        })->add(new RoleMiddleware(Roles::TEACHING));

        // ถังขยะของครู: แบบทดสอบที่ลบไว้จากทุกรายวิชา (กู้คืน/ลบถาวรผ่าน /courses/{id}/quizzes/{quizId}/restore|destroy)
        $group->get('/trash', [CourseController::class, 'trash'])->add(new RoleMiddleware(Roles::TEACHING));

        // ตรวจแผนการสอน (อ่านอย่างเดียว) — ตัวควบคุมตรวจ Roles::canReviewPlans() เอง เพราะหัวหน้างานหลักสูตรเป็นค่าตั้งรายผู้ใช้ ไม่ใช่บทบาท
        $group->group('/plans', function (RouteCollectorProxy $p): void {
            $p->get('', [PlanReviewController::class, 'index']);
            $p->get('/{id:[0-9]+}', [PlanPrintController::class, 'show']);
        })->add(new RoleMiddleware(Roles::STAFF));

        $group->group('/board', function (RouteCollectorProxy $b): void {
            $b->get('', [BoardController::class, 'index'])->setName('board');
            $b->get('/new', [BoardController::class, 'newGroup']);
            $b->post('', [BoardController::class, 'saveGroup']);
            $b->get('/{groupId:[0-9]+}', [BoardController::class, 'group']);
            $b->post('/{groupId:[0-9]+}/join', [BoardController::class, 'join']);
            $b->post('/{groupId:[0-9]+}/leave', [BoardController::class, 'leave']);
            $b->get('/{groupId:[0-9]+}/members', [BoardController::class, 'members']);
            $b->post('/{groupId:[0-9]+}/members/{userId:[0-9]+}/approve', [BoardController::class, 'approveMember']);
            $b->post('/{groupId:[0-9]+}/members/{userId:[0-9]+}/reject', [BoardController::class, 'rejectMember']);
            $b->get('/{groupId:[0-9]+}/topics/new', [BoardController::class, 'newTopic']);
            $b->post('/{groupId:[0-9]+}/topics', [BoardController::class, 'saveTopic']);
            $b->get('/{groupId:[0-9]+}/topics/{topicId:[0-9]+}', [BoardController::class, 'topic']);
            $b->post('/{groupId:[0-9]+}/topics/{topicId:[0-9]+}/replies', [BoardController::class, 'saveReply']);
        })->add(new RoleMiddleware(Roles::TEACHING));

        // ลิงก์เข้าร่วมรายวิชาที่ครูแจก — ไม่จำกัดบทบาทที่นี่ เพื่อบอกครูที่เผลอเปิดลิงก์ได้ว่าลิงก์นี้สำหรับนักเรียน
        $group->get('/join/{code}', [JoinController::class, 'show'])->setName('join');

        $group->group('/learn', function (RouteCollectorProxy $s): void {
            $s->get('', [StudentController::class, 'courses'])->setName('learn');
            $s->post('/join', [JoinController::class, 'join']);
            $s->get('/{courseId:[0-9]+}', [StudentController::class, 'course']);
            $s->get('/units/{id:[0-9]+}', [StudentController::class, 'unit']);
            $s->get('/units/{id:[0-9]+}/{kind:section|worksheet}/{itemId:[0-9]+}', [StudentController::class, 'item']);
            $s->post('/quizzes/{quizId:[0-9]+}/start', [StudentController::class, 'startQuiz']);
            $s->get('/attempts/{attemptId:[0-9]+}', [StudentController::class, 'take']);
            $s->post('/attempts/{attemptId:[0-9]+}/answer', [StudentController::class, 'answer']);
            $s->post('/attempts/{attemptId:[0-9]+}/submit', [StudentController::class, 'submit']);
            $s->get('/attempts/{attemptId:[0-9]+}/result', [StudentController::class, 'result']);
        })->add(new RoleMiddleware(['student']));

        // สีหลักของหน้าจอ — ผู้ใช้ทุกบทบาทตั้งของตัวเองได้ ผู้ดูแลตั้งค่าเริ่มต้นของระบบได้ด้วย
        $group->get('/settings/appearance', [AppearanceController::class, 'index'])->setName('appearance');
        $group->post('/settings/appearance', [AppearanceController::class, 'save']);

        $group->group('/settings', function (RouteCollectorProxy $s): void {
            $s->get('/ai', [SettingsController::class, 'ai'])->setName('settings.ai');
            $s->post('/ai/test', [SettingsController::class, 'test']);
            $s->post('/ai/connect', [SettingsController::class, 'connect']);
            $s->post('/ai/disconnect', [SettingsController::class, 'disconnect']);
            $s->post('/ai/mode', [SettingsController::class, 'mode']);
            $s->post('/ai/route', [SettingsController::class, 'route']);
            $s->post('/ai/default', [SettingsController::class, 'defaultAi']);
            $s->post('/ai/keys/{id:[0-9]+}/models', [SettingsController::class, 'keyModels']);
            $s->post('/ai/keys/{id:[0-9]+}/probe', [SettingsController::class, 'keyProbe']);
        })->add(new RoleMiddleware(Roles::TEACHING));

        // งานกำกับดูแล — ผู้ดูแลระบบและผู้ดูแลครูเข้าได้เท่ากัน
        $group->group('/admin', function (RouteCollectorProxy $o): void {
            $o->get('/users', [AdminUsersController::class, 'index'])->setName('admin.users');
            $o->post('/users/{id:[0-9]+}/approve', [AdminUsersController::class, 'approve']);
            $o->post('/users/{id:[0-9]+}/reject', [AdminUsersController::class, 'reject']);
            $o->post('/users/settings', [AdminUsersController::class, 'saveSettings']);

            $o->get('/courses', [AdminCoursesController::class, 'index'])->setName('admin.courses');
            $o->get('/courses/{id:[0-9]+}', [AdminCoursesController::class, 'show']);
            $o->get('/courses/{id:[0-9]+}/units/{unitId:[0-9]+}', [AdminCoursesController::class, 'unit']);
        })->add(new RoleMiddleware(Roles::OVERSIGHT));

        // สงวนไว้ให้ผู้ดูแลระบบ — AI โควตา ฐานข้อมูล บันทึกข้อขัดข้อง ตั้งค่าระบบ
        // และงานที่กระทบบัญชีคนอื่นโดยตรง (สวมสิทธิ์ รีเซ็ตรหัสผ่าน แต่งตั้งบทบาท)
        $group->group('/admin', function (RouteCollectorProxy $admin): void {
            $admin->get('/migrations', [MigrationController::class, 'index'])->setName('admin.migrations');
            $admin->get('/migrations/preview/{name}', [MigrationController::class, 'preview']);
            $admin->post('/migrations/run', [MigrationController::class, 'run']);
            $admin->post('/migrations/rollback', [MigrationController::class, 'rollback']);
            $admin->post('/migrations/reset', [MigrationController::class, 'reset']);

            $admin->get('/demo', [DemoController::class, 'index'])->setName('admin.demo');
            $admin->post('/demo/seed', [DemoController::class, 'seed']);

            $admin->get('/ai', [AdminAiController::class, 'index'])->setName('admin.ai');
            $admin->post('/ai/cap', [AdminAiController::class, 'saveCap']);
            $admin->post('/ai/endpoint', [AdminAiController::class, 'saveEndpoint']);
            $admin->post('/ai/endpoint/test', [AdminAiController::class, 'testEndpoint']);
            $admin->post('/ai/endpoint/{id:[0-9]+}/delete', [AdminAiController::class, 'deleteEndpoint']);
            $admin->post('/ai/endpoint/{id:[0-9]+}/active', [AdminAiController::class, 'toggleEndpoint']);
            $admin->post('/ai/endpoint/{id:[0-9]+}/default', [AdminAiController::class, 'defaultEndpoint']);
            $admin->post('/ai/courses/{id:[0-9]+}/features', [AdminAiController::class, 'saveCourseFeatures']);
            $admin->post('/users/{id:[0-9]+}/impersonate', [ImpersonationController::class, 'start']);
            $admin->post('/users/{id:[0-9]+}/reset-password', [AdminUsersController::class, 'resetPassword']);
            $admin->post('/users/{id:[0-9]+}/reset-link', [AdminUsersController::class, 'resetLink']);
            $admin->post('/users/{id:[0-9]+}/role', [AdminUsersController::class, 'changeRole']);

            $admin->get('/logs', [LogsController::class, 'index'])->setName('admin.logs');
            $admin->get('/logs/download', [LogsController::class, 'download']);

            // โอนข้อมูลบุคลากร กลุ่มเรียน และนักเรียนจากระบบ RMS ของวิทยาลัย
            $admin->get('/rms', [RmsController::class, 'index'])->setName('admin.rms');
            $admin->post('/rms/url', [RmsController::class, 'saveUrl']);
            $admin->post('/rms/people', [RmsController::class, 'people']);
            $admin->post('/rms/groups', [RmsController::class, 'groups']);
            $admin->post('/rms/students/count', [RmsController::class, 'studentCount']);
            $admin->post('/rms/students/batch', [RmsController::class, 'studentBatch']);
            $admin->post('/rms/rvc-one', [RmsController::class, 'rvcOne']);

            $admin->get('/overview', [DashboardController::class, 'overview'])->setName('admin.overview');
            $admin->get('/settings', [GeneralController::class, 'index'])->setName('admin.settings');
            $admin->post('/settings', [GeneralController::class, 'save']);
            $admin->post('/settings/login', [GeneralController::class, 'saveLogin']);
            $admin->post('/settings/rvc-one', [GeneralController::class, 'saveRvcOne']);
            $admin->post('/settings/signers', [GeneralController::class, 'saveSigners']);
        })->add(new RoleMiddleware(['admin']));
    })->add(ViewContext::class)->add(AuthMiddleware::class);
};
