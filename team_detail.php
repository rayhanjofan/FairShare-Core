<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$user = require_login();
$teacher = is_teacher($user);
$teamId = trim((string) ($_GET['id'] ?? $_GET['team_id'] ?? ''));

if (!valid_uuid($teamId)) {
    flash('ID kelompok tidak valid.', 'error');
    redirect('teams.php');
}

// Pemeriksaan Otorisasi Server-Side
if (!fs_can_user_access_team($user, $teamId)) {
    http_response_code(403);
    layout_start('Akses Ditolak', 'teams');
    page_heading('403 FORBIDDEN', 'Akses Ditolak', 'Kamu tidak memiliki hak akses untuk melihat analitik tim ini.');
    echo '<section class="panel"><div class="empty-state"><b>Akses Terbatas</b><p>'
        . ($teacher
            ? 'Kelompok ini tidak berada di bawah kelas yang kamu kelola.'
            : 'Kamu hanya dapat mengakses kelompok tempat kamu terdaftar sebagai anggota.')
        . '</p><a class="btn primary" href="teams.php">Kembali ke Kelompok Saya</a></div></section>';
    layout_end();
    exit;
}

$analytics = fs_get_team_full_analytics($teamId);
if (!$analytics) {
    http_response_code(404);
    layout_start('Kelompok Tidak Ditemukan', 'teams');
    page_heading('WORKSPACE / TIM', 'Kelompok Tidak Ditemukan', 'Data kelompok tidak tersedia di database.');
    echo '<section class="panel"><div class="empty-state"><b>Kelompok Tidak Ditemukan</b><p>Periksa kembali tautan kelompok kamu.</p><a class="btn primary" href="teams.php">Kembali ke Kelompok Saya</a></div></section>';
    layout_end();
    exit;
}

$taskProgress = $analytics['task_progress'];
$tasks = fs_get_task_activity_summary($teamId);

layout_start('Analitik Tim · ' . $analytics['team_name'], 'teams');

$headingAction = '<div style="display:flex; gap:8px; align-items:center;">'
    . '<a class="btn secondary" href="tasks.php?group=' . urlencode($teamId) . '">Kelola Tugas WBS →</a>'
    . ($teacher ? '<a class="btn secondary" href="reports.php?class_id=' . urlencode($analytics['class_id']) . '">Laporan Kelas ↗</a>' : '')
    . '</div>';

page_heading(
    'ANALISIS / TEAM ANALYTICS',
    $analytics['team_name'],
    'Kelas: ' . $analytics['class_name'] . ($analytics['semester'] ? ' · Semester ' . $analytics['semester'] : '') . ($analytics['academic_year'] ? ' (' . $analytics['academic_year'] . ')' : '') . ' · Dosen: ' . ($analytics['lecturer_name'] ?: '—'),
    $headingAction
);
?>

<!-- RINGKASAN TIM: STATISTIK UTAMA DARI DATABASE -->
<div class="stats-grid four-stats" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:16px; margin-bottom:20px;">
    <article class="stat-card">
        <div class="stat-top">
            <span class="stat-label">ANGGOTA TIM</span>
            <span class="stat-icon blue">♧</span>
        </div>
        <div class="stat-number"><?= (int) $analytics['member_count'] ?><small> mahasiswa</small></div>
        <div class="stat-foot">
            <span class="pill blue-pill">Keanggotaan Aktif</span>
            <span class="muted"><?= e($analytics['team_name']) ?></span>
        </div>
    </article>

    <article class="stat-card">
        <div class="stat-top">
            <span class="stat-label">PROGRES TUGAS WBS</span>
            <span class="stat-icon green">✓</span>
        </div>
        <div class="stat-number"><?= number_format((float) ($taskProgress['completion_percentage'] ?? 0), 1) ?><small>%</small></div>
        <div class="stat-foot">
            <span class="pill green-pill"><?= (int) ($taskProgress['completed_tasks'] ?? 0) ?> / <?= (int) ($taskProgress['total_tasks'] ?? 0) ?> tugas</span>
            <span class="muted">selesai</span>
        </div>
        <div class="mini-progress green-progress" style="margin-top:8px;">
            <span style="width:<?= min(100, (float) ($taskProgress['completion_percentage'] ?? 0)) ?>%"></span>
        </div>
    </article>

    <article class="stat-card">
        <div class="stat-top">
            <span class="stat-label">FILTERED DELTA TIM</span>
            <span class="stat-icon violet">◈</span>
        </div>
        <div class="stat-number"><?= number_format((float) $analytics['total_filtered_delta'], 2) ?></div>
        <div class="stat-foot">
            <span class="pill purple">Skor Valid</span>
            <span class="muted">Raw: <?= number_format((float) $analytics['total_raw_delta'], 2) ?></span>
        </div>
    </article>

    <article class="stat-card">
        <div class="stat-top">
            <span class="stat-label">EVENT VALID / TOTAL</span>
            <span class="stat-icon orange">⌁</span>
        </div>
        <div class="stat-number"><?= (int) $analytics['total_valid_events'] ?><small> / <?= (int) $analytics['total_events'] ?></small></div>
        <div class="stat-foot">
            <span class="pill orange-pill"><?= (int) $analytics['total_pending_events'] ?> pending · <?= (int) $analytics['total_rejected_events'] ?> ditolak</span>
            <span class="muted">telemetry</span>
        </div>
    </article>
</div>

<!-- RINCIAN STATUS TUGAS DARI fn_task_progress -->
<div class="stats-grid four-stats" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap:12px; margin-bottom:24px;">
    <article class="stat-card" style="padding:14px 18px;">
        <span class="stat-label" style="font-size:11px;">BELUM DIKERJAKAN (TODO)</span>
        <div class="stat-number" style="font-size:22px; color:#64748b;"><?= (int) ($taskProgress['todo_tasks'] ?? 0) ?> <small style="font-size:12px;">tugas</small></div>
    </article>
    <article class="stat-card" style="padding:14px 18px;">
        <span class="stat-label" style="font-size:11px;">SEDANG DIKERJAKAN</span>
        <div class="stat-number" style="font-size:22px; color:#3b82f6;"><?= (int) ($taskProgress['in_progress_tasks'] ?? 0) ?> <small style="font-size:12px;">tugas</small></div>
    </article>
    <article class="stat-card" style="padding:14px 18px;">
        <span class="stat-label" style="font-size:11px;">DALAM REVIEW</span>
        <div class="stat-number" style="font-size:22px; color:#8b5cf6;"><?= (int) ($taskProgress['review_tasks'] ?? 0) ?> <small style="font-size:12px;">tugas</small></div>
    </article>
    <article class="stat-card" style="padding:14px 18px;">
        <span class="stat-label" style="font-size:11px;">SELESAI (DONE)</span>
        <div class="stat-number" style="font-size:22px; color:#10b981;"><?= (int) ($taskProgress['completed_tasks'] ?? 0) ?> <small style="font-size:12px;">tugas</small></div>
    </article>
</div>

<!-- TABEL KONTRIBUSI ANGGOTA DARI v_team_contribution_summary & fn_group_wei_summary -->
<section class="panel" style="margin-bottom:24px;">
    <div class="panel-heading">
        <div>
            <div class="panel-title">Tabel Kontribusi & Weighted Effort Index (WEI) Anggota</div>
            <div class="panel-sub">Perhitungan WEI resmi dihasilkan langsung oleh fungsi database PostgreSQL <code>fn_group_wei_summary()</code>.</div>
        </div>
        <span class="counter"><?= count($analytics['members']) ?> anggota</span>
    </div>

    <?php if (empty($analytics['members'])): ?>
        <div class="empty-state">
            <b>Belum ada anggota terdaftar</b>
            <p>Undang anggota tim dengan membagikan kode undangan kelompok.</p>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Nama Mahasiswa</th>
                        <th>NIM</th>
                        <th>Jabatan</th>
                        <th>Total Event</th>
                        <th>Google Docs</th>
                        <th>GitHub</th>
                        <th>Raw Delta</th>
                        <th>Filtered Delta</th>
                        <th>Aktivitas Valid</th>
                        <th>Indikator WEI</th>
                        <th>Status Aktivitas</th>
                        <th>Aktivitas Terakhir</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($analytics['members'] as $m): ?>
                        <tr>
                            <td>
                                <b><?= e($m['student_name']) ?></b>
                                <?php if ($m['role_in_group'] === 'ketua'): ?>
                                    <span class="pm-badge" style="display:inline-block; margin-left:6px; font-size:10px;">★ PM</span>
                                <?php endif; ?>
                            </td>
                            <td><?= e($m['nim_nip'] ?: '—') ?></td>
                            <td>
                                <span class="status-badge <?= $m['role_in_group'] === 'ketua' ? 'good' : '' ?>">
                                    <?= $m['role_in_group'] === 'ketua' ? 'Project Manager' : 'Anggota' ?>
                                </span>
                            </td>
                            <td><b><?= (int) $m['total_events'] ?></b></td>
                            <td><?= (int) $m['gdocs_events'] ?></td>
                            <td><?= (int) $m['github_events'] ?></td>
                            <td><?= number_format((float) $m['total_raw_delta'], 2) ?></td>
                            <td><b><?= number_format((float) $m['total_filtered_delta'], 2) ?></b></td>
                            <td>
                                <?php if ($m['valid_events'] > 0): ?>
                                    <span style="color:#10b981; font-weight:700;">✓ <?= (int) $m['valid_events'] ?></span>
                                <?php else: ?>
                                    <span style="color:#94a3b8;">0</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($analytics['has_sufficient_data']): ?>
                                    <div style="display:flex; align-items:center; gap:8px;">
                                        <div class="mini-progress" style="width:60px;">
                                            <span style="width:<?= min(100, (float) $m['wei_percentage']) ?>%"></span>
                                        </div>
                                        <b><?= number_format((float) $m['wei_percentage'], 1) ?>%</b>
                                    </div>
                                <?php else: ?>
                                    <span class="muted" style="font-size:11px;">Belum cukup data</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="status-badge <?= e($m['status_class']) ?>" style="font-size:10px;" title="<?= e($m['status_label']) ?>">
                                    <?= e($m['status_label']) ?>
                                </span>
                            </td>
                            <td>
                                <small class="muted">
                                    <?= !empty($m['last_activity_at']) ? date('d M Y H:i', strtotime((string) $m['last_activity_at'])) . ' WIB' : '—' ?>
                                </small>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div style="margin-top:14px; padding:12px 16px; background:#f8fafc; border-radius:8px; border:1px solid #e2e8f0; font-size:11px; color:#475569; line-height:1.5;">
            💡 <b>Catatan Integritas WEI:</b> Anggota yang belum mempunyai aktivitas valid tetap ditampilkan dengan status <i>Belum memiliki aktivitas tervalidasi</i>. WEI merupakan indikator distribusi aktivitas kolaboratif pada tugas terbobot kelompok, bukan penilaian mutlak atas usaha, durasi, maupun kualitas pengerjaan.
        </div>
    <?php endif; ?>
</section>

<!-- RINGKASAN TUGAS KELOMPOK DARI v_task_activity_summary -->
<section class="panel">
    <div class="panel-heading">
        <div>
            <div class="panel-title">Ringkasan Aktivitas Tugas WBS Kelompok</div>
            <div class="panel-sub">Data aktivitas telemetri dan status sinkronisasi diambil dari <code>v_task_activity_summary</code>.</div>
        </div>
        <span class="counter"><?= count($tasks) ?> tugas</span>
    </div>

    <?php if (empty($tasks)): ?>
        <div class="empty-state">
            <b>Belum ada tugas WBS</b>
            <p>Kelompok ini belum memiliki tugas. Buat tugas di halaman WBS Tugas.</p>
            <a class="btn primary" href="tasks.php?group=<?= urlencode($teamId) ?>#new-task">Buat Tugas Pertama</a>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Judul Tugas</th>
                        <th>Tipe</th>
                        <th>Bobot</th>
                        <th>Status</th>
                        <th>Minggu Target</th>
                        <th>Total Event</th>
                        <th>Docs / Git</th>
                        <th>Valid</th>
                        <th>Raw / Filtered</th>
                        <th>Anggota Teridentifikasi</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tasks as $t): ?>
                        <tr>
                            <td>
                                <b><?= e($t['task_title']) ?></b>
                                <?php if (!empty($t['resource_title'])): ?>
                                    <small class="table-secondary" title="<?= e($t['resource_url']) ?>">
                                        Resource: <?= e($t['resource_title']) ?>
                                    </small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="pill <?= $t['task_type'] === 'gdocs' ? 'blue-pill' : ($t['task_type'] === 'github' ? 'purple' : '') ?>" style="font-size:10px;">
                                    <?= e(strtoupper((string) $t['task_type'])) ?>
                                </span>
                            </td>
                            <td><b><?= (int) $t['weight'] ?>×</b></td>
                            <td>
                                <span class="status-badge <?= $t['task_status'] === 'done' ? 'good' : ($t['task_status'] === 'in_progress' ? 'waiting' : '') ?>">
                                    <?= e(ui_task_status((string) $t['task_status'])) ?>
                                </span>
                            </td>
                            <td>Mg-<?= (int) $t['target_week'] ?></td>
                            <td><b><?= (int) $t['total_events'] ?></b></td>
                            <td>
                                <small>Docs: <?= (int) $t['gdocs_events'] ?> | Git: <?= (int) $t['github_events'] ?></small>
                            </td>
                            <td>
                                <span style="color:#10b981; font-weight:700;">✓ <?= (int) $t['valid_events'] ?></span>
                            </td>
                            <td>
                                <?= number_format((float) $t['total_raw_delta'], 1) ?> / <b><?= number_format((float) $t['total_filtered_delta'], 1) ?></b>
                            </td>
                            <td><?= (int) $t['identified_members'] ?> orang</td>
                            <td>
                                <a class="btn secondary small" href="task_detail.php?id=<?= urlencode((string) $t['task_id']) ?>">
                                    Detail Tugas →
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php layout_end(); ?>
