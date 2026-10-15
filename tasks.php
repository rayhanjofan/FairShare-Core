<?php
require_once __DIR__.'/includes/bootstrap.php';$user=require_login();$error='';$teacher=is_teacher($user);$groups=visible_groups($user);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if($teacher){http_response_code(403);exit('Mode dosen bersifat pemantauan; perubahan tugas dilakukan oleh anggota kelompok.');}
    verify_csrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
        $title = trim($_POST['title'] ?? '');
        $gid = trim($_POST['group_id'] ?? '');
        $status = $_POST['status'] ?? 'Belum dikerjakan';
        $due = $_POST['due_date'] ?? '';
        $weight = (int)($_POST['weight'] ?? 1);
        $group = group_by_id($gid);
        if ($title === '' || !$group || !in_array($user['id'], $group['member_ids'], true)) {
            $error = 'Judul tugas dan kelompok yang valid wajib dipilih.';
        } else {
            $tasks = all_tasks();
            $tasks[] = ['id'=>bin2hex(random_bytes(5)), 'title'=>$title, 'group_id'=>$gid, 'group_name'=>$group['name'], 'created_by'=>$user['id'], 'assigned_user_id'=>$user['id'], 'status'=>$status, 'weight'=>max(1,min(5,$weight)), 'due_date'=>$due ?: 'Belum ditentukan', 'created_at'=>date('Y-m-d')];
            write_data('tasks.json', $tasks);
            log_activity('Tugas dibuat', $title, 'success');
            flash('Tugas berhasil ditambahkan ke WBS.');
            redirect('tasks.php');
        }
    } elseif ($action === 'update') {
        $id = $_POST['id'] ?? '';
        $status = $_POST['status'] ?? 'Belum dikerjakan';
        $allowed = ['Belum dikerjakan','Dikerjakan','Review','Selesai'];
        if (in_array($status, $allowed, true)) {
            $tasks = all_tasks();
            foreach ($tasks as &$task) {
                if (($task['id'] ?? '') === $id && in_array($task['group_id'] ?? '', array_column($groups, 'id'), true)) $task['status'] = $status;
            }
            unset($task);
            write_data('tasks.json', $tasks);
            log_activity('Status tugas diperbarui', 'Status tugas diubah menjadi '.$status);
            flash('Status tugas diperbarui.');
            redirect('tasks.php');
        }
    }
}
$tasks=$teacher?array_values(array_filter(all_tasks(),fn($t)=>in_array($t['group_id']??'',array_column($groups,'id'),true))):user_tasks((int)$user['id']);$filter=$_GET['status']??'';if($filter!=='')$tasks=array_values(array_filter($tasks,fn($t)=>$t['status']===$filter));layout_start('WBS & Tugas','tasks');
page_heading($teacher?'DOSEN / MONITORING':'WORKSPACE / WORK BREAKDOWN STRUCTURE','WBS & Tugas',$teacher?'Pantau status dan tenggat tugas dari kelompok yang tersedia.':'Pecah pekerjaan menjadi tugas yang terukur, dengan bobot, penanggung jawab, dan tenggat.',$teacher?'<a class="btn secondary" href="classroom.php">Kembali ke monitor</a>':'<a class="btn primary" href="#new-task">＋ Tambah tugas</a>');
$allUserTasks = $tasks;
echo '<div class="stats-grid three-stats"><article class="stat-card"><div class="stat-label">TOTAL TUGAS</div><div class="stat-number">'.count($allUserTasks).'</div><div class="stat-foot">Dalam workspace milikmu</div></article><article class="stat-card"><div class="stat-label">SEDANG DIKERJAKAN</div><div class="stat-number">'.count(array_filter($allUserTasks,fn($t)=>$t['status']==='Dikerjakan')).'</div><div class="stat-foot">Aktivitas berjalan</div></article><article class="stat-card"><div class="stat-label">SELESAI</div><div class="stat-number">'.count(array_filter($allUserTasks,fn($t)=>$t['status']==='Selesai')).'</div><div class="stat-foot">Tugas selesai dikerjakan</div></article></div>';
echo '<div class="kanban-filter"><span class="filter-caption">FILTER STATUS</span><a href="tasks.php">Semua</a><a href="tasks.php?status=Belum%20dikerjakan">Belum dikerjakan</a><a href="tasks.php?status=Dikerjakan">Dikerjakan</a><a href="tasks.php?status=Review">Review</a><a href="tasks.php?status=Selesai">Selesai</a></div><div class="task-board">';
$columns = ['Belum dikerjakan'=>'todo','Dikerjakan'=>'doing','Review'=>'review','Selesai'=>'done'];
foreach ($columns as $status => $cls) {
    $subset = array_values(array_filter($tasks, fn($t)=>$t['status']===$status));
    echo '<section class="kanban-column"><div class="kanban-head"><span class="kanban-dot '.$cls.'"></span><b>'.e($status).'</b><span class="kanban-count">'.count($subset).'</span></div>';
    foreach ($subset as $t) {
        $id = e($t['id']);
        echo '<article class="kanban-card"><div class="kanban-card-top"><span class="task-tag">'.e($t['weight']).'× bobot</span><span class="muted">···</span></div><h3>'.e($t['title']).'</h3><p>'.e($t['group_name']).'</p><div class="kanban-card-bottom"><span class="due-date">◷ '.e($t['due_date']).'</span>';if($teacher){echo '<span class="task-state">'.e($t['status']).'</span>';}else{echo '<form method="post" class="status-form"><input type="hidden" name="csrf" value="'.e(csrf_token()).'"><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="'.$id.'"><select name="status" aria-label="Ubah status" onchange="this.form.submit()">';foreach (array_keys($columns) as $s) echo '<option '.($s===$status?'selected':'').' value="'.e($s).'">'.e($s).'</option>';echo '</select></form>'; }echo '</div></article>';
    }
    if(!$teacher)echo '<a class="kanban-add" href="#new-task">＋ Tambah tugas</a>';echo '</section>';
}
echo '</div>';if(!$teacher){echo '<section class="panel task-create-panel" id="new-task"><div class="panel-heading"><div><div class="panel-title">Tambah tugas WBS</div><div class="panel-sub">Isi detail pekerjaan dan tentukan kelompoknya.</div></div></div>';
if (!$groups) {
    echo '<div class="flash error">Buat kelompok terlebih dahulu di halaman Tim & Kelompok.</div>';
} else {
    echo '<form method="post" class="form-grid"><input type="hidden" name="csrf" value="'.e(csrf_token()).'"><input type="hidden" name="action" value="create"><div class="field-wide"><label>Nama tugas</label><input name="title" required placeholder="Contoh: Rancang halaman dashboard"></div><div><label>Kelompok</label><select name="group_id" required>';
    foreach ($groups as $g) echo '<option value="'.e($g['id']).'">'.e($g['name']).'</option>';
    echo '</select></div><div><label>Bobot kesulitan (1–5)</label><select name="weight"><option>1</option><option>2</option><option>3</option><option>4</option><option>5</option></select></div><div><label>Status awal</label><select name="status"><option>Belum dikerjakan</option><option>Dikerjakan</option><option>Review</option><option>Selesai</option></select></div><div><label>Tenggat</label><input type="date" name="due_date"></div><div class="field-actions"><button class="btn primary">Simpan tugas →</button></div></form>';
}
echo '</section>'; }
layout_end();
