<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/smart_assignment.php';
require_once dirname(__DIR__) . '/integrations/google/google_service.php';

$tests = 0;
$failed = 0;
$check = static function (string $name, bool $condition) use (&$tests, &$failed): void {
    $tests++;
    if ($condition) {
        echo "PASS: {$name}\n";
    } else {
        $failed++;
        echo "FAIL: {$name}\n";
    }
};

$check('GitHub repository URL parses', sa_parse_github_repo('https://github.com/octocat/Hello-World') === 'octocat/Hello-World');
$check('GitHub .git suffix parses', sa_parse_github_repo('https://github.com/octocat/Hello-World.git') === 'octocat/Hello-World');
$check('Non-GitHub host rejected', sa_parse_github_repo('https://github.com.evil.test/octocat/repo') === null);
$check('GitHub URL with extra path rejected', sa_parse_github_repo('https://github.com/octocat/repo/tree/main') === null);
$check('Google Docs URL parses', fs_google_parse_document_id('https://docs.google.com/document/d/abcdefghijklmnopqrstuvwxyz123456/edit') === 'abcdefghijklmnopqrstuvwxyz123456');
$check('Non-Google document URL rejected', fs_google_parse_document_id('https://example.com/document/d/abcdefghijklmnopqrstuvwxyz123456') === null);
$check('RFC3339 Drive Activity time parses', fs_google_time_from_activity(['timestamp' => '2026-11-03T12:30:45Z']) === '2026-11-03 12:30:45');
$check('Drive Activity seconds time parses', fs_google_time_from_activity(['timestamp' => ['seconds' => '1793709045']]) === gmdate('Y-m-d H:i:s', 1793709045));
$check('Canonical JSON is deterministic for object key order', fs_google_canonical_json(['b' => 2, 'a' => 1]) === fs_google_canonical_json(['a' => 1, 'b' => 2]));
$activityA = [
    'actors' => [['user' => ['knownUser' => ['personName' => 'people/abc', 'isCurrentUser' => true]]]],
    'timestamp' => '2026-11-03T12:30:45Z',
    'actions' => [['detail' => ['edit' => (object) []]]],
];
$activityB = $activityA;
$activityB['timestamp'] = '2026-11-03T12:31:45Z';
$eventA = fs_google_event_external_id('task-1', 'member-1', 'file-1', $activityA, $activityA['actions'][0], 0);
$eventB = fs_google_event_external_id('task-1', 'member-1', 'file-1', $activityB, $activityB['actions'][0], 0);
$check('Repeated edit actions inherit parent timestamp in idempotency key', $eventA !== $eventB);
$check('Same DriveActivity payload produces stable event id', $eventA === fs_google_event_external_id('task-1', 'member-1', 'file-1', $activityA, $activityA['actions'][0], 0));

fwrite(STDOUT, "\n{$tests} test(s), {$failed} failure(s).\n");
exit($failed === 0 ? 0 : 1);
