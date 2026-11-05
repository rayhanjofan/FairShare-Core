<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$user = require_login();
$teacher = is_teacher($user);
$error = '';
$classId = trim((string) ($_GET['id'] ?? ''));

if (!valid_uuid($classId)) {
    flash('ID kelas tidak valid.', 'error');
    redirect('classes.php');
}

$class = class_by_id($classId);
if (!$class) {
    flash('Kelas tidak ditemukan.', 'error');
    redirect('classes.php');
}

$pdo = require_db();

// VERIFIKASI AKSES OTORISASI
if ($teacher) {
    // Dosen hanya boleh mengelola kelas miliknya
    if (strtolower((string) $class['created_by']) !== strtolower((string) $user['id'])) {
        http_response_code(403);
        layout_start('Akses Ditolak', 'classes');
        page_heading('403 FORBIDDEN', 'Akses Ditolak', 'Kamu tidak memiliki hak akses untuk mengelola kelas ini.');
        echo '<div class="panel"><p>Kelas ini milik dosen lain. Kamu hanya diizinkan mengelola kelas yang kamu buat sendiri.</p><a class="btn primary" href="classes.php">Kembali ke Kelas Saya</a></div>';
        layout_end();
        exit;
    }
} else {
    // Mahasiswa harus sudah menjadi anggota kelas
    $checkStudent = $pdo->prepare("
        SELECT 1 FROM public.group_members
        WHERE group_id = CAST(:gid AS uuid) AND user_id = CAST(:uid AS uuid)
        LIMIT 1
    ");
    $checkStudent->execute(['gid' => $classId, 'uid' => (string) $user['id']]);
    if (!$checkStudent->fetch()) {
        http_response_code(403);
        layout_start('Akses Ditolak', 'classes');
        page_heading('403 FORBIDDEN', 'Akses Ditolak', 'Kamu belum terdaftar di kelas ini.');
        echo '<div class="panel"><p>Silakan bergabung ke kelas ini terlebih dahulu menggunakan kode undangan dari dosen pengampu.</p><a class="btn primary" href="classes.php">Kembali ke Kelas Saya</a></div>';
        layout_end();
        exit;
    }
}

// PROSES POST ACTIONS
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'create_team') {
            // Bisa dilakukan oleh Dosen pemilik kelas ATAU Mahasiswa yang terdaftar di kelas
            $teamName = trim((string) ($_POST['name'] ?? ''));
            $teamDesc = trim((string) ($_POST['description'] ?? ''));
            $pmUserId = trim((string) ($_POST['pm_user_id'] ?? ''));

            if ($teamName === '') {
                $error = 'Nama kelompok wajib diisi.';
            } elseif (mb_strlen($teamName) > 150) {
                $error = 'Nama kelompok maksimal 150 karakter.';
            } else {
                // Jika mahasiswa yang membuat, pastikan pembuat belum punya kelompok di kelas ini
                if (!$teacher) {
                    $existingTeamCheck = $pdo->prepare("
                        SELECT tm.name FROM public.group_members gm
                        JOIN public.groups tm ON tm.id = gm.group_id
                        WHERE tm.parent_group_id = CAST(:class_id AS uuid)
                          AND tm.group_type = 'team'
                          AND gm.user_id = CAST(:uid AS uuid)
                        LIMIT 1
                    ");
                    $existingTeamCheck->execute(['class_id' => $classId, 'uid' => (string) $user['id']]);
                    $alreadyInTeam = $existingTeamCheck->fetch(PDO::FETCH_ASSOC);
                    if ($alreadyInTeam) {
                        throw new RuntimeException("Kamu sudah terdaftar di kelompok '{$alreadyInTeam['name']}' pada kelas ini. Satu mahasiswa hanya boleh memiliki satu kelompok per kelas.");
                    }
                }

                // Tentukan Project Manager (PM)
                if ($pmUserId === '' || !valid_uuid($pmUserId)) {
                    if (!$teacher) {
                        // Mahasiswa defaultnya diri sendiri jika tidak memilih
                        $pmUserId = (string) $user['id'];
                    } else {
                        throw new RuntimeException('Dosen wajib memilih salah satu mahasiswa sebagai Project Manager kelompok.');
                    }
                }

                // Validasi bahwa calon PM benar-benar anggota kelas
                $pmCheck = $pdo->prepare("
                    SELECT u.id, u.name FROM public.group_members gm
                    JOIN public.users u ON u.id = gm.user_id
                    WHERE gm.group_id = CAST(:class_id AS uuid) AND gm.user_id = CAST(:pm_id AS uuid)
                    LIMIT 1
                ");
                $pmCheck->execute(['class_id' => $classId, 'pm_id' => $pmUserId]);
                $pmUser = $pmCheck->fetch(PDO::FETCH_ASSOC);
                if (!$pmUser) {
                    throw new RuntimeException('Project Manager yang dipilih belum terdaftar sebagai anggota di kelas ini.');
                }

                // Periksa apakah PM sudah memiliki kelompok lain di kelas ini
                $pmTeamCheck = $pdo->prepare("
                    SELECT tm.name FROM public.group_members gm
                    JOIN public.groups tm ON tm.id = gm.group_id
                    WHERE tm.parent_group_id = CAST(:class_id AS uuid)
                      AND tm.group_type = 'team'
                      AND gm.user_id = CAST(:pm_id AS uuid)
                    LIMIT 1
                ");
                $pmTeamCheck->execute(['class_id' => $classId, 'pm_id' => $pmUserId]);
                $pmHasTeam = $pmTeamCheck->fetch(PDO::FETCH_ASSOC);
                if ($pmHasTeam) {
                    throw new RuntimeException("Mahasiswa {$pmUser['name']} sudah terdaftar di kelompok '{$pmHasTeam['name']}' pada kelas ini.");
                }

                // Generate kode undangan kelompok yang unik
                $prefix = 'TIM';
                $words = preg_split('/\s+/', $teamName) ?: [];
                if (count($words) >= 2) {
                    $prefix = strtoupper(mb_substr($words[0], 0, 2) . mb_substr($words[1], 0, 2));
                } else {
                    $prefix = strtoupper(mb_substr($teamName, 0, 3));
                }
                $teamInviteCode = generate_unique_invite_code($pdo, $prefix ?: 'TIM');

                // Eksekusi transaksi
                $pdo->beginTransaction();

                $stmt = $pdo->prepare("
                    INSERT INTO public.groups (
                        id,
                        created_by,
                        name,
                        class_name,
                        duration_weeks,
                        github_repo_url,
                        gdocs_file_id,
                        created_at,
                        updated_at,
                        group_type,
                        parent_group_id,
                        invite_code,
                        description,
                        semester,
                        academic_year
                    ) VALUES (
                        gen_random_uuid(),
                        CAST(:creator AS uuid),
                        :name,
                        :class_name,
                        :duration_weeks,
                        NULL,
                        NULL,
                        CURRENT_TIMESTAMP,
                        CURRENT_TIMESTAMP,
                        'team',
                        CAST(:parent_id AS uuid),
                        :invite_code,
                        :description,
                        :semester,
                        :academic_year
                    )
                    RETURNING id::text
                ");

                $stmt->execute([
                    'creator'        => (string) $user['id'],
                    'name'           => $teamName,
                    'class_name'     => $class['name'],
                    'duration_weeks' => (int) ($class['duration_weeks'] ?? 16),
                    'parent_id'      => $classId,
                    'invite_code'    => $teamInviteCode,
                    'description'    => $teamDesc !== '' ? $teamDesc : null,
                    'semester'       => $class['semester'] ?? null,
                    'academic_year'  => $class['academic_year'] ?? null,
                ]);

                $newTeamId = (string) $stmt->fetchColumn();

                // Masukkan Project Manager ke group_members dengan role 'ketua'
                $addPm = $pdo->prepare("
                    INSERT INTO public.group_members (
                        id,
                        group_id,
                        user_id,
                        role_in_group,
                        peer_approval,
                        joined_at
                    ) VALUES (
                        gen_random_uuid(),
                        CAST(:gid AS uuid),
                        CAST(:uid AS uuid),
                        'ketua',
                        FALSE,
                        CURRENT_TIMESTAMP
                    )
                ");
                $addPm->execute(['gid' => $newTeamId, 'uid' => $pmUserId]);

                // Jika pembuat adalah mahasiswa dan bukan PM yang dipilih, masukkan pembuat sebagai anggota
                if (!$teacher && strtolower($pmUserId) !== strtolower((string) $user['id'])) {
                    $addCreator = $pdo->prepare("
                        INSERT INTO public.group_members (
                            id,
                            group_id,
                            user_id,
                            role_in_group,
                            peer_approval,
                            joined_at
                        ) VALUES (
                            gen_random_uuid(),
                            CAST(:gid AS uuid),
                            CAST(:uid AS uuid),
                            'anggota',
                            FALSE,
                            CURRENT_TIMESTAMP
                        )
                    ");
                    $addCreator->execute(['gid' => $newTeamId, 'uid' => (string) $user['id']]);
                }

                // Jika Dosen membuat dan memilih anggota awal tambahan
                if ($teacher && !empty($_POST['initial_members']) && is_array($_POST['initial_members'])) {
                    $addMember = $pdo->prepare("
                        INSERT INTO public.group_members (
                            id,
                            group_id,
                            user_id,
                            role_in_group,
                            peer_approval,
                            joined_at
                        ) VALUES (
                            gen_random_uuid(),
                            CAST(:gid AS uuid),
                            CAST(:uid AS uuid),
                            'anggota',
                            FALSE,
                            CURRENT_TIMESTAMP
                        )
                    ");
                    foreach ($_POST['initial_members'] as $initUid) {
                        $initUid = trim((string) $initUid);
                        if (!valid_uuid($initUid) || strtolower($initUid) === strtolower($pmUserId)) {
                            continue;
                        }
                        // Pastikan terdaftar di kelas
                        $memCheck = $pdo->prepare("
                            SELECT 1 FROM public.group_members
                            WHERE group_id = CAST(:class_id AS uuid) AND user_id = CAST(:uid AS uuid)
                            LIMIT 1
                        ");
                        $memCheck->execute(['class_id' => $classId, 'uid' => $initUid]);
                        if ($memCheck->fetch()) {
                            $addMember->execute(['gid' => $newTeamId, 'uid' => $initUid]);
                        }
                    }
                }

                $pdo->commit();

                log_activity('Kelompok kelas dibuat', "Kelompok $teamName di kelas {$class['name']}", 'success');
                flash("Kelompok <b>" . e($teamName) . "</b> berhasil dibentuk! Kode undangan kelompok: <b>" . e($teamInviteCode) . "</b>");
                redirect("class_detail.php?id=" . urlencode($classId) . "#teams");
            }
        } elseif ($action === 'change_pm') {
            // Hanya Dosen pengampu yang dapat mengganti Project Manager kelompok
            if (!$teacher) {
                http_response_code(403);
                exit('Hanya dosen pengampu yang berwenang mengganti Project Manager.');
            }

            $targetTeamId = trim((string) ($_POST['team_id'] ?? ''));
            $newPmId = trim((string) ($_POST['new_pm_id'] ?? ''));

            if (!valid_uuid($targetTeamId) || !valid_uuid($newPmId)) {
                throw new RuntimeException('Parameter penggantian Project Manager tidak valid.');
            }

            // Pastikan kelompok berada di kelas ini
            $teamCheck = $pdo->prepare("
                SELECT id, name FROM public.groups
                WHERE id = CAST(:tid AS uuid) AND parent_group_id = CAST(:cid AS uuid) AND group_type = 'team'
                LIMIT 1
            ");
            $teamCheck->execute(['tid' => $targetTeamId, 'cid' => $classId]);
            $team = $teamCheck->fetch(PDO::FETCH_ASSOC);
            if (!$team) {
                throw new RuntimeException('Kelompok tidak ditemukan di kelas ini.');
            }

            // Pastikan calon PM baru adalah anggota kelompok ini
            $memCheck = $pdo->prepare("
                SELECT u.name FROM public.group_members gm
                JOIN public.users u ON u.id = gm.user_id
                WHERE gm.group_id = CAST(:tid AS uuid) AND gm.user_id = CAST(:uid AS uuid)
                LIMIT 1
            ");
            $memCheck->execute(['tid' => $targetTeamId, 'uid' => $newPmId]);
            $candidate = $memCheck->fetch(PDO::FETCH_ASSOC);
            if (!$candidate) {
                throw new RuntimeException('Mahasiswa yang dipilih belum menjadi anggota kelompok ini.');
            }

            // ATOMIC TRANSACTION: Ketua lama -> anggota, Ketua baru -> ketua
            $pdo->beginTransaction();

            $demoteOld = $pdo->prepare("
                UPDATE public.group_members
                SET role_in_group = 'anggota'
                WHERE group_id = CAST(:tid AS uuid) AND role_in_group = 'ketua'
            ");
            $demoteOld->execute(['tid' => $targetTeamId]);

            $promoteNew = $pdo->prepare("
                UPDATE public.group_members
                SET role_in_group = 'ketua'
                WHERE group_id = CAST(:tid AS uuid) AND user_id = CAST(:uid AS uuid)
            ");
            $promoteNew->execute(['tid' => $targetTeamId, 'uid' => $newPmId]);

            $pdo->commit();

            log_activity('Project Manager diganti', "PM {$team['name']} diganti menjadi {$candidate['name']}", 'success');
            flash("Project Manager kelompok <b>" . e($team['name']) . "</b> berhasil dialihkan ke <b>" . e($candidate['name']) . "</b>.");
            redirect("class_detail.php?id=" . urlencode($classId) . "#teams");
        } elseif ($action === 'add_team_member') {
            // Dosen menambahkan mahasiswa ke dalam kelompok
            if (!$teacher) {
                http_response_code(403);
                exit('Hanya dosen yang dapat menambahkan anggota kelompok secara langsung.');
            }

            $targetTeamId = trim((string) ($_POST['team_id'] ?? ''));
            $memberUid = trim((string) ($_POST['user_id'] ?? ''));

            if (!valid_uuid($targetTeamId) || !valid_uuid($memberUid)) {
                throw new RuntimeException('ID kelompok atau mahasiswa tidak valid.');
            }

            // Pastikan kelompok ada di kelas ini
            $teamCheck = $pdo->prepare("
                SELECT id, name FROM public.groups
                WHERE id = CAST(:tid AS uuid) AND parent_group_id = CAST(:cid AS uuid) AND group_type = 'team'
                LIMIT 1
            ");
            $teamCheck->execute(['tid' => $targetTeamId, 'cid' => $classId]);
            $team = $teamCheck->fetch(PDO::FETCH_ASSOC);
            if (!$team) {
                throw new RuntimeException('Kelompok tidak ditemukan di kelas ini.');
            }

            // Pastikan mahasiswa terdaftar di kelas ini
            $classMemCheck = $pdo->prepare("
                SELECT u.name FROM public.group_members gm
                JOIN public.users u ON u.id = gm.user_id
                WHERE gm.group_id = CAST(:cid AS uuid) AND gm.user_id = CAST(:uid AS uuid)
                LIMIT 1
            ");
            $classMemCheck->execute(['cid' => $classId, 'uid' => $memberUid]);
            $student = $classMemCheck->fetch(PDO::FETCH_ASSOC);
            if (!$student) {
                throw new RuntimeException('Mahasiswa belum terdaftar sebagai anggota di kelas ini.');
            }

            // Masukkan mahasiswa ke kelompok (trigger database akan memverifikasi constraint 1 tim per kelas)
            $addMember = $pdo->prepare("
                INSERT INTO public.group_members (
                    id,
                    group_id,
                    user_id,
                    role_in_group,
                    peer_approval,
                    joined_at
                ) VALUES (
                    gen_random_uuid(),
                    CAST(:gid AS uuid),
                    CAST(:uid AS uuid),
                    'anggota',
                    FALSE,
                    CURRENT_TIMESTAMP
                )
            ");
            $addMember->execute(['gid' => $targetTeamId, 'uid' => $memberUid]);

            log_activity('Anggota kelompok ditambahkan', "{$student['name']} ditambahkan ke {$team['name']}", 'success');
            flash("<b>" . e($student['name']) . "</b> berhasil ditambahkan ke kelompok <b>" . e($team['name']) . "</b>.");
            redirect("class_detail.php?id=" . urlencode($classId) . "#teams");
        } elseif ($action === 'remove_team_member') {
            // Dosen mengeluarkan mahasiswa dari kelompok
            if (!$teacher) {
                http_response_code(403);
                exit('Hanya dosen yang dapat mengeluarkan anggota kelompok.');
            }

            $targetTeamId = trim((string) ($_POST['team_id'] ?? ''));
            $memberUid = trim((string) ($_POST['user_id'] ?? ''));

            if (!valid_uuid($targetTeamId) || !valid_uuid($memberUid)) {
                throw new RuntimeException('Parameter tidak valid.');
            }

            // Periksa role anggota yang akan dihapus
            $checkRole = $pdo->prepare("
                SELECT gm.role_in_group, u.name, tm.name AS team_name
                FROM public.group_members gm
                JOIN public.users u ON u.id = gm.user_id
                JOIN public.groups tm ON tm.id = gm.group_id
                WHERE gm.group_id = CAST(:tid AS uuid) AND gm.user_id = CAST(:uid AS uuid)
                LIMIT 1
            ");
            $checkRole->execute(['tid' => $targetTeamId, 'uid' => $memberUid]);
            $member = $checkRole->fetch(PDO::FETCH_ASSOC);

            if (!$member) {
                throw new RuntimeException('Anggota tidak ditemukan dalam kelompok tersebut.');
            }

            if ($member['role_in_group'] === 'ketua') {
                // Periksa apakah masih ada anggota lain
                $otherCount = (int) $pdo->query("
                    SELECT COUNT(*) FROM public.group_members
                    WHERE group_id = CAST('$targetTeamId' AS uuid) AND user_id <> CAST('$memberUid' AS uuid)
                ")->fetchColumn();
                if ($otherCount > 0) {
                    throw new RuntimeException('Tidak dapat menghapus Project Manager aktif saat kelompok masih memiliki anggota lain. Silakan alihkan Project Manager terlebih dahulu.');
                }
            }

            $del = $pdo->prepare("
                DELETE FROM public.group_members
                WHERE group_id = CAST(:tid AS uuid) AND user_id = CAST(:uid AS uuid)
            ");
            $del->execute(['tid' => $targetTeamId, 'uid' => $memberUid]);

            log_activity('Anggota dikeluarkan', "{$member['name']} dikeluarkan dari {$member['team_name']}", 'warning');
            flash("<b>" . e($member['name']) . "</b> telah dikeluarkan dari kelompok <b>" . e($member['team_name']) . "</b>.");
            redirect("class_detail.php?id=" . urlencode($classId) . "#teams");
        } elseif ($action === 'create_task') {
            // Dosen membuat tugas untuk kelompok di kelas
            if (!$teacher) {
                http_response_code(403);
                exit('Hanya dosen pengampu yang dapat membuat tugas kelas.');
            }

            $title = trim((string) ($_POST['title'] ?? ''));
            $desc = trim((string) ($_POST['description'] ?? ''));
            $weight = max(1, min(5, (int) ($_POST['weight'] ?? 1)));
            $targetWeek = max(1, (int) ($_POST['target_week'] ?? 1));
            $targetScope = (string) ($_POST['target_scope'] ?? 'all');
            $selectedTeamId = trim((string) ($_POST['selected_team_id'] ?? ''));

            if ($title === '') {
                $error = 'Judul tugas wajib diisi.';
            } elseif (mb_strlen($title) > 200) {
                $error = 'Judul tugas maksimal 200 karakter.';
            } else {
                $recipientTeamIds = [];

                if ($targetScope === 'all') {
                    // Semua kelompok di kelas ini
                    $allTeams = class_teams($classId);
                    $recipientTeamIds = array_column($allTeams, 'id');
                    if (empty($recipientTeamIds)) {
                        throw new RuntimeException('Belum ada kelompok yang terbentuk di kelas ini. Bentuk kelompok terlebih dahulu sebelum membagikan tugas ke semua kelompok.');
                    }
                } else {
                    // Kelompok tertentu yang dipilih
                    if (!valid_uuid($selectedTeamId)) {
                        throw new RuntimeException('Kelompok penerima tugas tidak valid.');
                    }
                    $checkTeam = $pdo->prepare("
                        SELECT 1 FROM public.groups
                        WHERE id = CAST(:tid AS uuid) AND parent_group_id = CAST(:cid AS uuid) AND group_type = 'team'
                        LIMIT 1
                    ");
                    $checkTeam->execute(['tid' => $selectedTeamId, 'cid' => $classId]);
                    if (!$checkTeam->fetch()) {
                        throw new RuntimeException('Kelompok yang dipilih tidak terdaftar di kelas ini.');
                    }
                    $recipientTeamIds = [$selectedTeamId];
                }

                // Masukkan tugas terpisah untuk setiap kelompok penerima agar progres mandiri
                $pdo->beginTransaction();
                $insertTask = $pdo->prepare("
                    INSERT INTO public.tasks (
                        id,
                        group_id,
                        assigned_user_id,
                        title,
                        description,
                        weight,
                        status,
                        target_week,
                        completed_at,
                        created_at,
                        updated_at
                    ) VALUES (
                        gen_random_uuid(),
                        CAST(:gid AS uuid),
                        NULL,
                        :title,
                        :description,
                        :weight,
                        'todo',
                        :target_week,
                        NULL,
                        CURRENT_TIMESTAMP,
                        CURRENT_TIMESTAMP
                    )
                ");

                foreach ($recipientTeamIds as $tid) {
                    $insertTask->execute([
                        'gid'         => $tid,
                        'title'       => $title,
                        'description' => $desc !== '' ? $desc : null,
                        'weight'      => $weight,
                        'target_week' => $targetWeek,
                    ]);
                }

                $pdo->commit();

                $countRecipients = count($recipientTeamIds);
                log_activity('Tugas kelas dibuat', "$title untuk $countRecipients kelompok di {$class['name']}", 'success');
                flash("Tugas <b>" . e($title) . "</b> berhasil dibagikan ke <b>$countRecipients</b> kelompok.");
                redirect("class_detail.php?id=" . urlencode($classId) . "#tasks");
            }
        } elseif ($action === 'update_task_status') {
            // Mahasiswa mengubah status tugas kelompoknya
            $taskId = trim((string) ($_POST['task_id'] ?? ''));
            $newStatus = db_task_status((string) ($_POST['status'] ?? 'todo'));

            if (!valid_uuid($taskId)) {
                throw new RuntimeException('ID tugas tidak valid.');
            }

            // Pastikan tugas ini milik kelompok di mana mahasiswa terdaftar
            $taskCheck = $pdo->prepare("
                SELECT t.id, t.title, t.group_id
                FROM public.tasks t
                JOIN public.groups g ON g.id = t.group_id
                JOIN public.group_members gm ON gm.group_id = g.id
                WHERE t.id = CAST(:tid AS uuid)
                  AND g.parent_group_id = CAST(:cid AS uuid)
                  AND gm.user_id = CAST(:uid AS uuid)
                LIMIT 1
            ");
            $taskCheck->execute(['tid' => $taskId, 'cid' => $classId, 'uid' => (string) $user['id']]);
            $task = $taskCheck->fetch(PDO::FETCH_ASSOC);

            if (!$task && !$teacher) {
                throw new RuntimeException('Kamu tidak memiliki akses untuk mengubah tugas ini.');
            }

            $update = $pdo->prepare("
                UPDATE public.tasks
                SET status = :status,
                    completed_at = CASE WHEN :is_done = 'done' THEN COALESCE(completed_at, CURRENT_TIMESTAMP) ELSE NULL END,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = CAST(:tid AS uuid)
            ");
            $update->execute([
                'status'  => $newStatus,
                'is_done' => $newStatus,
                'tid'     => $taskId,
            ]);

            log_activity('Status tugas diubah', "Status tugas diubah menjadi " . ui_task_status($newStatus), 'success');
            flash('Status tugas berhasil diperbarui.');
            redirect("class_detail.php?id=" . urlencode($classId) . "#tasks");
        }
    } catch (PDOException $e) {
        error_log('FairShare class_detail SQLSTATE ' . $e->getCode() . ': ' . $e->getMessage());
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = 'Operasi database gagal: ' . $e->getMessage();
    } catch (Throwable $e) {
        error_log('FairShare class_detail error: ' . $e->getMessage());
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e->getMessage();
    }
}

// AMBIL DATA KELAS TERBARU
$students = class_members($classId);
$teams = class_teams($classId);
$tasks = class_tasks($classId);

// Hitung metrik
$totalStudents = count($students);
$totalTeams = count($teams);
$totalTasks = count($tasks);
$completedTasks = count(array_filter($tasks, fn($t) => ($t['status_db'] ?? '') === 'done'));
$inProgressTasks = count(array_filter($tasks, fn($t) => ($t['status_db'] ?? '') === 'in_progress'));
$todoTasks = count(array_filter($tasks, fn($t) => ($t['status_db'] ?? '') === 'todo'));
$reviewTasks = count(array_filter($tasks, fn($t) => ($t['status_db'] ?? '') === 'review'));
$completionRate = $totalTasks > 0 ? round(($completedTasks / $totalTasks) * 100) : 0;

// Mahasiswa yang belum punya kelompok di kelas ini
$unassignedStudents = array_values(array_filter($students, fn($s) => empty($s['team_id'])));

// Mahasiswa saat ini sudah punya kelompok di kelas ini?
$myTeamInClass = null;
if (!$teacher) {
    foreach ($teams as $tm) {
        if (in_array(strtolower((string) $user['id']), array_map('strtolower', $tm['member_ids'] ?? []), true)) {
            $myTeamInClass = $tm;
            break;
        }
    }
}

layout_start($class['name'] . ' · Kelas', 'classes');
?>

<div class="class-hero-banner">
    <div class="class-hero-content">
        <div class="class-hero-badge">
            <span class="pill purple"><?= e($class['semester'] ?? 'Semester') ?></span>
            <span class="pill gray"><?= e($class['academic_year'] ?? '2025/2026') ?></span>
            <span class="pill blue-pill"><?= (int) ($class['duration_weeks'] ?? 16) ?> Minggu</span>
        </div>
        <h1 class="class-hero-title"><?= e($class['name']) ?></h1>
        <p class="class-hero-desc"><?= e($class['description'] ?: 'Tidak ada deskripsi tambahan untuk kelas ini.') ?></p>
        <div class="class-hero-meta">
            <span>Dosen Pengampu: <b><?= e($class['lecturer_name'] ?? 'Dosen') ?></b></span>
            <span>·</span>
            <span>Dibuat: <b><?= e(format_date($class['created_at'])) ?></b></span>
        </div>
    </div>
    <div class="class-hero-side">
        <div class="class-code-card">
            <div class="class-code-label">KODE UNDANGAN KELAS</div>
            <div class="class-code-value">
                <code><?= e($class['invite_code']) ?></code>
                <button
                    class="btn secondary small"
                    type="button"
                    data-copy="<?= e($class['invite_code']) ?>"
                    data-copy-message="Kode kelas <?= e($class['invite_code']) ?> disalin!"
                    title="Salin kode kelas">
                    ⧉ Salin
                </button>
            </div>
            <small class="muted">Bagikan kode ini kepada mahasiswa untuk bergabung ke kelas.</small>
        </div>
    </div>
</div>

<?php if ($error): ?>
    <div class="flash error"><?= e($error) ?></div>
<?php endif; ?>

<!-- STATISTIK KELAS -->
<div class="stats-grid">
    <article class="stat-card">
        <div class="stat-label">TOTAL MAHASISWA</div>
        <div class="stat-number"><?= $totalStudents ?></div>
        <div class="stat-foot"><?= count($unassignedStudents) ?> belum memiliki kelompok</div>
    </article>
    <article class="stat-card">
        <div class="stat-label">TOTAL KELOMPOK</div>
        <div class="stat-number"><?= $totalTeams ?></div>
        <div class="stat-foot">Kelompok aktif terbentuk</div>
    </article>
    <article class="stat-card">
        <div class="stat-label">TOTAL TUGAS WBS</div>
        <div class="stat-number"><?= $totalTasks ?></div>
        <div class="stat-foot"><?= $completedTasks ?> selesai (<?= $completionRate ?>%)</div>
    </article>
    <article class="stat-card">
        <div class="stat-label">STATUS PENYELESAIAN</div>
        <div class="stat-number"><?= $completionRate ?><small>%</small></div>
        <div class="mini-progress green-progress"><span style="width:<?= $completionRate ?>%"></span></div>
    </article>
</div>

<!-- NAVIGASI TAB KELAS -->
<div class="class-tabs" id="classTabs">
    <button class="class-tab-btn active" type="button" data-tab-target="tabTeams">
        ♧ Kelompok & Tim (<?= $totalTeams ?>)
    </button>
    <button class="class-tab-btn" type="button" data-tab-target="tabStudents">
        👥 Mahasiswa (<?= $totalStudents ?>)
    </button>
    <button class="class-tab-btn" type="button" data-tab-target="tabTasks">
        ☷ Tugas & WBS (<?= $totalTasks ?>)
    </button>
    <?php if ($teacher): ?>
    <button class="class-tab-btn" type="button" data-tab-target="tabMonitoring">
        ◈ Monitoring Kelas
    </button>
    <?php endif; ?>
</div>

<!-- TAB 1: KELOMPOK -->
<section class="class-tab-pane active" id="tabTeams">
    <div class="panel">
        <div class="panel-heading">
            <div>
                <div class="panel-title">Kelompok dalam Kelas</div>
                <div class="panel-sub">Setiap kelompok memiliki satu Project Manager dan anggota mahasiswa terdaftar.</div>
            </div>
            <div class="panel-actions">
                <?php if ($teacher || (!$teacher && !$myTeamInClass)): ?>
                    <button class="btn primary" type="button" data-modal-open="createTeamModal">
                        ＋ Bentuk Kelompok
                    </button>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!$teams): ?>
            <div class="empty-state">
                <span class="empty-illustration">♧</span>
                <b>Belum ada kelompok yang terbentuk</b>
                <p>
                    <?= $teacher
                        ? 'Bentuk kelompok untuk mahasiswa di kelas ini menggunakan tombol "+ Bentuk Kelompok".'
                        : 'Bentuk kelompok baru atau gabung ke kelompok teman menggunakan kode undangan kelompok.'
                    ?>
                </p>
            </div>
        <?php else: ?>
            <div class="teams-container">
                <?php foreach ($teams as $t): ?>
                    <?php
                        $tMembers = $t['members'] ?? [];
                        $tTasks = array_values(array_filter($tasks, fn($tk) => ($tk['group_id'] ?? '') === $t['id']));
                        $tDone = count(array_filter($tTasks, fn($tk) => ($tk['status_db'] ?? '') === 'done'));
                        $tRate = count($tTasks) > 0 ? round(($tDone / count($tTasks)) * 100) : 0;
                        $pm = $t['project_manager'] ?? null;
                    ?>
                    <article class="team-detail-card">
                        <div class="team-detail-head">
                            <div>
                                <h3 class="team-detail-title"><?= e($t['name']) ?></h3>
                                <p class="team-detail-desc"><?= e($t['description'] ?: 'Tidak ada deskripsi kelompok.') ?></p>
                            </div>
                            <div class="team-invite-pill">
                                <span>KODE TIM: <b><?= e($t['invite_code']) ?></b></span>
                                <button
                                    class="icon-btn small"
                                    type="button"
                                    data-copy="<?= e($t['invite_code']) ?>"
                                    data-copy-message="Kode kelompok <?= e($t['invite_code']) ?> disalin!"
                                    title="Salin kode kelompok">
                                    ⧉
                                </button>
                            </div>
                        </div>

                        <div class="team-pm-strip">
                            <span class="pm-badge">★ Project Manager</span>
                            <span class="pm-name">
                                <b><?= e($pm ? $pm['name'] : 'Belum ditetapkan') ?></b>
                                <?php if ($pm && !empty($pm['nim_nip'])): ?>
                                    <small class="muted">(NIM: <?= e($pm['nim_nip']) ?>)</small>
                                <?php endif; ?>
                            </span>
                            <?php if ($teacher && count($tMembers) > 1): ?>
                                <button
                                    class="btn secondary small"
                                    type="button"
                                    data-change-pm
                                    data-team-id="<?= e($t['id']) ?>"
                                    data-team-name="<?= e($t['name']) ?>"
                                    data-members='<?= json_encode(array_map(fn($m) => ['id' => $m['user_id'], 'name' => $m['name'], 'role' => $m['role_in_group']], $tMembers)) ?>'>
                                    Ganti PM
                                </button>
                            <?php endif; ?>
                        </div>

                        <div class="team-meta-row">
                            <span>👥 <b><?= count($tMembers) ?></b> Mahasiswa</span>
                            <span>☷ <b><?= count($tTasks) ?></b> Tugas WBS</span>
                            <span>✓ <b><?= $tDone ?></b> Selesai (<?= $tRate ?>%)</span>
                        </div>

                        <div class="team-members-list">
                            <div class="team-members-caption">ANGGOTA KELOMPOK:</div>
                            <div class="team-member-badges">
                                <?php foreach ($tMembers as $m): ?>
                                    <span class="member-chip <?= $m['role_in_group'] === 'ketua' ? 'chip-pm' : '' ?>">
                                        <span class="avatar"><?= e(mb_strtoupper(mb_substr($m['name'] ?? '?', 0, 1))) ?></span>
                                        <span class="chip-label">
                                            <?= e($m['name']) ?>
                                            <?= $m['role_in_group'] === 'ketua' ? '<i>(PM)</i>' : '' ?>
                                        </span>
                                        <?php if ($teacher && $m['role_in_group'] !== 'ketua'): ?>
                                            <form method="post" style="display:inline;" onsubmit="return confirm('Keluarkan <?= e($m['name']) ?> dari kelompok ini?');">
                                                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                                <input type="hidden" name="action" value="remove_team_member">
                                                <input type="hidden" name="team_id" value="<?= e($t['id']) ?>">
                                                <input type="hidden" name="user_id" value="<?= e($m['user_id']) ?>">
                                                <button class="chip-remove" type="submit" title="Keluarkan anggota">✕</button>
                                            </form>
                                        <?php endif; ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <?php if ($teacher): ?>
                            <div class="team-manage-strip">
                                <button
                                    class="btn secondary small"
                                    type="button"
                                    data-add-member-btn
                                    data-team-id="<?= e($t['id']) ?>"
                                    data-team-name="<?= e($t['name']) ?>">
                                    ＋ Tambah Anggota ke Kelompok Ini
                                </button>
                            </div>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<!-- TAB 2: MAHASISWA -->
<section class="class-tab-pane" id="tabStudents">
    <div class="panel">
        <div class="panel-heading">
            <div>
                <div class="panel-title">Daftar Mahasiswa Terdaftar</div>
                <div class="panel-sub">Mahasiswa yang telah bergabung ke kelas melalui kode undangan.</div>
            </div>
            <span class="counter"><?= count($students) ?> mahasiswa</span>
        </div>

        <?php if (!$students): ?>
            <div class="empty-state">
                <b>Belum ada mahasiswa bergabung</b>
                <p>Bagikan kode kelas <b><?= e($class['invite_code']) ?></b> kepada mahasiswa.</p>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>NIM</th>
                            <th>Nama Mahasiswa</th>
                            <th>Email</th>
                            <th>Status Kelompok</th>
                            <th>Bergabung</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($students as $s): ?>
                            <tr>
                                <td><b><?= e($s['nim_nip'] ?: '—') ?></b></td>
                                <td>
                                    <div class="table-main">
                                        <span class="avatar"><?= e(mb_strtoupper(mb_substr($s['name'], 0, 1))) ?></span>
                                        <div>
                                            <b><?= e($s['name']) ?></b>
                                            <?php if (strtolower((string) $s['user_id']) === strtolower((string) $user['id'])): ?>
                                                <span class="status-badge good">Kamu</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td><span class="muted"><?= e($s['email']) ?></span></td>
                                <td>
                                    <?php if (!empty($s['team_name'])): ?>
                                        <span class="status-badge good">
                                            <?= e($s['team_name']) ?>
                                            <?= ($s['role_in_team'] ?? '') === 'ketua' ? '★ (PM)' : '' ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="status-badge waiting">Belum memiliki kelompok</span>
                                    <?php endif; ?>
                                </td>
                                <td><small class="table-secondary"><?= e(format_date($s['joined_at'])) ?></small></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</section>

<!-- TAB 3: TUGAS (WBS) -->
<section class="class-tab-pane" id="tabTasks">
    <div class="panel">
        <div class="panel-heading">
            <div>
                <div class="panel-title">Work Breakdown Structure (WBS) & Tugas</div>
                <div class="panel-sub">Tugas dicatat dan dipantau secara terpisah per kelompok.</div>
            </div>
            <div class="panel-actions">
                <?php if ($teacher): ?>
                    <button class="btn primary" type="button" data-modal-open="createTaskModal">
                        ＋ Buat Tugas Kelas
                    </button>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!$tasks): ?>
            <div class="empty-state">
                <span class="empty-illustration">☷</span>
                <b>Belum ada tugas di kelas ini</b>
                <p><?= $teacher ? 'Klik tombol "+ Buat Tugas Kelas" untuk memberikan instruksi tugas kepada kelompok.' : 'Dosen belum membagikan tugas untuk kelompokmu.' ?></p>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Tugas</th>
                            <th>Kelompok</th>
                            <th>Bobot</th>
                            <th>Target</th>
                            <th>Status</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tasks as $tk): ?>
                            <?php
                                $canEditTask = $teacher || ($myTeamInClass && strtolower((string) $tk['group_id']) === strtolower((string) $myTeamInClass['id']));
                            ?>
                            <tr>
                                <td>
                                    <b><?= e($tk['title']) ?></b>
                                    <?php if (!empty($tk['description'])): ?>
                                        <small class="table-secondary"><?= e($tk['description']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><span class="pill blue-pill"><?= e($tk['group_name']) ?></span></td>
                                <td><span class="task-tag"><?= e($tk['weight']) ?>×</span></td>
                                <td><small>Minggu <?= (int) $tk['target_week'] ?></small></td>
                                <td>
                                    <span class="status-badge <?= $tk['status_db'] === 'done' ? 'good' : ($tk['status_db'] === 'in_progress' ? 'doing' : '') ?>">
                                        <?= e($tk['status']) ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($canEditTask && !$teacher): ?>
                                        <form method="post" style="display:inline;">
                                            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="action" value="update_task_status">
                                            <input type="hidden" name="task_id" value="<?= e($tk['id']) ?>">
                                            <select name="status" onchange="this.form.submit()" style="font-size:11px; padding:3px 6px;">
                                                <option value="todo" <?= $tk['status_db'] === 'todo' ? 'selected' : '' ?>>Belum dikerjakan</option>
                                                <option value="in_progress" <?= $tk['status_db'] === 'in_progress' ? 'selected' : '' ?>>Dikerjakan</option>
                                                <option value="review" <?= $tk['status_db'] === 'review' ? 'selected' : '' ?>>Review</option>
                                                <option value="done" <?= $tk['status_db'] === 'done' ? 'selected' : '' ?>>Selesai</option>
                                            </select>
                                        </form>
                                    <?php else: ?>
                                        <small class="muted"><?= $tk['status_db'] === 'done' ? 'Tuntas' : 'Berjalan' ?></small>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</section>

<!-- TAB 4: MONITORING KELAS (DOSEN) -->
<?php if ($teacher): ?>
<section class="class-tab-pane" id="tabMonitoring">
    <div class="panel">
        <div class="panel-heading">
            <div>
                <div class="panel-title">Monitoring Progres & Transparansi Kelompok</div>
                <div class="panel-sub">Pantau kinerja kelompok, penyelesaian tugas WBS, dan telemetri kontribusi.</div>
            </div>
        </div>

        <?php if (!$teams): ?>
            <div class="empty-state">
                <b>Belum ada data monitoring</b>
                <p>Monitoring akan tersedia setelah kelompok terbentuk.</p>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Kelompok</th>
                            <th>Project Manager</th>
                            <th>Anggota</th>
                            <th>Tugas Selesai</th>
                            <th>Progres</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($teams as $tm): ?>
                            <?php
                                $tmTasks = array_values(array_filter($tasks, fn($tk) => ($tk['group_id'] ?? '') === $tm['id']));
                                $tmDone = count(array_filter($tmTasks, fn($tk) => ($tk['status_db'] ?? '') === 'done'));
                                $tmRate = count($tmTasks) > 0 ? round(($tmDone / count($tmTasks)) * 100) : 0;
                                $pmInfo = $tm['project_manager'] ?? null;
                            ?>
                            <tr>
                                <td>
                                    <b><?= e($tm['name']) ?></b>
                                    <small class="table-secondary">Kode: <?= e($tm['invite_code']) ?></small>
                                </td>
                                <td>
                                    <b><?= e($pmInfo ? $pmInfo['name'] : '—') ?></b>
                                </td>
                                <td><?= count($tm['members'] ?? []) ?> mahasiswa</td>
                                <td><?= $tmDone ?> / <?= count($tmTasks) ?> tugas</td>
                                <td>
                                    <div style="display:flex; align-items:center; gap:8px;">
                                        <div class="mini-progress green-progress" style="width:90px;">
                                            <span style="width:<?= $tmRate ?>%"></span>
                                        </div>
                                        <small><b><?= $tmRate ?>%</b></small>
                                    </div>
                                </td>
                                <td>
                                    <a class="btn secondary small" href="tasks.php?group=<?= urlencode($tm['id']) ?>">
                                        Lihat Tugas →
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>

<!-- MODAL BENTUK KELOMPOK -->
<div class="modal-overlay" id="createTeamModal" hidden>
    <div class="modal-box">
        <div class="modal-head">
            <div>
                <h3>Bentuk Kelompok Kolaborasi</h3>
                <small>Kelompok akan terikat pada kelas <?= e($class['name']) ?>.</small>
            </div>
            <button class="modal-close" type="button" data-modal-close="createTeamModal">✕</button>
        </div>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="create_team">
            <div class="modal-body">
                <div class="form-group">
                    <label>Nama Kelompok <span class="text-danger">*</span></label>
                    <input name="name" required maxlength="150" placeholder="Contoh: Kelompok 01 - Smart Parking">
                </div>
                <div class="form-group">
                    <label>Deskripsi Proyek Kelompok</label>
                    <textarea name="description" rows="2" placeholder="Rencana proyek, topik aplikasi, atau pembagian peran..."></textarea>
                </div>
                <div class="form-group">
                    <label>Pilih Project Manager (Ketua Kelompok) <span class="text-danger">*</span></label>
                    <select name="pm_user_id" required>
                        <?php if (!$teacher): ?>
                            <option value="<?= e($user['id']) ?>" selected>Diri Saya Sendiri (<?= e($user['name']) ?>)</option>
                        <?php else: ?>
                            <option value="">-- Pilih Mahasiswa sebagai Project Manager --</option>
                        <?php endif; ?>
                        <?php foreach ($students as $s): ?>
                            <?php if (!$teacher && strtolower((string) $s['user_id']) === strtolower((string) $user['id'])) continue; ?>
                            <option value="<?= e($s['user_id']) ?>">
                                <?= e($s['name']) ?> (NIM: <?= e($s['nim_nip'] ?: '—') ?>)
                                <?= !empty($s['team_name']) ? ' [Sudah di: ' . e($s['team_name']) . ']' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <?php if ($teacher && count($unassignedStudents) > 0): ?>
                    <div class="form-group">
                        <label>Pilih Anggota Awal Lainnya (Opsional)</label>
                        <div style="max-height:140px; overflow-y:auto; border:1px solid #ebebf4; border-radius:8px; padding:8px;">
                            <?php foreach ($unassignedStudents as $us): ?>
                                <label style="display:flex; align-items:center; gap:8px; margin-bottom:6px; font-size:12px;">
                                    <input type="checkbox" name="initial_members[]" value="<?= e($us['user_id']) ?>">
                                    <span><?= e($us['name']) ?> (NIM: <?= e($us['nim_nip'] ?: '—') ?>)</span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
            <div class="modal-foot">
                <button class="btn secondary" type="button" data-modal-close="createTeamModal">Batal</button>
                <button class="btn primary" type="submit">＋ Bentuk Kelompok</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL GANTI PROJECT MANAGER (DOSEN) -->
<?php if ($teacher): ?>
<div class="modal-overlay" id="changePmModal" hidden>
    <div class="modal-box">
        <div class="modal-head">
            <div>
                <h3>Ganti Project Manager Kelompok</h3>
                <small id="changePmTeamLabel">Kelompok</small>
            </div>
            <button class="modal-close" type="button" data-modal-close="changePmModal">✕</button>
        </div>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="change_pm">
            <input type="hidden" name="team_id" id="changePmTeamId" value="">
            <div class="modal-body">
                <div class="form-group">
                    <label>Pilih Project Manager Baru <span class="text-danger">*</span></label>
                    <select name="new_pm_id" id="changePmSelect" required>
                        <!-- Diisi via JS -->
                    </select>
                    <small class="muted" style="display:block; margin-top:6px;">
                        Transaksi database akan secara otomatis mengalihkan ketua lama menjadi anggota dan anggota terpilih menjadi ketua baru.
                    </small>
                </div>
            </div>
            <div class="modal-foot">
                <button class="btn secondary" type="button" data-modal-close="changePmModal">Batal</button>
                <button class="btn primary" type="submit">Simpan Pergantian PM</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL TAMBAH ANGGOTA KE KELOMPOK (DOSEN) -->
<div class="modal-overlay" id="addMemberModal" hidden>
    <div class="modal-box">
        <div class="modal-head">
            <div>
                <h3>Tambah Mahasiswa ke Kelompok</h3>
                <small id="addMemberTeamLabel">Kelompok</small>
            </div>
            <button class="modal-close" type="button" data-modal-close="addMemberModal">✕</button>
        </div>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="add_team_member">
            <input type="hidden" name="team_id" id="addMemberTeamId" value="">
            <div class="modal-body">
                <div class="form-group">
                    <label>Pilih Mahasiswa Kelas <span class="text-danger">*</span></label>
                    <select name="user_id" required>
                        <option value="">-- Pilih Mahasiswa Kelas --</option>
                        <?php foreach ($students as $s): ?>
                            <option value="<?= e($s['user_id']) ?>">
                                <?= e($s['name']) ?> (NIM: <?= e($s['nim_nip'] ?: '—') ?>)
                                <?= !empty($s['team_name']) ? ' [Saat ini di: ' . e($s['team_name']) . ']' : ' [Belum ada kelompok]' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="muted" style="display:block; margin-top:6px;">
                        Sistem hanya mengizinkan mahasiswa yang terdaftar di kelas ini dan belum tergabung di kelompok lain.
                    </small>
                </div>
            </div>
            <div class="modal-foot">
                <button class="btn secondary" type="button" data-modal-close="addMemberModal">Batal</button>
                <button class="btn primary" type="submit">Tambahkan Mahasiswa →</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL BUAT TUGAS KELAS (DOSEN) -->
<div class="modal-overlay" id="createTaskModal" hidden>
    <div class="modal-box">
        <div class="modal-head">
            <div>
                <h3>Buat Tugas Kelas</h3>
                <small>Tugas dapat dibagikan kepada seluruh kelompok atau kelompok tertentu.</small>
            </div>
            <button class="modal-close" type="button" data-modal-close="createTaskModal">✕</button>
        </div>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="create_task">
            <div class="modal-body">
                <div class="form-group">
                    <label>Judul Tugas WBS <span class="text-danger">*</span></label>
                    <input name="title" required maxlength="200" placeholder="Contoh: Modul Otentikasi dan Desain Basis Data">
                </div>
                <div class="form-group">
                    <label>Deskripsi atau Instruksi Pengerjaan</label>
                    <textarea name="description" rows="3" placeholder="Jelaskan deliverable, kriteria kelulusan, atau instruksi..."></textarea>
                </div>
                <div class="form-row-2">
                    <div class="form-group">
                        <label>Bobot Kesulitan (1–5)</label>
                        <select name="weight">
                            <option value="1">1 - Sangat Ringan</option>
                            <option value="2">2 - Ringan</option>
                            <option value="3" selected>3 - Sedang</option>
                            <option value="4">4 - Berat</option>
                            <option value="5">5 - Sangat Kompleks</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Target Minggu Ke-</label>
                        <input type="number" name="target_week" min="1" max="52" value="1" required>
                    </div>
                </div>
                <div class="form-group">
                    <label>Kelompok Penerima Tugas <span class="text-danger">*</span></label>
                    <div style="margin-bottom:8px;">
                        <label style="margin-right:16px;">
                            <input type="radio" name="target_scope" value="all" checked onchange="document.getElementById('teamSelectRow').hidden = true;">
                            Semua Kelompok di Kelas (<?= $totalTeams ?> kelompok)
                        </label>
                        <label>
                            <input type="radio" name="target_scope" value="single" onchange="document.getElementById('teamSelectRow').hidden = false;">
                            Kelompok Tertentu
                        </label>
                    </div>
                    <div id="teamSelectRow" hidden>
                        <select name="selected_team_id">
                            <option value="">-- Pilih Kelompok Penerima --</option>
                            <?php foreach ($teams as $t): ?>
                                <option value="<?= e($t['id']) ?>"><?= e($t['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <small class="muted" style="display:block; margin-top:6px;">
                        Jika memilih "Semua Kelompok", sistem akan mencatat satu record tugas terpisah untuk setiap kelompok agar progres masing-masing tidak saling mengganggu.
                    </small>
                </div>
            </div>
            <div class="modal-foot">
                <button class="btn secondary" type="button" data-modal-close="createTaskModal">Batal</button>
                <button class="btn primary" type="submit">Bagikan Tugas →</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
document.addEventListener("DOMContentLoaded", function() {
    // Tab switching
    var tabBtns = document.querySelectorAll(".class-tab-btn");
    var tabPanes = document.querySelectorAll(".class-tab-pane");

    tabBtns.forEach(function(btn) {
        btn.addEventListener("click", function() {
            var targetId = btn.getAttribute("data-tab-target");

            tabBtns.forEach(function(b) { b.classList.remove("active"); });
            tabPanes.forEach(function(p) { p.classList.remove("active"); });

            btn.classList.add("active");
            var pane = document.getElementById(targetId);
            if (pane) {
                pane.classList.add("active");
            }
        });
    });

    // Check hash URL
    if (window.location.hash) {
        var hash = window.location.hash.replace('#', '');
        if (hash === 'teams') {
            document.querySelector("[data-tab-target='tabTeams']")?.click();
        } else if (hash === 'students') {
            document.querySelector("[data-tab-target='tabStudents']")?.click();
        } else if (hash === 'tasks') {
            document.querySelector("[data-tab-target='tabTasks']")?.click();
        } else if (hash === 'monitoring') {
            document.querySelector("[data-tab-target='tabMonitoring']")?.click();
        }
    }

    // Modal open/close
    document.querySelectorAll("[data-modal-open]").forEach(function(btn) {
        btn.addEventListener("click", function() {
            var modalId = btn.getAttribute("data-modal-open");
            var modal = document.getElementById(modalId);
            if (modal) modal.hidden = false;
        });
    });

    document.querySelectorAll("[data-modal-close]").forEach(function(btn) {
        btn.addEventListener("click", function() {
            var modalId = btn.getAttribute("data-modal-close");
            var modal = document.getElementById(modalId);
            if (modal) modal.hidden = true;
        });
    });

    document.querySelectorAll(".modal-overlay").forEach(function(overlay) {
        overlay.addEventListener("click", function(e) {
            if (e.target === overlay) overlay.hidden = true;
        });
    });

    // Ganti PM button
    document.querySelectorAll("[data-change-pm]").forEach(function(btn) {
        btn.addEventListener("click", function() {
            var teamId = btn.getAttribute("data-team-id");
            var teamName = btn.getAttribute("data-team-name");
            var members = JSON.parse(btn.getAttribute("data-members") || "[]");

            document.getElementById("changePmTeamId").value = teamId;
            document.getElementById("changePmTeamLabel").textContent = "Kelompok: " + teamName;

            var select = document.getElementById("changePmSelect");
            select.innerHTML = "";
            members.forEach(function(m) {
                var opt = document.createElement("option");
                opt.value = m.id;
                opt.textContent = m.name + (m.role === "ketua" ? " (PM Saat Ini)" : "");
                if (m.role === "ketua") opt.disabled = true;
                select.appendChild(opt);
            });

            document.getElementById("changePmModal").hidden = false;
        });
    });

    // Tambah anggota button
    document.querySelectorAll("[data-add-member-btn]").forEach(function(btn) {
        btn.addEventListener("click", function() {
            var teamId = btn.getAttribute("data-team-id");
            var teamName = btn.getAttribute("data-team-name");

            document.getElementById("addMemberTeamId").value = teamId;
            document.getElementById("addMemberTeamLabel").textContent = "Menambahkan ke kelompok: " + teamName;
            document.getElementById("addMemberModal").hidden = false;
        });
    });
});
</script>

<?php layout_end(); ?>

