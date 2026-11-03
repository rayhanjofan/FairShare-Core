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

        if ($action === 'create_class') {
            if (!$teacher) {
                http_response_code(403);
                exit('Hanya dosen yang dapat membuat kelas.');
            }

            $name = trim((string) ($_POST['name'] ?? ''));
            $desc = trim((string) ($_POST['description'] ?? ''));
            $semester = trim((string) ($_POST['semester'] ?? 'Genap'));
            $academicYear = trim((string) ($_POST['academic_year'] ?? '2025/2026'));
            $durationWeeks = max(1, (int) ($_POST['duration_weeks'] ?? 16));

            if ($name === '') {
                $error = 'Nama kelas wajib diisi.';
            } elseif (mb_strlen($name) > 150) {
                $error = 'Nama kelas maksimal 150 karakter.';
            } else {
                // Buat prefix kode dari singkatan nama kelas
                $words = preg_split('/\s+/', $name) ?: [];
                $prefix = '';
                if (count($words) >= 2) {
                    foreach (array_slice($words, 0, 3) as $w) {
                        $prefix .= mb_substr($w, 0, 1);
                    }
                } else {
                    $prefix = mb_substr($name, 0, 3);
                }

                $inviteCode = generate_unique_invite_code($pdo, $prefix ?: 'CLS');

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
                        NULL,
                        :duration_weeks,
                        NULL,
                        NULL,
                        CURRENT_TIMESTAMP,
                        CURRENT_TIMESTAMP,
                        'class',
                        NULL,
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
                    'duration_weeks' => $durationWeeks,
                    'invite_code'    => $inviteCode,
                    'description'    => $desc !== '' ? $desc : null,
                    'semester'       => $semester !== '' ? $semester : null,
                    'academic_year'  => $academicYear !== '' ? $academicYear : null,
                ]);

                $classId = (string) $stmt->fetchColumn();

                log_activity('Kelas baru dibuat', "Kelas $name ($inviteCode)", 'success');
                flash("Kelas <b>" . e($name) . "</b> berhasil dibuat! Bagikan kode undangan: <b>" . e($inviteCode) . "</b>");
                redirect('class_detail.php?id=' . urlencode($classId));
            }
        } elseif ($action === 'join_class') {
            if ($teacher) {
                http_response_code(403);
                exit('Dosen tidak dapat bergabung ke kelas mahasiswa.');
            }

            $rawCode = strtoupper(trim((string) ($_POST['invite_code'] ?? '')));
            if ($rawCode === '') {
                $error = 'Kode undangan kelas wajib diisi.';
            } else {
                $find = $pdo->prepare("
                    SELECT id::text AS id, name, group_type
                    FROM public.groups
                    WHERE UPPER(invite_code) = :code
                    LIMIT 1
                ");
                $find->execute(['code' => $rawCode]);
                $targetGroup = $find->fetch(PDO::FETCH_ASSOC);

                if (!$targetGroup) {
                    $error = 'Kode undangan tidak ditemukan. Periksa kembali kode yang diberikan dosen.';
                } elseif ($targetGroup['group_type'] !== 'class') {
                    $error = 'Kode tersebut merupakan kode kelompok, bukan kode kelas. Gunakan halaman Kelompok Saya untuk bergabung ke kelompok.';
                } else {
                    $classId = (string) $targetGroup['id'];

                    // Cek apakah sudah terdaftar
                    $check = $pdo->prepare("
                        SELECT 1 FROM public.group_members
                        WHERE group_id = CAST(:gid AS uuid) AND user_id = CAST(:uid AS uuid)
                        LIMIT 1
                    ");
                    $check->execute(['gid' => $classId, 'uid' => (string) $user['id']]);

                    if ($check->fetch()) {
                        $error = 'Kamu sudah terdaftar di kelas ' . $targetGroup['name'] . '.';
                    } else {
                        // Daftarkan sebagai anggota kelas
                        $join = $pdo->prepare("
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
                        $join->execute([
                            'gid' => $classId,
                            'uid' => (string) $user['id'],
                        ]);

                        log_activity('Bergabung ke kelas', "Bergabung ke {$targetGroup['name']}", 'success');
                        flash("Selamat! Kamu berhasil bergabung ke kelas <b>" . e($targetGroup['name']) . "</b>.");
                        redirect('class_detail.php?id=' . urlencode($classId));
                    }
                }
            }
        }
    } catch (PDOException $e) {
        error_log('FairShare classes SQLSTATE ' . $e->getCode() . ': ' . $e->getMessage());
        $error = 'Gagal memproses data kelas: ' . $e->getMessage();
    } catch (Throwable $e) {
        error_log('FairShare classes error: ' . $e->getMessage());
        $error = 'Terjadi kesalahan sistem. Silakan coba beberapa saat lagi.';
    }
}

$classes = visible_classes($user);

layout_start('Kelas Saya', 'classes');

$actionButton = $teacher
    ? '<button class="btn primary" type="button" data-modal-open="createClassModal">＋ Buat Kelas</button>'
    : '<button class="btn primary" type="button" data-modal-open="joinClassModal">＋ Gabung Kelas</button>';

page_heading(
    $teacher ? 'DOSEN / PENGELOLAAN KELAS' : 'MAHASISWA / KELAS SAYA',
    'Kelas Saya',
    $teacher ? 'Kelola kelas akademik, bagikan kode undangan, dan bentuk kelompok kolaboratif.' : 'Daftar kelas perkuliahan yang kamu ikuti untuk kolaborasi dan pemantauan tugas.',
    $actionButton
);

if ($error) {
    echo '<div class="flash error">' . e($error) . '</div>';
}
?>

<div class="stats-grid three-stats">
    <article class="stat-card">
        <div class="stat-label"><?= $teacher ? 'TOTAL KELAS DIAJAR' : 'KELAS YANG DIIKUTI' ?></div>
        <div class="stat-number"><?= count($classes) ?></div>
        <div class="stat-foot"><?= $teacher ? 'Kelas aktif di Supabase' : 'Kelas perkuliahan aktif' ?></div>
    </article>
    <article class="stat-card">
        <div class="stat-label"><?= $teacher ? 'TOTAL MAHASISWA' : 'TOTAL KELOMPOK' ?></div>
        <div class="stat-number">
            <?= $teacher
                ? array_sum(array_map(fn($c) => (int) ($c['student_count'] ?? 0), $classes))
                : array_sum(array_map(fn($c) => (int) ($c['team_count'] ?? 0), $classes))
            ?>
        </div>
        <div class="stat-foot"><?= $teacher ? 'Mahasiswa terdaftar di seluruh kelas' : 'Kelompok terbentuk di seluruh kelas' ?></div>
    </article>
    <article class="stat-card">
        <div class="stat-label">STATUS AKSES</div>
        <div class="stat-number"><small><?= $teacher ? 'DOSEN PENGAMPU' : 'MAHASISWA' ?></small></div>
        <div class="stat-foot">Data terisolasi secara aman per kelas</div>
    </article>
</div>

<div class="panel">
    <div class="panel-heading">
        <div>
            <div class="panel-title"><?= $teacher ? 'Daftar Kelas Milik Saya' : 'Kelas Terdaftar' ?></div>
            <div class="panel-sub">
                <?= $teacher ? 'Hanya kelas yang kamu buat yang tampil di sini.' : 'Kamu dapat mengakses kelompok dan tugas di setiap kelas yang diikuti.' ?>
            </div>
        </div>
        <span class="counter"><?= count($classes) ?> kelas</span>
    </div>

    <?php if (!$classes): ?>
        <div class="empty-state">
            <span class="empty-illustration">▤</span>
            <b>Belum ada kelas terdaftar</b>
            <p>
                <?= $teacher
                    ? 'Klik tombol "+ Buat Kelas" untuk membuat kelas perkuliahan baru.'
                    : 'Klik tombol "+ Gabung Kelas" dan masukkan kode undangan yang diberikan dosen pengampu.'
                ?>
            </p>
        </div>
    <?php else: ?>
        <div class="classes-grid">
            <?php foreach ($classes as $c): ?>
                <article class="class-card">
                    <div class="class-card-header">
                        <div class="class-card-badge">
                            <span class="pill purple"><?= e($c['semester'] ?? 'Semester') ?></span>
                            <span class="pill gray"><?= e($c['academic_year'] ?? '2025/2026') ?></span>
                        </div>
                        <?php if ($teacher): ?>
                            <span class="status-badge good">Dosen Pengampu</span>
                        <?php else: ?>
                            <span class="status-badge">Dosen: <?= e($c['lecturer_name'] ?? 'Dosen') ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="class-card-body">
                        <h3 class="class-title">
                            <a href="class_detail.php?id=<?= urlencode($c['id']) ?>">
                                <?= e($c['name']) ?>
                            </a>
                        </h3>
                        <p class="class-desc">
                            <?= e($c['description'] ?: 'Tidak ada deskripsi kelas.') ?>
                        </p>

                        <div class="class-invite-box">
                            <div class="class-invite-label">KODE KELAS</div>
                            <div class="class-invite-code">
                                <code><?= e($c['invite_code']) ?></code>
                                <button
                                    class="btn secondary small"
                                    type="button"
                                    data-copy="<?= e($c['invite_code']) ?>"
                                    data-copy-message="Kode kelas <?= e($c['invite_code']) ?> disalin!"
                                    title="Salin kode kelas">
                                    ⧉ Salin Kode
                                </button>
                            </div>
                        </div>

                        <div class="class-stats-row">
                            <div class="class-stat-item">
                                <span class="class-stat-num"><?= (int) ($c['student_count'] ?? 0) ?></span>
                                <span class="class-stat-lbl">Mahasiswa</span>
                            </div>
                            <div class="class-stat-item">
                                <span class="class-stat-num"><?= (int) ($c['team_count'] ?? 0) ?></span>
                                <span class="class-stat-lbl">Kelompok</span>
                            </div>
                            <div class="class-stat-item">
                                <span class="class-stat-num"><?= (int) ($c['task_count'] ?? 0) ?></span>
                                <span class="class-stat-lbl">Tugas WBS</span>
                            </div>
                        </div>
                    </div>

                    <div class="class-card-footer">
                        <a class="btn primary full-width" href="class_detail.php?id=<?= urlencode($c['id']) ?>">
                            Buka Kelas →
                        </a>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- MODAL BUAT KELAS (DOSEN) -->
<?php if ($teacher): ?>
<div class="modal-overlay" id="createClassModal" hidden>
    <div class="modal-box">
        <div class="modal-head">
            <div>
                <h3>Buat Kelas Perkuliahan Baru</h3>
                <small>Sistem otomatis menghasilkan kode undangan unik untuk dibagikan ke mahasiswa.</small>
            </div>
            <button class="modal-close" type="button" data-modal-close="createClassModal">✕</button>
        </div>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="create_class">
            <div class="modal-body">
                <div class="form-group">
                    <label>Nama Kelas <span class="text-danger">*</span></label>
                    <input name="name" required maxlength="150" placeholder="Contoh: Pemrograman Berbasis Objek (TI-2A)">
                </div>
                <div class="form-group">
                    <label>Deskripsi Kelas</label>
                    <textarea name="description" rows="3" placeholder="Deskripsi mata kuliah, silabus singkat, atau instruksi..."></textarea>
                </div>
                <div class="form-row-2">
                    <div class="form-group">
                        <label>Semester</label>
                        <select name="semester">
                            <option value="Genap" selected>Genap</option>
                            <option value="Ganjil">Ganjil</option>
                            <option value="Pendek">Antara / Pendek</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Tahun Akademik</label>
                        <input name="academic_year" value="2025/2026" placeholder="Contoh: 2025/2026">
                    </div>
                </div>
                <div class="form-group">
                    <label>Durasi Perkuliahan (Minggu)</label>
                    <input type="number" name="duration_weeks" min="1" max="52" value="16" required>
                </div>
            </div>
            <div class="modal-foot">
                <button class="btn secondary" type="button" data-modal-close="createClassModal">Batal</button>
                <button class="btn primary" type="submit">＋ Simpan & Buat Kelas</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- MODAL GABUNG KELAS (MAHASISWA) -->
<?php if (!$teacher): ?>
<div class="modal-overlay" id="joinClassModal" hidden>
    <div class="modal-box">
        <div class="modal-head">
            <div>
                <h3>Gabung ke Kelas Perkuliahan</h3>
                <small>Masukkan kode undangan unik yang kamu terima dari dosen pengampu.</small>
            </div>
            <button class="modal-close" type="button" data-modal-close="joinClassModal">✕</button>
        </div>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="join_class">
            <div class="modal-body">
                <div class="form-group">
                    <label>Kode Undangan Kelas <span class="text-danger">*</span></label>
                    <input
                        name="invite_code"
                        required
                        maxlength="20"
                        style="text-transform:uppercase; font-weight:700; letter-spacing:0.05em;"
                        placeholder="Contoh: PBO-A8K29X">
                    <small class="muted" style="display:block; margin-top:6px;">
                        Pastikan menggunakan kode kelas dari dosen pengampu, bukan kode kelompok.
                    </small>
                </div>
            </div>
            <div class="modal-foot">
                <button class="btn secondary" type="button" data-modal-close="joinClassModal">Batal</button>
                <button class="btn primary" type="submit">Gabung Kelas →</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
document.addEventListener("DOMContentLoaded", function() {
    document.querySelectorAll("[data-modal-open]").forEach(function(btn) {
        btn.addEventListener("click", function() {
            var modalId = btn.getAttribute("data-modal-open");
            var target = document.getElementById(modalId);
            if (target) {
                target.hidden = false;
            }
        });
    });

    document.querySelectorAll("[data-modal-close]").forEach(function(btn) {
        btn.addEventListener("click", function() {
            var modalId = btn.getAttribute("data-modal-close");
            var target = document.getElementById(modalId);
            if (target) {
                target.hidden = true;
            }
        });
    });

    document.querySelectorAll(".modal-overlay").forEach(function(overlay) {
        overlay.addEventListener("click", function(e) {
            if (e.target === overlay) {
                overlay.hidden = true;
            }
        });
    });
});
</script>

<?php layout_end(); ?>

