<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$user = require_login();
$teacher = is_teacher($user);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? '');

    try {
        $pdo = require_db();

        // Refresh user identity from DB to ensure valid UUID
        $sessionEmail = trim((string) ($user['email'] ?? ''));
        if ($sessionEmail === '') {
            throw new RuntimeException('Sesi login tidak memiliki email. Silakan logout dan login kembali.');
        }

        $refreshUser = $pdo->prepare("
            SELECT id::text AS id, nim_nip, name, email, role
            FROM public.users
            WHERE LOWER(email) = LOWER(:email)
            LIMIT 1
        ");
        $refreshUser->execute(['email' => $sessionEmail]);
        $dbUser = $refreshUser->fetch(PDO::FETCH_ASSOC);

        if (!$dbUser) {
            throw new RuntimeException('Akun aktif tidak ditemukan pada database.');
        }

        $user = array_merge($user, $dbUser);
        $teacher = is_teacher($user);

        if ($action === 'create' && !$teacher) {
            $name = trim((string) ($_POST['name'] ?? ''));
            $classId = trim((string) ($_POST['class_id'] ?? ''));
            $desc = trim((string) ($_POST['description'] ?? ''));

            if ($name === '') {
                $error = 'Nama kelompok wajib diisi.';
            } elseif (mb_strlen($name) > 150) {
                $error = 'Nama kelompok maksimal 150 karakter.';
            } elseif (!valid_uuid($classId)) {
                $error = 'Pilih kelas yang valid untuk kelompok ini.';
            } else {
                // Pastikan mahasiswa sudah menjadi anggota kelas tersebut
                $classCheck = $pdo->prepare("
                    SELECT g.id::text AS id, g.name, g.duration_weeks, g.semester, g.academic_year
                    FROM public.groups g
                    JOIN public.group_members gm ON gm.group_id = g.id
                    WHERE g.id = CAST(:cid AS uuid)
                      AND g.group_type = 'class'
                      AND gm.user_id = CAST(:uid AS uuid)
                    LIMIT 1
                ");
                $classCheck->execute(['cid' => $classId, 'uid' => (string) $user['id']]);
                $parentClass = $classCheck->fetch(PDO::FETCH_ASSOC);

                if (!$parentClass) {
                    $error = 'Kamu belum terdaftar di kelas yang dipilih. Silakan bergabung ke kelas terlebih dahulu di halaman Kelas Saya.';
                } else {
                    // Pastikan mahasiswa belum terdaftar di kelompok lain di kelas ini
                    $teamCheck = $pdo->prepare("
                        SELECT tm.name FROM public.group_members gm
                        JOIN public.groups tm ON tm.id = gm.group_id
                        WHERE tm.parent_group_id = CAST(:cid AS uuid)
                          AND tm.group_type = 'team'
                          AND gm.user_id = CAST(:uid AS uuid)
                        LIMIT 1
                    ");
                    $teamCheck->execute(['cid' => $classId, 'uid' => (string) $user['id']]);
                    $existingTeam = $teamCheck->fetch(PDO::FETCH_ASSOC);

                    if ($existingTeam) {
                        $error = "Kamu sudah terdaftar di kelompok '{$existingTeam['name']}' pada kelas ini. Satu mahasiswa hanya boleh memiliki satu kelompok per kelas.";
                    } else {
                        // Generate kode unik kelompok
                        $prefix = 'TIM';
                        $words = preg_split('/\s+/', $name) ?: [];
                        if (count($words) >= 2) {
                            $prefix = strtoupper(mb_substr($words[0], 0, 2) . mb_substr($words[1], 0, 2));
                        } else {
                            $prefix = strtoupper(mb_substr($name, 0, 3));
                        }
                        $teamInviteCode = generate_unique_invite_code($pdo, $prefix ?: 'TIM');

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
                            'name'           => $name,
                            'class_name'     => $parentClass['name'],
                            'duration_weeks' => (int) ($parentClass['duration_weeks'] ?? 16),
                            'parent_id'      => $classId,
                            'invite_code'    => $teamInviteCode,
                            'description'    => $desc !== '' ? $desc : null,
                            'semester'       => $parentClass['semester'] ?? null,
                            'academic_year'  => $parentClass['academic_year'] ?? null,
                        ]);

                        $gid = (string) $stmt->fetchColumn();

                        // Pembuat kelompok otomatis menjadi ketua / Project Manager
                        $member = $pdo->prepare("
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
                        $member->execute(['gid' => $gid, 'uid' => (string) $user['id']]);

                        $pdo->commit();

                        log_activity('Kelompok dibuat', $name, 'success');
                        flash("Kelompok <b>" . e($name) . "</b> berhasil dibuat di kelas <b>" . e($parentClass['name']) . "</b>! Bagikan kode undangan: <b>" . e($teamInviteCode) . "</b>");
                        redirect('teams.php');
                    }
                }
            }
        } elseif ($action === 'join' && !$teacher) {
            $code = strtoupper(trim((string) ($_POST['code'] ?? '')));

            if ($code === '') {
                $error = 'Kode undangan kelompok wajib diisi.';
            } else {
                // Cari kelompok berdasarkan invite_code (atau UUID jika kompatibilitas lama)
                $find = $pdo->prepare("
                    SELECT g.id::text AS id, g.name, g.group_type, g.parent_group_id::text AS parent_group_id, p.name AS parent_class_name
                    FROM public.groups g
                    LEFT JOIN public.groups p ON p.id = g.parent_group_id
                    WHERE (UPPER(g.invite_code) = :code OR g.id::text = :code_uuid)
                      AND g.group_type = 'team'
                    LIMIT 1
                ");
                $find->execute([
                    'code'      => $code,
                    'code_uuid' => strtolower($code),
                ]);
                $targetTeam = $find->fetch(PDO::FETCH_ASSOC);

                if (!$targetTeam) {
                    $error = 'Kelompok dengan kode undangan tersebut tidak ditemukan.';
                } else {
                    $teamId = (string) $targetTeam['id'];
                    $parentClassId = (string) ($targetTeam['parent_group_id'] ?? '');

                    if ($parentClassId === '' || !valid_uuid($parentClassId)) {
                        $error = 'Kelompok ini tidak terhubung ke kelas yang valid.';
                    } else {
                        // 1. Verifikasi mahasiswa terdaftar di kelas induk
                        $checkClass = $pdo->prepare("
                            SELECT 1 FROM public.group_members
                            WHERE group_id = CAST(:cid AS uuid) AND user_id = CAST(:uid AS uuid)
                            LIMIT 1
                        ");
                        $checkClass->execute(['cid' => $parentClassId, 'uid' => (string) $user['id']]);
                        if (!$checkClass->fetch()) {
                            $className = $targetTeam['parent_class_name'] ?: 'kelas induk';
                            $error = "Kamu belum bergabung ke $className. Gabung ke kelas tersebut terlebih dahulu di menu Kelas Saya sebelum bergabung ke kelompok ini.";
                        } else {
                            // 2. Verifikasi mahasiswa belum terdaftar di kelompok ini
                            $checkThisTeam = $pdo->prepare("
                                SELECT 1 FROM public.group_members
                                WHERE group_id = CAST(:tid AS uuid) AND user_id = CAST(:uid AS uuid)
                                LIMIT 1
                            ");
                            $checkThisTeam->execute(['tid' => $teamId, 'uid' => (string) $user['id']]);
                            if ($checkThisTeam->fetch()) {
                                $error = 'Kamu sudah menjadi anggota kelompok ' . $targetTeam['name'] . '.';
                            } else {
                                // 3. Verifikasi mahasiswa belum terdaftar di kelompok lain di kelas yang sama
                                $checkOtherTeam = $pdo->prepare("
                                    SELECT tm.name FROM public.group_members gm
                                    JOIN public.groups tm ON tm.id = gm.group_id
                                    WHERE tm.parent_group_id = CAST(:cid AS uuid)
                                      AND tm.group_type = 'team'
                                      AND gm.user_id = CAST(:uid AS uuid)
                                    LIMIT 1
                                ");
                                $checkOtherTeam->execute(['cid' => $parentClassId, 'uid' => (string) $user['id']]);
                                $otherTeam = $checkOtherTeam->fetch(PDO::FETCH_ASSOC);

                                if ($otherTeam) {
                                    $error = "Kamu sudah menjadi anggota kelompok '{$otherTeam['name']}' di kelas yang sama. Satu mahasiswa hanya boleh memiliki satu kelompok aktif per kelas.";
                                } else {
                                    // Daftarkan sebagai anggota kelompok
                                    $stmt = $pdo->prepare("
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
                                    $stmt->execute(['gid' => $teamId, 'uid' => (string) $user['id']]);

                                    log_activity('Bergabung ke kelompok', "Bergabung ke {$targetTeam['name']}", 'success');
                                    flash("Berhasil bergabung ke kelompok <b>" . e($targetTeam['name']) . "</b>.");
                                    redirect('teams.php');
                                }
                            }
                        }
                    }
                }
            }
        }
    } catch (PDOException $e) {
        error_log('FairShare teams SQLSTATE ' . $e->getCode() . ': ' . $e->getMessage());
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = 'Gagal memproses kelompok: ' . $e->getMessage();
    } catch (Throwable $e) {
        error_log('FairShare teams error: ' . $e->getMessage());
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e->getMessage();
    }
}

$groups = visible_groups($user);
$myClasses = visible_classes($user);

layout_start($teacher ? 'Seluruh Kelompok' : 'Kelompok Saya', 'teams');

$headingAction = (!$teacher
    ? '<a class="btn primary" href="#create-group">＋ Buat kelompok</a>'
    : '<a class="btn secondary" href="classroom.php">Monitor kelas ↗</a>'
);

page_heading(
    $teacher ? 'MONITORING AKADEMIK' : 'WORKSPACE / KELOMPOK SAYA',
    $teacher ? 'Seluruh Kelompok' : 'Kelompok Saya',
    $teacher ? 'Pantau kelompok mahasiswa dan Project Manager yang berada dalam kelas pengajaranmu.' : 'Kelola kelompok kolaborasi, undang anggota kelas, dan pantau pembagian tugas.',
    $headingAction
);

if ($error) {
    echo '<div class="flash error">' . e($error) . '</div>';
}
?>

<div class="stats-grid three-stats">
    <article class="stat-card">
        <div class="stat-label"><?= $teacher ? 'TOTAL KELOMPOK TERPANTAU' : 'KELOMPOK SAYA' ?></div>
        <div class="stat-number"><?= count($groups) ?></div>
        <div class="stat-foot"><?= $teacher ? 'Kelompok dalam kelas milikmu' : 'Kelompok aktif yang kamu ikuti' ?></div>
    </article>
    <article class="stat-card">
        <div class="stat-label">TOTAL ANGGOTA</div>
        <div class="stat-number"><?= array_sum(array_map(fn($g) => count($g['members'] ?? []), $groups)) ?></div>
        <div class="stat-foot">Keanggotaan terverifikasi di Supabase</div>
    </article>
    <article class="stat-card">
        <div class="stat-label">PERAN AKSES</div>
        <div class="stat-number"><small><?= $teacher ? 'DOSEN PENGAMPU' : 'MAHASISWA' ?></small></div>
        <div class="stat-foot">Data dibatasi sesuai hak akses</div>
    </article>
</div>

<div class="team-layout">
    <section class="panel">
        <div class="panel-heading">
            <div>
                <div class="panel-title"><?= $teacher ? 'Kelompok dalam Pemantauan' : 'Daftar Kelompok Saya' ?></div>
                <div class="panel-sub"><?= $teacher ? 'Kelompok mahasiswa dari kelas yang kamu ajar' : 'Kelompok yang kamu ikuti di setiap kelas' ?></div>
            </div>
            <span class="counter"><?= count($groups) ?> total</span>
        </div>

        <?php if (!$groups): ?>
            <div class="empty-state">
                <span class="empty-illustration">♧</span>
                <b>Belum ada kelompok</b>
                <p>
                    <?= $teacher
                        ? 'Kelompok yang dibentuk mahasiswa di kelasmu akan muncul di sini.'
                        : 'Buat kelompok baru di kelas yang kamu ikuti atau gabung menggunakan kode undangan kelompok dari teman.'
                    ?>
                </p>
            </div>
        <?php else: ?>
            <?php foreach ($groups as $g): ?>
                <?php
                    $members = $g['members'] ?? [];
                    $code = group_invite_code($g);
                    $pm = $g['project_manager'] ?? null;
                    $className = $g['parent_class_name'] ?: ($g['course'] ?: 'Kelas Perkuliahan');
                ?>
                <article class="group-card">
                    <div class="group-card-head">
                        <div class="group-symbol">✦</div>
                        <div class="group-main">
                            <h3><?= e($g['name']) ?></h3>
                            <p>
                                <b><?= e($className) ?></b> · Dibuat <?= e(format_date($g['created_at'] ?? '')) ?>
                            </p>
                        </div>
                        <div style="display:flex; align-items:center; gap:8px;">
                            <button
                                class="btn secondary small"
                                type="button"
                                data-copy="<?= e($code) ?>"
                                data-copy-message="Kode kelompok <?= e($code) ?> disalin!"
                                title="Salin kode kelompok">
                                ⧉ <?= e($code) ?>
                            </button>
                        </div>
                    </div>

                    <div class="group-pm-info" style="margin: 12px 0 10px; padding: 8px 12px; background: #f6f5ff; border-radius: 8px; display: flex; align-items: center; justify-content: space-between;">
                        <span style="font-size: 11px; color: #5a49db; font-weight: 700;">
                            ★ Project Manager: <b><?= e($pm ? $pm['name'] : 'Belum ditetapkan') ?></b>
                        </span>
                        <?php if ($pm && strtolower((string) $pm['user_id']) === strtolower((string) $user['id'])): ?>
                            <span class="status-badge good">Kamu adalah PM</span>
                        <?php endif; ?>
                    </div>

                    <div class="group-meta">
                        <span>♧ <b><?= count($members) ?></b> anggota</span>
                        <span>⌁ Kode Undangan: <b><?= e($code) ?></b></span>
                    </div>

                    <div class="member-stack">
                        <?php foreach (array_slice($members, 0, 8) as $m): ?>
                            <span class="avatar" title="<?= e($m['name'] ?? 'Anggota') ?> (<?= $m['role_in_group'] === 'ketua' ? 'Project Manager' : 'Anggota' ?>)">
                                <?= e(mb_strtoupper(mb_substr($m['name'] ?? '?', 0, 1))) ?>
                            </span>
                        <?php endforeach; ?>
                    </div>

                    <div class="group-card-foot">
                        <?php if (!empty($g['parent_group_id'])): ?>
                            <a class="text-link" href="class_detail.php?id=<?= urlencode((string) $g['parent_group_id']) ?>">
                                Buka Kelas →
                            </a>
                        <?php endif; ?>
                        <a class="btn primary small" href="tasks.php?group=<?= urlencode($g['id']) ?>">
                            Kelola Tugas WBS →
                        </a>
                    </div>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>

    <?php if (!$teacher): ?>
        <aside class="panel side-form" id="create-group">
            <div class="panel-title">Buat Kelompok Baru</div>
            <p class="panel-sub">Bentuk kelompok kolaborasi baru di salah satu kelas yang kamu ikuti.</p>
            <form method="post">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="create">

                <label>Pilih Kelas Perkuliahan <span class="text-danger">*</span></label>
                <select name="class_id" required>
                    <option value="">-- Pilih Kelas --</option>
                    <?php foreach ($myClasses as $mc): ?>
                        <option value="<?= e($mc['id']) ?>"><?= e($mc['name']) ?></option>
                    <?php endforeach; ?>
                </select>

                <label>Nama Kelompok <span class="text-danger">*</span></label>
                <input name="name" required maxlength="150" placeholder="Contoh: Kelompok 01 - Smart App">

                <label>Deskripsi Proyek (Opsional)</label>
                <textarea 
                    name="description" 
                    rows="3" 
                    style="width: 100%; max-width: 100%; box-sizing: border-box; resize: vertical; min-height: 80px;" 
                    placeholder="Topik atau gambaran proyek kelompok..."></textarea>

                <small class="muted" style="display:block; margin:6px 0 14px;">
                    Kamu otomatis menjadi anggota dan Project Manager (ketua) kelompok ini.
                </small>

                <button class="btn primary full-width">＋ Buat Kelompok</button>
            </form>

            <div class="form-separator"></div>

            <div class="panel-title">Gabung Kelompok</div>
            <p class="panel-sub">Masukkan kode undangan kelompok dari teman sekelasmu.</p>
            <form method="post">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="join">
                <label>Kode Undangan Kelompok</label>
                <input name="code" required maxlength="25" style="text-transform:uppercase; font-weight:700;" placeholder="Contoh: TIM-A8K29X">
                <small class="muted" style="display:block; margin:6px 0 14px;">
                    Pastikan kamu sudah bergabung ke kelas yang sama sebelum memasukkan kode kelompok.
                </small>
                <button class="btn secondary full-width">Gabung Kelompok →</button>
            </form>
        </aside>
    <?php else: ?>
        <aside class="panel side-form">
            <div class="panel-title">Portal Dosen Pengampu</div>
            <p class="panel-sub">Kelompok di halaman ini dibatasi khusus pada kelas-kelas yang kamu kelola.</p>
            <a class="btn primary full-width" href="classes.php">Kelola Kelas Saya →</a>
            <div class="form-separator"></div>
            <div class="panel-title">Integritas Keanggotaan</div>
            <p class="panel-sub">Setiap kelompok hanya memiliki satu Project Manager aktif. Mahasiswa dibatasi maksimal satu kelompok per kelas.</p>
            <a class="btn secondary full-width" href="classroom.php">Buka Monitoring Kelas →</a>
        </aside>
    <?php endif; ?>
</div>

<?php layout_end(); ?>