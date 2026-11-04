<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

function sa_task_by_id(string $taskId): ?array
{
    if (!valid_uuid($taskId)) {
        return null;
    }
    $stmt = require_db()->prepare("SELECT t.id::text AS id, t.group_id::text AS group_id, t.assignment_batch_id::text AS assignment_batch_id, t.assigned_user_id::text AS assigned_user_id, t.title, t.description, t.weight, t.status, t.target_week, t.due_at::text AS due_at, t.completed_at::text AS completed_at, t.task_type, t.resource_url, t.external_resource_id, t.resource_title, t.sync_status, t.last_synced_at::text AS last_synced_at, t.sync_error, t.created_at::text AS created_at, t.updated_at::text AS updated_at, team.name AS group_name, team.created_by::text AS team_created_by, team.parent_group_id::text AS class_id, class.name AS class_name, class.created_by::text AS class_owner_id FROM public.tasks t JOIN public.groups team ON team.id = t.group_id AND team.group_type = 'team' JOIN public.groups class ON class.id = team.parent_group_id AND class.group_type = 'class' WHERE t.id = CAST(:task_id AS UUID) LIMIT 1");
    $stmt->execute(['task_id' => $taskId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function sa_can_access_task(array $task, array $user): bool
{
    if (is_teacher($user)) {
        return strtolower((string) $task['class_owner_id']) === strtolower((string) $user['id']);
    }
    $stmt = require_db()->prepare("SELECT 1 FROM public.group_members WHERE group_id = CAST(:group_id AS UUID) AND user_id = CAST(:user_id AS UUID) LIMIT 1");
    $stmt->execute(['group_id' => $task['group_id'], 'user_id' => $user['id']]);
    return $stmt->fetchColumn() !== false;
}

function sa_can_manage_task_resource(array $task, array $user): bool
{
    if (is_teacher($user)) {
        return strtolower((string) $task['class_owner_id']) === strtolower((string) $user['id']);
    }
    $group = group_by_id((string) $task['group_id']);
    return $group !== null && can_manage_group($group, $user);
}

function sa_parse_github_repo(string $url): ?string
{
    $parts = parse_url(trim($url));
    if (!$parts || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
        || strtolower((string) ($parts['host'] ?? '')) !== 'github.com') {
        return null;
    }
    $path = trim((string) ($parts['path'] ?? ''), '/');
    $segments = explode('/', $path);
    if (count($segments) !== 2) {
        return null;
    }
    [$owner, $repo] = $segments;
    $repo = preg_replace('/\.git$/i', '', $repo) ?? $repo;
    if (!preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9-]{0,38})$/', $owner)
        || !preg_match('/^[A-Za-z0-9_.-]{1,100}$/', $repo)) {
        return null;
    }
    return $owner . '/' . $repo;
}

function sa_task_activities(string $taskId, int $limit = 80): array
{
    $stmt = require_db()->prepare("SELECT tl.id::text AS id, tl.user_id::text AS user_id, tl.source, tl.external_id, tl.activity_timestamp::text AS activity_timestamp, tl.raw_delta, tl.filtered_delta, tl.metadata, u.name AS user_name FROM public.telemetry_logs tl LEFT JOIN public.users u ON u.id = tl.user_id WHERE tl.task_id = CAST(:task_id AS UUID) ORDER BY tl.activity_timestamp DESC LIMIT " . max(1, min(200, $limit)));
    $stmt->execute(['task_id' => $taskId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$row) {
        $metadata = $row['metadata'];
        if (is_string($metadata)) {
            $metadata = json_decode($metadata, true);
        }
        $row['metadata'] = is_array($metadata) ? $metadata : [];
    }
    unset($row);
    return $rows;
}
