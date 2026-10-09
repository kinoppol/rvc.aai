<?php

declare(strict_types=1);

namespace App\Domain;

use App\Support\Db;

final class BoardRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    // ---- กลุ่ม ----

    /** @return list<array<string,mixed>> */
    public function groups(int $userId): array
    {
        return $this->db->all(
            'SELECT g.*,
                    u.full_name AS creator_name,
                    (SELECT COUNT(*) FROM {discussion_members} m WHERE m.group_id = g.id AND m.status = \'approved\') AS member_count,
                    (SELECT COUNT(*) FROM {discussion_topics} t WHERE t.group_id = g.id) AS topic_count,
                    my.status AS my_status
             FROM {discussion_groups} g
             JOIN {users} u ON u.id = g.created_by
             LEFT JOIN {discussion_members} my ON my.group_id = g.id AND my.user_id = ?
             ORDER BY g.category, g.name',
            [$userId]
        );
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->first(
            'SELECT g.*, u.full_name AS creator_name
             FROM {discussion_groups} g
             JOIN {users} u ON u.id = g.created_by
             WHERE g.id = ?',
            [$id]
        ) ?: null;
    }

    public function create(array $data): int
    {
        $id = $this->db->insert('discussion_groups', $data);
        // ผู้สร้างเป็นสมาชิกอนุมัติแล้วทันที
        $this->db->insert('discussion_members', [
            'group_id' => $id,
            'user_id' => $data['created_by'],
            'status' => 'approved',
            'joined_at' => date('Y-m-d H:i:s'),
        ]);

        return $id;
    }

    // ---- สมาชิก ----

    /** @return array<string,mixed>|null */
    public function membership(int $groupId, int $userId): ?array
    {
        return $this->db->first(
            'SELECT * FROM {discussion_members} WHERE group_id = ? AND user_id = ?',
            [$groupId, $userId]
        ) ?: null;
    }

    public function requestJoin(int $groupId, int $userId): void
    {
        $this->db->run(
            'INSERT IGNORE INTO {discussion_members} (group_id, user_id, status) VALUES (?, ?, \'pending\')',
            [$groupId, $userId]
        );
    }

    public function approveMember(int $groupId, int $userId): void
    {
        $this->db->run(
            'UPDATE {discussion_members} SET status = \'approved\', joined_at = NOW() WHERE group_id = ? AND user_id = ?',
            [$groupId, $userId]
        );
    }

    public function removeMember(int $groupId, int $userId): void
    {
        $this->db->run(
            'DELETE FROM {discussion_members} WHERE group_id = ? AND user_id = ?',
            [$groupId, $userId]
        );
    }

    /** @return list<array<string,mixed>> */
    public function members(int $groupId): array
    {
        return $this->db->all(
            'SELECT m.*, u.full_name, u.username
             FROM {discussion_members} m
             JOIN {users} u ON u.id = m.user_id
             WHERE m.group_id = ?
             ORDER BY m.status DESC, m.joined_at',
            [$groupId]
        );
    }

    public function pendingCount(int $groupId): int
    {
        return $this->db->int(
            'SELECT COUNT(*) FROM {discussion_members} WHERE group_id = ? AND status = \'pending\'',
            [$groupId]
        );
    }

    // ---- กระทู้ ----

    /** @return list<array<string,mixed>> */
    public function topics(int $groupId, int $offset = 0, int $limit = 20): array
    {
        return $this->db->all(
            'SELECT t.*, u.full_name AS author_name
             FROM {discussion_topics} t
             JOIN {users} u ON u.id = t.author_id
             WHERE t.group_id = ?
             ORDER BY t.updated_at DESC
             LIMIT ? OFFSET ?',
            [$groupId, $limit, $offset]
        );
    }

    public function topicCount(int $groupId): int
    {
        return $this->db->int(
            'SELECT COUNT(*) FROM {discussion_topics} WHERE group_id = ?',
            [$groupId]
        );
    }

    /** @return array<string,mixed>|null */
    public function findTopic(int $id): ?array
    {
        return $this->db->first(
            'SELECT t.*, u.full_name AS author_name
             FROM {discussion_topics} t
             JOIN {users} u ON u.id = t.author_id
             WHERE t.id = ?',
            [$id]
        ) ?: null;
    }

    public function createTopic(array $data): int
    {
        return $this->db->insert('discussion_topics', $data);
    }

    // ---- ความคิดเห็น ----

    /** @return list<array<string,mixed>> */
    public function replies(int $topicId): array
    {
        return $this->db->all(
            'SELECT r.*, u.full_name AS author_name
             FROM {discussion_replies} r
             JOIN {users} u ON u.id = r.author_id
             WHERE r.topic_id = ?
             ORDER BY r.created_at',
            [$topicId]
        );
    }

    public function createReply(array $data): int
    {
        $id = $this->db->insert('discussion_replies', $data);
        $this->db->run(
            'UPDATE {discussion_topics} SET reply_count = reply_count + 1, updated_at = NOW() WHERE id = ?',
            [$data['topic_id']]
        );

        return $id;
    }

    /** จำนวนคำขอสมาชิกที่รออนุมัติในกลุ่มที่ครูคนนี้สร้าง */
    public function pendingJoinCount(int $userId): int
    {
        return $this->db->int(
            'SELECT COUNT(*) FROM {discussion_members} m
             JOIN {discussion_groups} g ON g.id = m.group_id
             WHERE g.created_by = ? AND m.status = \'pending\'',
            [$userId]
        );
    }
}
