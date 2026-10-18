<?php
require_once __DIR__.'/includes/bootstrap.php';
$user=require_login(); $error=''; $teacher=is_teacher($user); $groups=visible_groups($user);
if($_SERVER['REQUEST_METHOD']==='POST'){
    if($teacher){http_response_code(403);exit('Mode dosen bersifat pemantauan; perubahan tugas dilakukan oleh anggota kelompok.');}
    verify_csrf(); $action=(string)($_POST['action']??'');
    try {
        $pdo=require_db();
        if($action==='create'){
            $title=trim((string)($_POST['title']??'')); $gid=(string)($_POST['group_id']??'');
            $status=db_task_status((string)($_POST['status']??'Belum dikerjakan'));
            $targetWeek=max(1,(int)($_POST['target_week']??1)); $weight=max(1,min(5,(int)($_POST['weight']??1)));
            $group=group_by_id($gid);
            $isMember=$group && (in_array(strtolower((string)$user['id']),array_map('strtolower',$group['member_ids']??[]),true) || strtolower((string)$group['created_by'])===strtolower((string)$user['id']));
            if($title==='' || mb_strlen($title)>200 || !$isMember) $error='Judul tugas valid dan kelompok yang kamu ikuti wajib dipilih.';
            else {
                $stmt=$pdo->prepare("INSERT INTO public.tasks (group_id,assigned_user_id,title,weight,status,target_week,completed_at) VALUES (CAST(:gid AS uuid),CAST(:uid AS uuid),:title,:weight,:status,:target_week,CASE WHEN :status_done='done' THEN CURRENT_TIMESTAMP ELSE NULL END)");
                $stmt->execute(['gid'=>$gid,'uid'=>$user['id'],'title'=>$title,'weight'=>$weight,'status'=>$status,'target_week'=>$targetWeek,'status_done'=>$status]);
                log_activity('Tugas dibuat',$title,'success'); flash('Tugas berhasil ditambahkan dan disimpan ke Supabase.'); redirect('tasks.php');
            }
        } elseif($action==='update'){
            $id=(string)($_POST['id']??''); $status=db_task_status((string)($_POST['status']??'Belum dikerjakan'));
            if(!valid_uuid($id)) $error='ID tugas tidak valid.';
            else {
                $taskStmt=$pdo->prepare('SELECT id::text AS id,group_id::text AS group_id FROM public.tasks WHERE id=CAST(:id AS uuid)');
                $taskStmt->execute(['id'=>$id]); $task=$taskStmt->fetch();
                $allowedGroups=array_column($groups,'id');
                if(!$task || !in_array($task['group_id'],$allowedGroups,true)) $error='Tugas tidak ditemukan atau kamu tidak memiliki akses ke kelompok ini.';
                else {
                    $stmt=$pdo->prepare("UPDATE public.tasks SET status=:status, completed_at=CASE WHEN :status_done='done' THEN COALESCE(completed_at,CURRENT_TIMESTAMP) ELSE NULL END, updated_at=CURRENT_TIMESTAMP WHERE id=CAST(:id AS uuid)");
                    $stmt->execute(['status'=>$status,'status_done'=>$status,'id'=>$id]);
                    log_activity('Status tugas diperbarui','Status tugas diubah menjadi '.ui_task_status($status),'success'); flash('Status tugas diperbarui di Supabase.'); redirect('tasks.php');
                }
            }
        }
    } catch(PDOException $e){ error_log('FairShare tasks SQLSTATE '.$e->getCode().': '.$e->getMessage()); $error='Tugas gagal disimpan. Periksa data dan izin database.'; }
      catch(Throwable $e){ error_log('FairShare tasks: '.$e->getMessage()); $error='Database Supabase tidak dapat diakses. Periksa data/.env.'; }
}
$groups=visible_groups($user); $allTasks=all_tasks(); $groupIds=array_column($groups,'id');
$tasks=$teacher?array_values(array_filter($allTasks,fn($t)=>in_array($t['group_id']??'',$groupIds,true))):user_tasks((string)$user['id']);
$requestedGroup=(string)($_GET['group']??''); if($requestedGroup!=='' && valid_uuid($requestedGroup)) $tasks=array_values(array_filter($tasks,fn($t)=>strtolower((string)$t['group_id'])===strtolower($requestedGroup)));
$filter=(string)($_GET['status']??''); if($filter!=='')$tasks=array_values(array_filter($tasks,fn($t)=>($t['status']??'')===$filter));
layout_start('WBS & Tugas','tasks'); page_heading($teacher?'DOSEN / MONITORING':'WORKSPACE / WORK BREAKDOWN STRUCTURE','WBS & Tugas',$teacher?'Pantau status dan minggu target tugas dari kelompok yang tersedia.':'Pecah pekerjaan menjadi tugas yang terukur, dengan bobot, penanggung jawab, dan target minggu.',$teacher?'<a class="btn secondary" href="classroom.php">Kembali ke monitor</a>':'<a class="btn primary" href="#new-task">＋ Tambah tugas</a>');
if($error) echo '<div class="flash error">'.e($error).'</div>';
$allUserTasks=$tasks;
echo '<div class="stats-grid three-stats"><article class="stat-card"><div class="stat-label">TOTAL TUGAS</div><div class="stat-number">'.count($allUserTasks).'</div><div class="stat-foot">Dalam workspace yang terlihat</div></article><article class="stat-card"><div class="stat-label">SEDANG DIKERJAKAN</div><div class="stat-number">'.count(array_filter($allUserTasks,fn($t)=>$t['status']==='Dikerjakan')).'</div><div class="stat-foot">Aktivitas berjalan</div></article><article class="stat-card"><div class="stat-label">SELESAI</div><div class="stat-number">'.count(array_filter($allUserTasks,fn($t)=>$t['status']==='Selesai')).'</div><div class="stat-foot">Tugas selesai dikerjakan</div></article></div>';
echo '<div class="kanban-filter"><span class="filter-caption">FILTER STATUS</span><a href="tasks.php">Semua</a><a href="tasks.php?status=Belum%20dikerjakan">Belum dikerjakan</a><a href="tasks.php?status=Dikerjakan">Dikerjakan</a><a href="tasks.php?status=Review">Review</a><a href="tasks.php?status=Selesai">Selesai</a></div><div class="task-board">';
$columns=['Belum dikerjakan'=>'todo','Dikerjakan'=>'doing','Review'=>'review','Selesai'=>'done'];
foreach($columns as $statusLabel=>$cls){
    $subset=array_values(array_filter($tasks,fn($t)=>$t['status']===$statusLabel));
    echo '<section class="kanban-column"><div class="kanban-head"><span class="kanban-dot '.$cls.'"></span><b>'.e($statusLabel).'</b><span class="kanban-count">'.count($subset).'</span></div>';
    foreach($subset as $t){
        $id=e($t['id']); echo '<article class="kanban-card"><div class="kanban-card-top"><span class="task-tag">'.e($t['weight']).'× bobot</span><span class="muted">···</span></div><h3>'.e($t['title']).'</h3><p>'.e($t['group_name']??'Kelompok').'</p><div class="kanban-card-bottom"><span class="due-date">◷ '.e($t['due_date']??('Minggu target '.($t['target_week']??1))).'</span>';
        if($teacher) echo '<span class="task-state">'.e($t['status']).'</span>';
        else { echo '<form method="post" class="status-form"><input type="hidden" name="csrf" value="'.e(csrf_token()).'"><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="'.$id.'"><select name="status" aria-label="Ubah status" onchange="this.form.submit()">'; foreach(array_keys($columns) as $s) echo '<option '.($s===($t['status']??'')?'selected':'').' value="'.e($s).'">'.e($s).'</option>'; echo '</select></form>'; }
        echo '</div></article>';
    }
    if(!$teacher) echo '<a class="kanban-add" href="#new-task">＋ Tambah tugas</a>';
    echo '</section>';
}
echo '</div>';
if(!$teacher){
    echo '<section class="panel task-create-panel" id="new-task"><div class="panel-heading"><div><div class="panel-title">Tambah tugas WBS</div><div class="panel-sub">Tugas disimpan dalam tabel tasks di Supabase.</div></div></div>';
    if(!$groups) echo '<div class="flash error">Buat atau gabung kelompok terlebih dahulu di halaman Tim & Kelompok.</div>';
    else {
        echo '<form method="post" class="form-grid"><input type="hidden" name="csrf" value="'.e(csrf_token()).'"><input type="hidden" name="action" value="create"><div class="field-wide"><label>Nama tugas</label><input name="title" maxlength="200" required placeholder="Contoh: Rancang halaman dashboard"></div><div><label>Kelompok</label><select name="group_id" required>'; foreach($groups as $g) echo '<option value="'.e($g['id']).'">'.e($g['name']).'</option>'; echo '</select></div><div><label>Bobot kesulitan (1–5)</label><select name="weight"><option>1</option><option>2</option><option>3</option><option>4</option><option>5</option></select></div><div><label>Status awal</label><select name="status"><option>Belum dikerjakan</option><option>Dikerjakan</option><option>Review</option><option>Selesai</option></select></div><div><label>Target minggu</label><input type="number" name="target_week" min="1" max="52" value="1" required></div><div class="field-actions"><button class="btn primary">Simpan tugas →</button></div></form>';
    }
    echo '</section>';
}
layout_end();
