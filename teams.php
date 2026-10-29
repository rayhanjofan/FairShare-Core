<?php
require_once __DIR__ . '/includes/bootstrap.php';
$user = require_login();
$error = '';
$teacher = is_teacher($user);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    try {
        $pdo = require_db();
        // Ambil ulang identitas pengguna dari Supabase.
        // Ini memperbaiki sesi lama yang mungkin tidak memiliki UUID.
        $sessionEmail = trim((string) ($user['email'] ?? ''));
        if ($sessionEmail === '') {
            throw new RuntimeException(
                'Sesi login tidak memiliki email. Silakan logout dan login kembali.'
            );
        }

        $refreshUser = $pdo->prepare("
        SELECT
        id::text AS id,
        nim_nip,
        name,
        email,
        role
        FROM public.users
        WHERE LOWER(email) = LOWER(:email)
        LIMIT 1
        ");

        $refreshUser->execute([
            'email' => $sessionEmail
        ]);

        $dbUser = $refreshUser->fetch(PDO::FETCH_ASSOC);

        if (!$dbUser) {
            throw new RuntimeException(
                'Akun aktif tidak ditemukan pada public.users di Supabase.'
            );
        }

        // Perbarui data sesi dengan data aktual dari database.
        $user = array_merge($user, $dbUser);
        $_SESSION['user'] = $user;

        // Pastikan ID benar-benar UUID sebelum digunakan.
        if (!valid_uuid((string) ($user['id'] ?? ''))) {
            throw new RuntimeException(
                'ID akun dari Supabase bukan UUID yang valid.'
            );
        }

        // Perbarui role setelah data pengguna dimuat ulang.
        $teacher = is_teacher($user);
        if ($action === 'create' && !$teacher) {
            $name = trim((string) ($_POST['name'] ?? ''));
            $course = trim((string) ($_POST['course'] ?? ''));
            if ($name === '')
                $error = 'Nama kelompok wajib diisi.';
            elseif (mb_strlen($name) > 150 || mb_strlen($course) > 100)
                $error = 'Nama kelompok maksimal 150 karakter dan mata kuliah maksimal 100 karakter.';
            else {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare(
                    "INSERT INTO public.groups
                        (id, created_by, name, class_name, duration_weeks, github_repo_url, gdocs_file_id, created_at, updated_at)
                    VALUES (
                        gen_random_uuid(),
                        CAST(:creator AS uuid),
                        :name,
                        :class_name,
                        16,
                        NULL,
                        NULL,
                        CURRENT_TIMESTAMP,
                        CURRENT_TIMESTAMP
                    )
                    RETURNING id::text"
                );
                $stmt->execute([
                    'creator'    => (string) $user['id'],
                    'name'       => $name,
                    'class_name' => $course !== '' ? $course : 'PBL',
                ]);

                // fetchColumn() hanya boleh dipanggil SEKALI per statement.
                // Panggilan kedua mengembalikan false (cursor habis) yang
                // di-cast menjadi string kosong — itulah sumber SQLSTATE 22P02.
                $gid = (string) $stmt->fetchColumn();

                if (!valid_uuid($gid)) {
                    throw new RuntimeException(
                        'Pembuatan kelompok gagal menghasilkan UUID yang valid. Nilai dikembalikan: ' . var_export($gid, true)
                    );
                }

                $member = $pdo->prepare(
                    "INSERT INTO public.group_members
                        (id, group_id, user_id, role_in_group, peer_approval, joined_at)
                    SELECT
                        gen_random_uuid(),
                        CAST(:gid AS uuid),
                        CAST(:uid AS uuid),
                        'ketua',
                        FALSE,
                        CURRENT_TIMESTAMP
                    WHERE NOT EXISTS (
                        SELECT 1 FROM public.group_members
                        WHERE group_id = CAST(:gid2 AS uuid)
                          AND user_id  = CAST(:uid2  AS uuid)
                    )"
                );

                $member->execute([
                    'gid'  => $gid,
                    'uid'  => (string) $user['id'],
                    'gid2' => $gid,
                    'uid2' => (string) $user['id'],
                ]);
                $pdo->commit();
                log_activity('Kelompok dibuat', $name, 'success');
                flash('Kelompok berhasil dibuat di Supabase. Bagikan ID kelompok sebagai kode undangan.');
                redirect('teams.php');
            }
        } elseif ($action === 'join' && !$teacher) {
            $code = strtolower(trim((string) ($_POST['code'] ?? '')));
            if (!valid_uuid($code))
                $error = 'Kode undangan tidak valid. Gunakan ID UUID kelompok yang diberikan ketua.';
            else {
                $find = $pdo->prepare('SELECT id::text FROM public.groups WHERE id=CAST(:id AS uuid)');
                $find->execute(['id' => $code]);
                $gid = $find->fetchColumn();
                if (!$gid)
                    $error = 'Kelompok dengan kode tersebut tidak ditemukan.';
                else {
                    // Periksa apakah pengguna sudah menjadi anggota (database mungkin tidak
                    // memiliki UNIQUE constraint pada group_members).
                    $checkMember = $pdo->prepare(
                        'SELECT COUNT(*) FROM public.group_members
                         WHERE group_id = CAST(:gid AS uuid) AND user_id = CAST(:uid AS uuid)'
                    );
                    $checkMember->execute([
                        'gid' => (string) $gid,
                        'uid' => (string) $user['id'],
                    ]);
                    if ((int) $checkMember->fetchColumn() > 0) {
                        $error = 'Kamu sudah menjadi anggota kelompok tersebut.';
                    } else {
                        $stmt = $pdo->prepare(
                            "INSERT INTO public.group_members
                                (id, group_id, user_id, role_in_group, peer_approval, joined_at)
                            VALUES (
                                gen_random_uuid(),
                                CAST(:gid AS uuid),
                                CAST(:uid AS uuid),
                                'anggota',
                                FALSE,
                                CURRENT_TIMESTAMP
                            )"
                        );
                        $stmt->execute([
                            'gid' => (string) $gid,
                            'uid' => (string) $user['id'],
                        ]);
                        log_activity('Anggota bergabung', 'Bergabung ke kelompok melalui ID undangan', 'success');
                        flash('Berhasil bergabung ke kelompok. Data keanggotaan tersimpan di Supabase.');
                        redirect('teams.php');
                    }
                }
            }
        } elseif ($action === 'invite' && !$teacher) {
            $gid = (string) ($_POST['group_id'] ?? '');
            $group = group_by_id($gid);
            if (!$group || !can_manage_group($group, $user))
                $error = 'Hanya ketua kelompok yang dapat membagikan undangan.';
            else {
                flash('Kode undangan kelompok: ' . group_invite_code($group));
                redirect('teams.php');
            }
        }
    } catch (PDOException $e) {
        error_log('FairShare teams SQLSTATE ' . $e->getCode() . ': ' . $e->getMessage());
        if (isset($pdo) && $pdo->inTransaction())
            $pdo->rollBack();
        $error = 'Perubahan kelompok gagal disimpan. Periksa koneksi dan izin database.';
    } catch (Throwable $e) {
        error_log('FairShare teams: ' . $e->getMessage());
        if (isset($pdo) && $pdo->inTransaction())
            $pdo->rollBack();
        $error = 'Database Supabase tidak dapat diakses. Periksa konfigurasi data/.env.';
    }
}
$groups = visible_groups($user);
layout_start('Tim & Kelompok', 'teams');
$headingAction = (!$teacher ? '<a class="btn primary" href="#create-group">＋ Buat kelompok</a>' : '<a class="btn secondary" href="classroom.php">Monitor kelas ↗</a>');
page_heading($teacher ? 'MONITORING AKADEMIK' : 'WORKSPACE / TIM', $teacher ? 'Seluruh Kelompok' : 'Tim & Kelompok', $teacher ? 'Pantau kelompok dan progres proyek yang berada dalam ruang lingkup pengajaranmu.' : 'Kelola ruang kerja, undang anggota, dan pantau proyek kolaborasi.', $headingAction);
if ($error)
    echo '<div class="flash error">' . e($error) . '</div>';
echo '<div class="stats-grid three-stats"><article class="stat-card"><div class="stat-label">' . ($teacher ? 'TOTAL KELOMPOK' : 'KELOMPOK SAYA') . '</div><div class="stat-number">' . count($groups) . '</div><div class="stat-foot">' . ($teacher ? 'Kelompok di Supabase' : 'Workspace yang diikuti') . '</div></article><article class="stat-card"><div class="stat-label">TOTAL ANGGOTA</div><div class="stat-number">' . array_sum(array_map(fn($g) => count($g['members'] ?? []), $groups)) . '</div><div class="stat-foot">Keanggotaan dari database bersama</div></article><article class="stat-card"><div class="stat-label">AKSES</div><div class="stat-number"><small>' . ($teacher ? 'DOSEN / AUDITOR' : 'MAHASISWA') . '</small></div><div class="stat-foot">Data ditampilkan sesuai role</div></article></div>';
echo '<div class="team-layout"><section class="panel"><div class="panel-heading"><div><div class="panel-title">' . ($teacher ? 'Kelompok dalam pemantauan' : 'Workspace kelompok') . '</div><div class="panel-sub">' . ($teacher ? 'Kelompok yang terdaftar di database' : 'Kelompok yang kamu ikuti') . '</div></div><span class="counter">' . count($groups) . ' total</span></div>';
if (!$groups)
    echo '<div class="empty-state"><span class="empty-illustration">♧</span><b>Belum ada kelompok</b><p>' . ($teacher ? 'Kelompok yang dibuat mahasiswa akan muncul di sini.' : 'Buat kelompok baru atau gabung menggunakan ID undangan dari ketua kelompok.') . '</p></div>';
foreach ($groups as $g) {
    $members = $g['members'] ?? [];
    $code = group_invite_code($g);
    echo '<article class="group-card"><div class="group-card-head"><div class="group-symbol">✦</div><div class="group-main"><h3>' . e($g['name']) . '</h3><p>' . e($g['course'] ?? 'PBL') . ' · Dibuat ' . e(format_date($g['created_at'] ?? '')) . '</p></div>' . ($teacher ? '<span class="status-badge good">Mode monitor</span>' : '<button class="icon-btn" type="button" data-copy="' . e($code) . '" title="Salin ID undangan">⧉</button>') . '</div><div class="group-meta"><span>♧ ' . count($members) . ' anggota</span><span>⌁ ' . ($teacher ? 'Pembuat: ' : 'ID undangan: ') . '<b>' . e($teacher ? ($g['creator_name'] ?? 'Tidak diketahui') : $code) . '</b></span></div><div class="member-stack">';
    foreach (array_slice($members, 0, 6) as $m)
        echo '<span class="avatar" title="' . e($m['name'] ?? 'Anggota') . '">' . e(mb_strtoupper(mb_substr($m['name'] ?? '?', 0, 1))) . '</span>';
    echo '</div><div class="group-card-foot"><span class="status-badge good">● Workspace Supabase</span><a class="text-link" href="tasks.php?group=' . e($g['id']) . '">Lihat tugas →</a></div>';
    if (!$teacher && can_manage_group($g, $user))
        echo '<div class="invite-strip"><div><b>Undang anggota</b><small>Bagikan ID UUID ini; kode disimpan di database sebagai ID kelompok.</small></div><code>' . e($code) . '</code><button class="btn secondary small" type="button" data-copy="' . e($code) . '">Salin ID</button></div>';
    echo '</article>';
}
echo '</section>';
if (!$teacher)
    echo '<aside class="panel side-form" id="create-group"><div class="panel-title">Buat kelompok</div><p class="panel-sub">Buat workspace baru untuk mata kuliah atau proyek.</p><form method="post"><input type="hidden" name="csrf" value="' . e(csrf_token()) . '"><input type="hidden" name="action" value="create"><label>Nama kelompok</label><input name="name" required maxlength="150" placeholder="Contoh: Kelompok PBL 01"><label>Mata kuliah / proyek</label><input name="course" maxlength="100" placeholder="Contoh: Project Based Learning"><button class="btn primary full-width">＋ Buat workspace</button></form><div class="form-separator"></div><div class="panel-title">Gabung kelompok</div><p class="panel-sub">Masukkan ID UUID kelompok dari ketua.</p><form method="post"><input type="hidden" name="csrf" value="' . e(csrf_token()) . '"><input type="hidden" name="action" value="join"><label>ID undangan kelompok</label><input name="code" required maxlength="36" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"><button class="btn secondary full-width">Gabung kelompok →</button></form></aside>';
else
    echo '<aside class="panel side-form"><div class="panel-title">Akses dosen</div><p class="panel-sub">Dosen dapat melihat semua kelompok yang tersedia pada database ini. Akses produksi sebaiknya dibatasi berdasarkan kelas/mata kuliah.</p><a class="btn primary full-width" href="classroom.php">Buka monitor kelas →</a><div class="form-separator"></div><div class="panel-title">Integritas data</div><p class="panel-sub">Keanggotaan disimpan pada tabel group_members. Pengguna tidak dapat terdaftar dua kali pada kelompok yang sama.</p></aside>';
echo '</div>';
layout_end();
