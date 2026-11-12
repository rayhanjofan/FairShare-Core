<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
$user = require_login();
$userId = (string) $user['id'];
$groups = visible_groups($user);
$items = all_telemetry($userId);

// Hitung metrik validasi dan delta
$validCount = 0;
$pendingCount = 0;
$rejectedCount = 0;
$manualCount = 0;
$totalRawDelta = 0.0;
$totalFilteredDelta = 0.0;

foreach ($items as $item) {
    $meta = $item['metadata'] ?? [];
    $entryMode = (string) ($meta['entry_mode'] ?? '');
    $valStatus = strtolower(trim((string) ($meta['validation_status'] ?? '')));

    if ($entryMode === 'manual_test') {
        $manualCount++;
    } else {
        $totalRawDelta += (float) ($item['raw_delta'] ?? 1.0);
        if ($valStatus === 'valid' || $valStatus === 'terverifikasi') {
            $validCount++;
            $totalFilteredDelta += (float) ($item['filtered_delta'] ?? 0.0);
        } elseif ($valStatus === 'pending' || $valStatus === 'menunggu kalibrasi bobot') {
            $pendingCount++;
        } else {
            $rejectedCount++;
        }
    }
}

// Hitung WEI overview pengguna
$weiOverview = fs_member_wei_overview($userId);

$q = trim((string) ($_GET['q'] ?? ''));
if ($q !== '') {
    $items = array_values(array_filter($items, fn($l) => stripos(($l['title'] ?? '') . ' ' . ($l['source'] ?? ''), $q) !== false));
}

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="fairshare-kontribusi-' . date('Ymd-His') . '.csv"');
    $o = fopen('php://output', 'w');
    fputcsv($o, ['Aktivitas', 'Kelompok', 'Sumber', 'Tanggal', 'Raw Delta', 'Filtered Delta', 'Status Validasi', 'Detail']);
    foreach ($items as $i) {
        fputcsv($o, [
            $i['title'],
            $i['group_name'] ?? '',
            strtoupper((string) $i['source']),
            $i['date'],
            number_format((float) ($i['raw_delta'] ?? 0), 2),
            number_format((float) ($i['filtered_delta'] ?? 0), 2),
            $i['status'],
            $i['detail'] ?? '',
        ]);
    }
    fclose($o);
    exit;
}

layout_start('Kontribusi Saya', 'contributions');
page_heading(
    'FAIRSHARE / KONTRIBUSI',
    'Kontribusi & Indeks Usaha Saya',
    'Pantau validasi aktivitas pengerjaan, Raw Delta, Filtered Delta, dan Weighted Effort Index (WEI).',
    button('↓ Ekspor CSV', 'contributions.php?export=csv', 'secondary')
);
?>

<div class="stats-grid four-stats" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:16px; margin-bottom:24px;">
    <article class="stat-card">
        <div class="stat-top">
            <span class="stat-label">WEIGHTED EFFORT INDEX</span>
            <span class="stat-icon violet">◈</span>
        </div>
        <div class="stat-number">
            <?php if ($weiOverview['has_sufficient_data']): ?>
                <?= number_format((float) $weiOverview['overall_wei'], 1) ?><small>%</small>
            <?php else: ?>
                <span style="font-size:18px; font-weight:700; color:#64748b;">Belum cukup data</span>
            <?php endif; ?>
        </div>
        <div class="stat-foot">
            <span class="pill <?= $weiOverview['has_sufficient_data'] ? 'purple' : 'waiting' ?>">
                <?= $weiOverview['has_sufficient_data'] ? 'Tervalidasi Resmi' : 'Menunggu Aktivitas' ?>
            </span>
            <span class="muted">Berdasarkan bobot tugas tim</span>
        </div>
    </article>

    <article class="stat-card">
        <div class="stat-top">
            <span class="stat-label">FILTERED DELTA</span>
            <span class="stat-icon green">✓</span>
        </div>
        <div class="stat-number">
            <?= number_format($totalFilteredDelta, 2) ?><small> delta</small>
        </div>
        <div class="stat-foot">
            <span class="pill green-pill">Lolos Validasi</span>
            <span class="muted"><?= $validCount ?> event tervalidasi</span>
        </div>
    </article>

    <article class="stat-card">
        <div class="stat-top">
            <span class="stat-label">RAW DELTA</span>
            <span class="stat-icon blue">⌁</span>
        </div>
        <div class="stat-number">
            <?= number_format($totalRawDelta, 2) ?><small> raw</small>
        </div>
        <div class="stat-foot">
            <span class="pill blue-pill">Total Terdeteksi</span>
            <span class="muted">Dari Google Docs / GitHub</span>
        </div>
    </article>

    <article class="stat-card">
        <div class="stat-top">
            <span class="stat-label">STATUS AUDIT AKTIVITAS</span>
            <span class="stat-icon orange">⚖</span>
        </div>
        <div class="stat-number" style="font-size:16px; line-height:1.4; padding-top:4px;">
            <span style="color:#10b981; font-weight:800;">✓ <?= $validCount ?> Valid</span> · 
            <span style="color:#f59e0b; font-weight:800;">⏳ <?= $pendingCount ?> Pending</span><br>
            <span style="color:#ef4444; font-weight:800;">✕ <?= $rejectedCount ?> Ditolak</span> · 
            <span style="color:#64748b; font-weight:700;">⚙ <?= $manualCount ?> Uji</span>
        </div>
        <div class="stat-foot">
            <span class="muted">Rincian status audit transparan</span>
        </div>
    </article>
</div>

<!-- PANEL PERBANDINGAN KONTRIBUSI KELOMPOK -->
<?php if ($groups): ?>
    <section class="panel" style="margin-bottom:24px;">
        <div class="panel-heading">
            <div>
                <div class="panel-title">Perbandingan Kontribusi Anggota Kelompok</div>
                <div class="panel-sub">Distribusi kontribusi relatif seluruh anggota berdasarkan tugas berbobot kelompok.</div>
            </div>
            <span class="counter"><?= count($groups) ?> kelompok aktif</span>
        </div>

        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap:20px; padding:8px 0;">
            <?php foreach ($groups as $grp): ?>
                <?php
                $gid = (string) $grp['id'];
                $gWei = fs_calculate_group_wei($gid);
                $hasData = $gWei['has_sufficient_data'];
                ?>
                <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:16px;">
                    <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:12px;">
                        <div>
                            <b style="font-size:14px; color:#1e293b;"><?= e($grp['name']) ?></b>
                            <div style="font-size:11px; color:#64748b; margin-top:2px;">
                                <?= $gWei['valid_tasks_count'] ?> tugas aktif dinilai (Total bobot: <?= $gWei['total_evaluated_weight'] ?>)
                            </div>
                        </div>
                        <span class="status-badge <?= $hasData ? 'good' : 'waiting' ?>" style="font-size:10px;">
                            <?= $hasData ? 'Data Cukup' : 'Belum Cukup Data' ?>
                        </span>
                    </div>

                    <?php if (!$hasData): ?>
                        <div style="padding:14px; text-align:center; background:#fff; border-radius:8px; border:1px dashed #cbd5e1; font-size:12px; color:#64748b;">
                            Belum ada aktivitas Google Docs tervalidasi pada tugas kelompok ini.
                        </div>
                    <?php else: ?>
                        <div style="display:flex; flex-direction:column; gap:10px;">
                            <?php foreach ($gWei['members'] as $mUid => $mInfo): ?>
                                <?php
                                $isMe = ($mUid === $userId);
                                $weiPct = $mInfo['wei_percent'] !== null ? round((float) $mInfo['wei_percent'], 1) : 0.0;
                                ?>
                                <div style="background:#fff; border:1px solid <?= $isMe ? '#818cf8' : '#e2e8f0' ?>; border-radius:8px; padding:10px 12px; <?= $isMe ? 'box-shadow:0 1px 3px rgba(99,102,241,0.1);' : '' ?>">
                                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                                        <div>
                                            <b style="font-size:12px; color:<?= $isMe ? '#4338ca' : '#1e293b' ?>;">
                                                <?= e($mInfo['name']) ?> <?= $isMe ? '(Saya)' : '' ?>
                                            </b>
                                            <span style="font-size:10px; color:#64748b; margin-left:4px;">
                                                <?= $mInfo['role_in_group'] === 'ketua' ? '👑 PM' : 'Anggota' ?>
                                            </span>
                                        </div>
                                        <b style="font-size:13px; color:<?= $isMe ? '#4338ca' : '#0f172a' ?>;">
                                            <?= number_format($weiPct, 1) ?>%
                                        </b>
                                    </div>
                                    <div class="mini-progress" style="height:6px; background:#e2e8f0;">
                                        <span style="width:<?= min(100, $weiPct) ?>%; background:<?= $isMe ? '#6366f1' : '#10b981' ?>;"></span>
                                    </div>
                                    <div style="display:flex; justify-content:space-between; margin-top:4px; font-size:10px; color:#64748b;">
                                        <span>Filtered: <b><?= number_format($mInfo['filtered_delta_total'], 1) ?></b></span>
                                        <span>Raw: <b><?= number_format($mInfo['raw_delta_total'], 1) ?></b></span>
                                        <span>Valid: <b><?= $mInfo['valid_events_count'] ?></b> event</span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>

<!-- RIWAYAT AKTIVITAS & AUDIT TELEMETRI -->
<section class="panel">
    <div class="panel-heading">
        <div>
            <div class="panel-title">Riwayat Kontribusi & Validasi Telemetri</div>
            <div class="panel-sub">Transparansi status validasi, Raw Delta, dan Filtered Delta dari PostgreSQL/Supabase</div>
        </div>
        <form class="search-form" method="get">
            <input name="q" value="<?= e($q) ?>" placeholder="Cari aktivitas atau sumber...">
            <button class="btn secondary">Cari</button>
        </form>
    </div>

    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Aktivitas</th>
                    <th>Kelompok</th>
                    <th>Sumber</th>
                    <th>Waktu</th>
                    <th>Raw Delta</th>
                    <th>Filtered Delta</th>
                    <th>Status Validasi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$items): ?>
                    <tr>
                        <td colspan="7">
                            <div class="empty-state">
                                <span class="empty-illustration">◈</span>
                                <b>Belum ada telemetri tercatat</b>
                                <p>Aktivitas Google Docs akan muncul setelah dokumen dihubungkan ke tugas dan disinkronkan.</p>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($items as $i): ?>
                        <?php
                        $valStatus = $i['validation_status'] ?? '';
                        $badgeClass = 'waiting';
                        if ($valStatus === 'valid' || $valStatus === 'terverifikasi') {
                            $badgeClass = 'good';
                        } elseif ($valStatus === 'rejected' || $valStatus === 'duplicate') {
                            $badgeClass = 'danger';
                        } elseif (($i['metadata']['entry_mode'] ?? '') === 'manual_test') {
                            $badgeClass = '';
                        }
                        ?>
                        <tr>
                            <td>
                                <div class="table-main">
                                    <span class="table-icon">◈</span>
                                    <div>
                                        <b><?= e($i['title']) ?></b>
                                        <?php if (!empty($i['detail'])): ?>
                                            <div style="font-size:11px; color:#64748b; margin-top:2px;"><?= e($i['detail']) ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td><?= e($i['group_name'] ?? '—') ?></td>
                            <td>
                                <span class="source-label" style="text-transform:uppercase; font-size:10px;">
                                    <?= e($i['source']) ?>
                                </span>
                            </td>
                            <td><small><?= e(format_date($i['date'])) ?></small></td>
                            <td><?= number_format((float) ($i['raw_delta'] ?? 1.0), 2) ?></td>
                            <td><b><?= number_format((float) ($i['filtered_delta'] ?? 0.0), 2) ?></b></td>
                            <td>
                                <span class="status-badge <?= $badgeClass ?>" style="font-size:11px;">
                                    <?= e($i['status']) ?>
                                </span>
                                <?php if (!empty($i['validation_reason'])): ?>
                                    <div style="font-size:10px; color:#ef4444; margin-top:2px;">
                                        <?= e(fs_validation_reason_text($i['validation_reason'])) ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<!-- PANEL SIMULASI UJI MANUAL (TIDAK MEMENGARUHI WEI RESMI) -->
<section class="panel task-create-panel" style="margin-top:24px;">
    <div class="panel-heading">
        <div>
            <div class="panel-title">Catatan Telemetri Uji (Simulasi Manual)</div>
            <div class="panel-sub">Gunakan hanya untuk menguji penyimpanan database Supabase. Catatan manual ini diberi tanda khusus dan <b>tidak diperhitungkan dalam evaluasi skor WEI resmi</b>.</div>
        </div>
        <span class="status-badge waiting">Uji Mandiri</span>
    </div>

    <?php if (!$groups): ?>
        <div class="flash error">Buat atau gabung kelompok sebelum mencatat aktivitas uji.</div>
    <?php else: ?>
        <form method="post" action="actions/add_telemetry.php" class="form-grid">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <div class="field-wide">
                <label>Deskripsi aktivitas uji <span class="text-danger">*</span></label>
                <input name="title" maxlength="50" required placeholder="Contoh: Pengujian koneksi Supabase">
            </div>
            <div>
                <label>Kelompok <span class="text-danger">*</span></label>
                <select name="group_id" required>
                    <?php foreach ($groups as $g): ?>
                        <option value="<?= e($g['id']) ?>"><?= e($g['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Jenis sumber yang disimulasikan</label>
                <select name="source">
                    <option value="gdocs">Google Docs</option>
                    <option value="github">GitHub</option>
                </select>
            </div>
            <div>
                <label>Delta uji (0–100)</label>
                <input type="number" name="points" min="0" max="100" step="0.01" value="1" required>
            </div>
            <div class="field-actions">
                <button class="btn secondary" type="submit">Simpan Catatan Uji Manual →</button>
            </div>
        </form>
    <?php endif; ?>
</section>

<div style="margin-top:20px; padding:14px 18px; background:#f8fafc; border-radius:10px; border:1px solid #e2e8f0; font-size:12px; color:#475569; line-height:1.6;">
    ⚖ <b>Batasan Penilaian FairShare:</b> Weighted Effort Index (WEI) adalah indikator proporsi kontribusi aktivitas kolaboratif yang berhasil dikumpulkan dan divalidasi FairShare, bukan ukuran mutlak kualitas pekerjaan maupun nilai akademik otomatis mahasiswa.
</div>

<?php layout_end(); ?>
