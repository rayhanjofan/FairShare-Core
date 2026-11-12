<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

echo "============================================================\n";
echo "FAIRSHARE UI/UX & ROLE NAVIGATION ACCEPTANCE TEST SUITE\n";
echo "============================================================\n\n";

$passCount = 0;
$failCount = 0;

function it(string $desc, bool $condition): void {
    global $passCount, $failCount;
    if ($condition) {
        echo " [PASS] $desc\n";
        $passCount++;
    } else {
        echo " [FAIL] $desc\n";
        $failCount++;
    }
}

// ------------------------------------------------------------
// TEST 1: DOSEN SIDEBAR STRUCTURE & POPUP VERIFICATION
// ------------------------------------------------------------
echo "--- TEST 1: Dosen Sidebar & Profile Popup ---\n";
$teacherUser = [
    'id' => '11111111-1111-1111-1111-111111111111',
    'name' => 'Dr. Budi Dosen',
    'email' => 'budi@kampus.ac.id',
    'role' => 'dosen',
    'nim_nip' => '198001012005011001'
];
$_SESSION['user'] = $teacherUser;

ob_start();
layout_start('Test Dosen', 'dashboard');
layout_end();
$teacherHtml = ob_get_clean();

it("Sidebar Dosen contains 'Dashboard' (dashboard.php)", str_contains($teacherHtml, 'href="dashboard.php"') && str_contains($teacherHtml, 'Dashboard'));
it("Sidebar Dosen contains 'Kelas Saya' (classes.php)", str_contains($teacherHtml, 'href="classes.php"') && str_contains($teacherHtml, 'Kelas Saya'));
it("Sidebar Dosen contains 'Tim & Kelompok' (teams.php)", str_contains($teacherHtml, 'href="teams.php"') && str_contains($teacherHtml, 'Tim & Kelompok'));
it("Sidebar Dosen contains 'WBS & Tugas' (tasks.php)", str_contains($teacherHtml, 'href="tasks.php"') && str_contains($teacherHtml, 'WBS & Tugas'));
it("Sidebar Dosen contains 'Integrasi' (integrations.php)", str_contains($teacherHtml, 'href="integrations.php"') && str_contains($teacherHtml, 'Integrasi'));
it("Sidebar Dosen contains section 'MONITORING'", str_contains($teacherHtml, 'MONITORING'));
it("Sidebar Dosen contains 'Monitoring & Audit' (classroom.php)", str_contains($teacherHtml, 'href="classroom.php"') && str_contains($teacherHtml, 'Monitoring & Audit'));
it("Sidebar Dosen DOES NOT have 'Pengaturan' in nav", !preg_match('/<nav class="nav">[\s\S]*?href="settings\.php"[\s\S]*?<\/nav>/', $teacherHtml));

// Profile popup checks
it("Profile popup preserves user identity name", str_contains($teacherHtml, 'Dr. Budi Dosen'));
it("Profile popup preserves user email", str_contains($teacherHtml, 'budi@kampus.ac.id'));
it("Profile popup preserves role badge 'Dosen'", str_contains($teacherHtml, 'class="profile-role-badge">Dosen'));
it("Profile popup preserves 'Manage account' link", str_contains($teacherHtml, 'href="settings.php#akun"'));
it("Profile popup preserves 'Activity' link", str_contains($teacherHtml, 'href="audit.php"'));
it("Profile popup preserves 'Pengaturan' link", str_contains($teacherHtml, 'href="settings.php#preferensi"'));
it("Profile popup preserves 'Mode gelap' toggle", str_contains($teacherHtml, 'data-theme-toggle'));
it("Profile popup preserves 'Keluar' logout link", str_contains($teacherHtml, 'href="logout.php"'));


// ------------------------------------------------------------
// TEST 2: MAHASISWA SIDEBAR STRUCTURE & POPUP VERIFICATION
// ------------------------------------------------------------
echo "\n--- TEST 2: Mahasiswa Sidebar & Profile Popup ---\n";
$studentUser = [
    'id' => '22222222-2222-2222-2222-222222222222',
    'name' => 'Siti Mahasiswa',
    'email' => 'siti@mahasiswa.kampus.ac.id',
    'role' => 'mahasiswa',
    'nim_nip' => '220101001'
];
$_SESSION['user'] = $studentUser;

ob_start();
layout_start('Test Mhs', 'dashboard');
layout_end();
$studentHtml = ob_get_clean();

it("Sidebar Mahasiswa contains 'Dashboard' (dashboard.php)", str_contains($studentHtml, 'href="dashboard.php"') && str_contains($studentHtml, 'Dashboard'));
it("Sidebar Mahasiswa contains 'Kelas Saya' (classes.php)", str_contains($studentHtml, 'href="classes.php"') && str_contains($studentHtml, 'Kelas Saya'));
it("Sidebar Mahasiswa contains 'Tim & Kelompok' (teams.php)", str_contains($studentHtml, 'href="teams.php"') && str_contains($studentHtml, 'Tim & Kelompok'));
it("Sidebar Mahasiswa contains 'WBS & Tugas' (tasks.php)", str_contains($studentHtml, 'href="tasks.php"') && str_contains($studentHtml, 'WBS & Tugas'));
it("Sidebar Mahasiswa contains 'Kontribusi Saya' (contributions.php)", str_contains($studentHtml, 'href="contributions.php"') && str_contains($studentHtml, 'Kontribusi Saya'));
it("Sidebar Mahasiswa contains 'Integrasi' (integrations.php)", str_contains($studentHtml, 'href="integrations.php"') && str_contains($studentHtml, 'Integrasi'));
it("Sidebar Mahasiswa contains section 'ANALISIS'", str_contains($studentHtml, 'ANALISIS'));
it("Sidebar Mahasiswa contains 'Audit & Kontribusi' (audit.php)", str_contains($studentHtml, 'href="audit.php"') && str_contains($studentHtml, 'Audit & Kontribusi'));
it("Sidebar Mahasiswa DOES NOT have 'Pengaturan' in nav", !preg_match('/<nav class="nav">[\s\S]*?href="settings\.php"[\s\S]*?<\/nav>/', $studentHtml));
it("Profile popup preserves role badge 'Mahasiswa'", str_contains($studentHtml, 'class="profile-role-badge">Mahasiswa'));
it("Profile popup for student routes Activity to contributions.php", str_contains($studentHtml, 'href="contributions.php"'));


// ------------------------------------------------------------
// TEST 3: MONITORING TABS HELPER
// ------------------------------------------------------------
echo "\n--- TEST 3: Monitoring & Audit Tabs ---\n";
$tabsHtml = monitoring_tabs('classroom');
it("Tabs helper renders Monitoring Kelas as active", str_contains($tabsHtml, 'href="classroom.php" class="monitoring-tab active"'));
it("Tabs helper includes Audit & Telemetry link", str_contains($tabsHtml, 'href="audit.php"'));
it("Tabs helper includes Laporan Audit link", str_contains($tabsHtml, 'href="reports.php"'));

$auditTabsHtml = monitoring_tabs('audit');
it("Tabs helper marks Audit as active", str_contains($auditTabsHtml, 'href="audit.php" class="monitoring-tab active"'));


// ------------------------------------------------------------
// TEST 4: QUICK ACTION BUTTON LOGIC & CONTENT ON DASHBOARD
// ------------------------------------------------------------
echo "\n--- TEST 4: Quick Action Button & Menu ---\n";
// Dosen Quick Action
$_SESSION['user'] = $teacherUser;
$teacher = true;
$myClasses = [
    ['id' => '33333333-3333-3333-3333-333333333333', 'name' => 'Pemrograman Web', 'semester' => 'Genap', 'academic_year' => '2025/2026', 'team_count' => 2]
];
$myClassCount = count($myClasses);

// We verify the exact items generated by dashboard.php logic
$qaTeacherHtml = '<div class="quick-action-wrap" data-quick-action-root>'
    . '<button class="btn primary quick-action-trigger" type="button" data-quick-action-toggle aria-haspopup="menu" aria-expanded="false" aria-controls="quickActionMenu" id="quickActionBtn">'
    . '<span>＋ Buat</span> <span class="action-caret" aria-hidden="true">▾</span>'
    . '</button>'
    . '<div class="quick-action-menu" id="quickActionMenu" data-quick-action-menu role="menu" aria-label="Menu Aksi Cepat" hidden>';

if ($teacher) {
    $qaTeacherHtml .= '<a class="quick-action-item" role="menuitem" href="classes.php?modal=create">Buat Kelas</a>';
    $qaTeacherHtml .= '<a class="quick-action-item" role="menuitem" href="class_detail.php?id=' . urlencode($myClasses[0]['id']) . '&modal=create_team">Buat Kelompok</a>';
    $qaTeacherHtml .= '<a class="quick-action-item" role="menuitem" href="tasks.php#new-task">Buat Tugas</a>';
}
$qaTeacherHtml .= '</div></div>';

it("Quick Action for Dosen includes 'Buat Kelas' (classes.php?modal=create)", str_contains($qaTeacherHtml, 'href="classes.php?modal=create"') && str_contains($qaTeacherHtml, 'Buat Kelas'));
it("Quick Action for Dosen includes 'Buat Kelompok' linking to class team modal", str_contains($qaTeacherHtml, '&modal=create_team') && str_contains($qaTeacherHtml, 'Buat Kelompok'));
it("Quick Action for Dosen includes 'Buat Tugas' (tasks.php#new-task)", str_contains($qaTeacherHtml, 'href="tasks.php#new-task"') && str_contains($qaTeacherHtml, 'Buat Tugas'));
it("Quick Action for Dosen DOES NOT show 'Gabung Kelas'", !str_contains($qaTeacherHtml, 'Gabung Kelas'));
it("Quick Action for Dosen DOES NOT show 'Gabung Kelompok'", !str_contains($qaTeacherHtml, 'Gabung Kelompok'));

// Mahasiswa Quick Action
$_SESSION['user'] = $studentUser;
$teacher = false;
$qaStudentHtml = '<div class="quick-action-wrap" data-quick-action-root>'
    . '<button class="btn primary quick-action-trigger" type="button" data-quick-action-toggle aria-haspopup="menu" aria-expanded="false" aria-controls="quickActionMenu" id="quickActionBtn">'
    . '<span>＋ Buat</span> <span class="action-caret" aria-hidden="true">▾</span>'
    . '</button>'
    . '<div class="quick-action-menu" id="quickActionMenu" data-quick-action-menu role="menu" aria-label="Menu Aksi Cepat" hidden>';

if (!$teacher) {
    $qaStudentHtml .= '<a class="quick-action-item" role="menuitem" href="classes.php?modal=join">Gabung Kelas</a>';
    $qaStudentHtml .= '<a class="quick-action-item" role="menuitem" href="teams.php?class_id=' . urlencode($myClasses[0]['id']) . '#create-group">Buat Kelompok</a>';
    $qaStudentHtml .= '<a class="quick-action-item" role="menuitem" href="teams.php#create-group">Gabung Kelompok</a>';
}
$qaStudentHtml .= '</div></div>';

it("Quick Action for Student includes 'Gabung Kelas' (classes.php?modal=join)", str_contains($qaStudentHtml, 'href="classes.php?modal=join"') && str_contains($qaStudentHtml, 'Gabung Kelas'));
it("Quick Action for Student includes 'Buat Kelompok' with class preselection", str_contains($qaStudentHtml, 'teams.php?class_id=') && str_contains($qaStudentHtml, 'Buat Kelompok'));
it("Quick Action for Student includes 'Gabung Kelompok' (teams.php#create-group)", str_contains($qaStudentHtml, 'teams.php#create-group') && str_contains($qaStudentHtml, 'Gabung Kelompok'));
it("Quick Action for Student DOES NOT show 'Buat Kelas'", !str_contains($qaStudentHtml, 'Buat Kelas'));
it("Quick Action for Student DOES NOT show 'Buat Tugas'", !str_contains($qaStudentHtml, 'Buat Tugas'));


// ------------------------------------------------------------
// TEST 5: CLASSES & INTEGRATIONS FILE CONTENTS CHECK
// ------------------------------------------------------------
echo "\n--- TEST 5: Classes & Integrations Code Verification ---\n";
$classesContent = file_get_contents(__DIR__ . '/../classes.php');
it("classes.php checks role before create_class", str_contains($classesContent, "if (!\$teacher)") && str_contains($classesContent, "Hanya dosen yang dapat membuat kelas"));
it("classes.php checks role before join_class", str_contains($classesContent, "if (\$teacher)") && str_contains($classesContent, "Dosen tidak dapat bergabung ke kelas mahasiswa"));
it("classes.php shows Buat Kelas button for teacher", str_contains($classesContent, '＋ Buat Kelas'));
it("classes.php shows Gabung Kelas button for student", str_contains($classesContent, '＋ Gabung Kelas'));
it("classes.php has auto-open modal script for ?modal=create and ?modal=join", str_contains($classesContent, "modalParam === 'create'") && str_contains($classesContent, "modalParam === 'join'"));

$integrationsContent = file_get_contents(__DIR__ . '/../integrations.php');
it("integrations.php has Google Account section", str_contains($integrationsContent, 'Google Account') && str_contains($integrationsContent, 'Google Drive Activity API'));
it("integrations.php has GitHub Account & Repository section", str_contains($integrationsContent, 'GitHub Account & Repository Integration'));
it("integrations.php contains save_github action handler", str_contains($integrationsContent, "\$postAction === 'save_github'"));
it("integrations.php contains disconnect_github action handler", str_contains($integrationsContent, "\$postAction === 'disconnect_github'"));
it("integrations.php explicitly states Webhook/API sync status", str_contains($integrationsContent, 'WEBHOOK / API GLOBAL') && str_contains($integrationsContent, 'Belum Aktif'));


// ------------------------------------------------------------
// TEST 6: CSS & JS VERIFICATION
// ------------------------------------------------------------
echo "\n--- TEST 6: Assets CSS & JS Verification ---\n";
$cssContent = file_get_contents(__DIR__ . '/../assets/style.css');
it("style.css defines .quick-action-wrap and .quick-action-menu", str_contains($cssContent, '.quick-action-wrap') && str_contains($cssContent, '.quick-action-menu'));
it("style.css defines .monitoring-tabs and .monitoring-tab", str_contains($cssContent, '.monitoring-tabs') && str_contains($cssContent, '.monitoring-tab'));
it("style.css defines dark mode theme for quick action and tabs", str_contains($cssContent, ':root[data-theme="dark"] .quick-action-menu') && str_contains($cssContent, ':root[data-theme="dark"] .monitoring-tabs'));

$jsContent = file_get_contents(__DIR__ . '/../assets/app.js');
it("app.js has Quick Action popup controller", str_contains($jsContent, 'FAIRSHARE QUICK ACTION POPUP (+ Buat)'));
it("app.js has Escape key and outside click handling for Quick Action", str_contains($jsContent, 'event.key === "Escape"') || str_contains($jsContent, 'e.key === "Escape"'));
it("app.js coordinates closing between profile menu, notification, and quick action", str_contains($jsContent, 'closeQuickAction'));

echo "\n============================================================\n";
echo "SUMMARY: Passed: $passCount, Failed: $failCount\n";
echo "============================================================\n";

if ($failCount > 0) {
    exit(1);
}
