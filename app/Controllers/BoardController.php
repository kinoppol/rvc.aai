<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Domain\BoardRepository;
use App\Support\Csrf;
use App\Support\Flash;
use App\Support\Thai;
use App\Support\Url;
use App\Support\View;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpNotFoundException;

final class BoardController
{
    private const CATEGORIES = [
        'lms'      => 'พัฒนาระบบ LMS',
        'teaching' => 'การจัดการเรียนการสอน',
        'general'  => 'ทั่วไป',
        'other'    => 'อื่น ๆ',
    ];

    private const PER_PAGE = 20;

    public function __construct(
        private readonly View $view,
        private readonly BoardRepository $board,
        private readonly Auth $auth,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $groups = $this->board->groups((int) $user['id']);
        $byCategory = [];
        foreach (self::CATEGORIES as $key => $label) {
            $byCategory[$key] = ['label' => $label, 'groups' => []];
        }
        foreach ($groups as $g) {
            $byCategory[$g['category']]['groups'][] = $g;
        }

        return $this->view->render($response, 'board/index', [
            'page' => 'board',
            'byCategory' => $byCategory,
            'totalGroups' => count($groups),
        ]);
    }

    public function newGroup(Request $request, Response $response): Response
    {
        return $this->view->render($response, 'board/group-edit', [
            'page' => 'board',
            'group' => null,
            'categories' => self::CATEGORIES,
        ]);
    }

    public function saveGroup(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $data = (array) $request->getParsedBody();

        if (!Csrf::check($data['_token'] ?? null)) {
            Flash::error('เซสชันหมดอายุ');

            return $this->redirect($response, '/board/new');
        }

        $name = mb_substr(trim((string) ($data['name'] ?? '')), 0, 120);
        $description = mb_substr(trim((string) ($data['description'] ?? '')), 0, 1000) ?: null;
        $category = array_key_exists($data['category'] ?? '', self::CATEGORIES) ? $data['category'] : 'general';
        $isOpen = !empty($data['is_open']) ? 1 : 0;

        if ($name === '') {
            Flash::error('กรุณาใส่ชื่อกลุ่ม');

            return $this->redirect($response, '/board/new');
        }

        $groupId = $this->board->create([
            'name' => $name,
            'description' => $description,
            'category' => $category,
            'is_open' => $isOpen,
            'created_by' => (int) $user['id'],
        ]);

        $this->auth->log('board.group.create', 'group#' . $groupId, ['name' => $name]);
        Flash::success('สร้างกลุ่ม "' . $name . '" แล้ว');

        return $this->redirect($response, '/board/' . $groupId);
    }

    public function group(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $group = $this->requireGroup($request, (int) $args['groupId']);
        $membership = $this->board->membership((int) $group['id'], (int) $user['id']);

        $q = $request->getQueryParams();
        $page = max(1, (int) ($q['page'] ?? 1));
        $offset = ($page - 1) * self::PER_PAGE;
        $total = $this->board->topicCount((int) $group['id']);
        $topics = [];
        $pendingCount = 0;
        $isOpen = (int) ($group['is_open'] ?? 0) === 1;
        $isApprovedMember = $membership !== null && $membership['status'] === 'approved';
        $canView = $isOpen || $isApprovedMember;

        if ($canView) {
            $topics = $this->board->topics((int) $group['id'], $offset, self::PER_PAGE);
            foreach ($topics as &$t) {
                $t['created_ago'] = Thai::ago($t['created_at']);
                $t['updated_ago'] = Thai::ago($t['updated_at']);
            }
            unset($t);
            if ((int) $group['created_by'] === (int) $user['id']) {
                $pendingCount = $this->board->pendingCount((int) $group['id']);
            }
        }

        return $this->view->render($response, 'board/group', [
            'page' => 'board',
            'group' => $group,
            'membership' => $membership,
            'isCreator' => (int) $group['created_by'] === (int) $user['id'],
            'isOpen' => $isOpen,
            'isApprovedMember' => $isApprovedMember,
            'canView' => $canView,
            'topics' => $topics,
            'pendingCount' => $pendingCount,
            'total' => $total,
            'currentPage' => $page,
            'totalPages' => (int) ceil($total / self::PER_PAGE),
        ]);
    }

    public function join(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $group = $this->requireGroup($request, (int) $args['groupId']);

        $data = (array) $request->getParsedBody();
        if (!Csrf::check($data['_token'] ?? null)) {
            Flash::error('เซสชันหมดอายุ');

            return $this->redirect($response, '/board/' . $group['id']);
        }

        $existing = $this->board->membership((int) $group['id'], (int) $user['id']);
        if ($existing === null) {
            $this->board->requestJoin((int) $group['id'], (int) $user['id']);
            $this->auth->log('board.join.request', 'group#' . $group['id']);
            Flash::success('ส่งคำขอเข้าร่วมแล้ว · รอผู้สร้างกลุ่มอนุมัติ');
        }

        return $this->redirect($response, '/board/' . $group['id']);
    }

    public function leave(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $group = $this->requireGroup($request, (int) $args['groupId']);

        if ((int) $group['created_by'] === (int) $user['id']) {
            Flash::error('ผู้สร้างกลุ่มไม่สามารถออกจากกลุ่มได้');

            return $this->redirect($response, '/board/' . $group['id']);
        }

        $data = (array) $request->getParsedBody();
        if (!Csrf::check($data['_token'] ?? null)) {
            Flash::error('เซสชันหมดอายุ');

            return $this->redirect($response, '/board/' . $group['id']);
        }

        $this->board->removeMember((int) $group['id'], (int) $user['id']);
        Flash::success('ออกจากกลุ่มแล้ว');

        return $this->redirect($response, '/board');
    }

    public function members(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $group = $this->requireGroup($request, (int) $args['groupId']);
        $this->requireCreator($request, $group, (int) $user['id']);

        $members = $this->board->members((int) $group['id']);

        return $this->view->render($response, 'board/members', [
            'page' => 'board',
            'group' => $group,
            'members' => $members,
        ]);
    }

    public function approveMember(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $group = $this->requireGroup($request, (int) $args['groupId']);
        $this->requireCreator($request, $group, (int) $user['id']);

        $data = (array) $request->getParsedBody();
        if (!Csrf::check($data['_token'] ?? null)) {
            Flash::error('เซสชันหมดอายุ');

            return $this->redirect($response, '/board/' . $group['id'] . '/members');
        }

        $this->board->approveMember((int) $group['id'], (int) $args['userId']);
        $this->auth->log('board.member.approve', 'group#' . $group['id'], ['user_id' => (int) $args['userId']]);

        return $this->redirect($response, '/board/' . $group['id'] . '/members');
    }

    public function rejectMember(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $group = $this->requireGroup($request, (int) $args['groupId']);
        $this->requireCreator($request, $group, (int) $user['id']);

        $data = (array) $request->getParsedBody();
        if (!Csrf::check($data['_token'] ?? null)) {
            Flash::error('เซสชันหมดอายุ');

            return $this->redirect($response, '/board/' . $group['id'] . '/members');
        }

        $this->board->removeMember((int) $group['id'], (int) $args['userId']);
        $this->auth->log('board.member.reject', 'group#' . $group['id'], ['user_id' => (int) $args['userId']]);

        return $this->redirect($response, '/board/' . $group['id'] . '/members');
    }

    public function newTopic(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $group = $this->requireGroup($request, (int) $args['groupId']);
        $this->requireMember($request, $group, (int) $user['id']);

        return $this->view->render($response, 'board/topic-edit', [
            'page' => 'board',
            'group' => $group,
        ]);
    }

    public function saveTopic(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $group = $this->requireGroup($request, (int) $args['groupId']);
        $this->requireMember($request, $group, (int) $user['id']);

        $data = (array) $request->getParsedBody();
        if (!Csrf::check($data['_token'] ?? null)) {
            Flash::error('เซสชันหมดอายุ');

            return $this->redirect($response, '/board/' . $group['id'] . '/topics/new');
        }

        $title = mb_substr(trim((string) ($data['title'] ?? '')), 0, 200);
        $body = mb_substr(trim((string) ($data['body'] ?? '')), 0, 10000);

        if ($title === '' || $body === '') {
            Flash::error('กรุณาใส่หัวข้อและเนื้อหา');

            return $this->redirect($response, '/board/' . $group['id'] . '/topics/new');
        }

        $topicId = $this->board->createTopic([
            'group_id' => (int) $group['id'],
            'author_id' => (int) $user['id'],
            'title' => $title,
            'body' => $body,
        ]);

        $this->auth->log('board.topic.create', 'topic#' . $topicId);
        Flash::success('ตั้งกระทู้แล้ว');

        return $this->redirect($response, '/board/' . $group['id'] . '/topics/' . $topicId);
    }

    public function topic(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $group = $this->requireGroup($request, (int) $args['groupId']);
        $this->requireMember($request, $group, (int) $user['id']);

        $topic = $this->board->findTopic((int) $args['topicId']);
        if ($topic === null || (int) $topic['group_id'] !== (int) $group['id']) {
            throw new HttpNotFoundException($request, 'ไม่พบกระทู้นี้');
        }

        $replies = $this->board->replies((int) $topic['id']);
        foreach ($replies as &$r) {
            $r['created_ago'] = Thai::ago($r['created_at']);
        }
        unset($r);

        return $this->view->render($response, 'board/topic', [
            'page' => 'board',
            'group' => $group,
            'topic' => $topic,
            'topicAgo' => Thai::ago($topic['created_at']),
            'replies' => $replies,
        ]);
    }

    public function saveReply(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $group = $this->requireGroup($request, (int) $args['groupId']);
        $this->requireMember($request, $group, (int) $user['id']);

        $topic = $this->board->findTopic((int) $args['topicId']);
        if ($topic === null || (int) $topic['group_id'] !== (int) $group['id']) {
            throw new HttpNotFoundException($request, 'ไม่พบกระทู้นี้');
        }

        $data = (array) $request->getParsedBody();
        if (!Csrf::check($data['_token'] ?? null)) {
            Flash::error('เซสชันหมดอายุ');

            return $this->redirect($response, '/board/' . $group['id'] . '/topics/' . $topic['id']);
        }

        $body = mb_substr(trim((string) ($data['body'] ?? '')), 0, 10000);
        if ($body === '') {
            Flash::error('กรุณาใส่เนื้อหาความคิดเห็น');

            return $this->redirect($response, '/board/' . $group['id'] . '/topics/' . $topic['id']);
        }

        $this->board->createReply([
            'topic_id' => (int) $topic['id'],
            'author_id' => (int) $user['id'],
            'body' => $body,
        ]);

        return $this->redirect($response, '/board/' . $group['id'] . '/topics/' . $topic['id'] . '#replies');
    }

    // ---- ภายใน ----

    /** @return array<string,mixed> */
    private function requireGroup(Request $request, int $groupId): array
    {
        $group = $this->board->find($groupId);
        if ($group === null) {
            throw new HttpNotFoundException($request, 'ไม่พบกลุ่มสนทนานี้');
        }

        return $group;
    }

    private function requireCreator(Request $request, array $group, int $userId): void
    {
        if ((int) $group['created_by'] !== $userId) {
            throw new HttpForbiddenException($request, 'เฉพาะผู้สร้างกลุ่มเท่านั้น');
        }
    }

    private function requireMember(Request $request, array $group, int $userId): void
    {
        if ((int) ($group['is_open'] ?? 0) === 1) {
            return;
        }

        $m = $this->board->membership((int) $group['id'], $userId);
        if ($m === null || $m['status'] !== 'approved') {
            throw new HttpForbiddenException($request, 'คุณไม่ได้เป็นสมาชิกที่ได้รับอนุมัติของกลุ่มนี้');
        }
    }

    private function redirect(Response $response, string $path): Response
    {
        return $response->withHeader('Location', Url::to($path))->withStatus(302);
    }
}
