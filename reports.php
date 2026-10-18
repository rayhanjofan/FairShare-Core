<?php
require_once __DIR__.'/includes/bootstrap.php'; $user=require_login(); $notice='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf(); $action=(string)($_POST['action']??'profile');
    try {
        $pdo=require_db();
        if($action==='profile'){
            $name=trim((string)($_POST['name']??'')); $email=mb_strtolower(trim((string)($_POST['email']??'')));
            if($name==='' || mb_strlen($name)>100 || !filter_var($email,FILTER_VALIDATE_EMAIL) || mb_strlen($email)>150) $notice='Nama dan email valid wajib diisi.';
            else {
                $stmt=$pdo->prepare('UPDATE public.users SET name=:name,email=:email,updated_at=CURRENT_TIMESTAMP WHERE id=CAST(:id AS uuid)');
                $stmt->execute(['name'=>$name,'email'=>$email,'id'=>$user['id']]);
                $_SESSION['user']['name']=$name; $_SESSION['user']['email']=$email; $user=current_user();
                log_activity('Profil diperbarui','Nama atau email akun diperbarui','success'); $notice='Profil berhasil disimpan ke Supabase.';
            }
        } elseif($action==='password') {
            $old=(string)($_POST['old_password']??''); $new=(string)($_POST['new_password']??'');
            if(strlen($new)<8) $notice='Kata sandi baru minimal 8 karakter.';
            else {
                $q=$pdo->prepare('SELECT password_hash FROM public.users WHERE id=CAST(:id AS uuid)'); $q->execute(['id'=>$user['id']]); $hash=$q->fetchColumn();
                if(!$hash || !password_verify($old,(string)$hash)) $notice='Kata sandi saat ini tidak cocok.';
                else { $up=$pdo->prepare('UPDATE public.users SET password_hash=:hash,updated_at=CURRENT_TIMESTAMP WHERE id=CAST(:id AS uuid)'); $up->execute(['hash'=>password_hash($new,PASSWORD_DEFAULT),'id'=>$user['id']]); log_activity('Kata sandi diperbarui','Kredensial akun diubah','success'); $notice='Kata sandi berhasil diperbarui di Supabase.'; }
            }
        } elseif($action==='preferences') {
            // Preferences are intentionally device-local because the agreed five-table schema has no preferences entity.
            $_SESSION['preferences']=['weekly_digest'=>isset($_POST['weekly_digest']),'wei_alert'=>isset($_POST['wei_alert'])];
            write_data('preferences-'.(string)$user['id'].'.json',$_SESSION['preferences']); $notice='Preferensi disimpan pada perangkat ini.';
        }
    } catch(PDOException $e) {
        error_log('FairShare settings SQLSTATE '.$e->getCode().': '.$e->getMessage());
        $notice=$e->getCode()==='23505'?'Email sudah digunakan akun lain.':'Perubahan akun gagal disimpan. Periksa koneksi database.';
    } catch(Throwable $e) { error_log('FairShare settings: '.$e->getMessage()); $notice='Supabase belum dapat diakses. Periksa koneksi database.'; }
}
$prefs=read_data('preferences-'.(string)$user['id'].'.json',['weekly_digest'=>true,'wei_alert'=>true]);
layout_start('Pengaturan','settings'); page_heading('WORKSPACE / PREFERENCES','Pengaturan','Kelola profil, keamanan akun, dan preferensi workspace.');
if($notice) echo '<div class="flash '.(str_contains($notice,'berhasil')||str_contains($notice,'disimpan')?'success':'error').'">'.e($notice).'</div>';
echo '<div class="settings-layout"><div class="settings-main"><section class="panel settings-panel"><div class="settings-panel-title"><span class="settings-icon">♙</span><div><b>Profil & akun</b><small>Informasi dasar akun pada Supabase</small></div></div><form method="post"><input type="hidden" name="csrf" value="'.e(csrf_token()).'"><input type="hidden" name="action" value="profile"><div class="settings-field"><label>NIM/NIP</label><input value="'.e($user['nim_nip']??''). '" disabled></div><div class="settings-field"><label>Nama lengkap</label><input name="name" required maxlength="100" value="'.e($user['name']).'"></div><div class="settings-field"><label>Email</label><input type="email" name="email" required maxlength="150" value="'.e($user['email']).'"></div><div class="settings-actions"><button class="btn primary">Simpan profil</button></div></form></section><section class="panel settings-panel"><div class="settings-panel-title"><span class="settings-icon">♧</span><div><b>Keamanan</b><small>Perbarui kata sandi akun pada database</small></div></div><form method="post"><input type="hidden" name="csrf" value="'.e(csrf_token()).'"><input type="hidden" name="action" value="password"><div class="settings-field"><label>Kata sandi saat ini</label><input type="password" name="old_password" required></div><div class="settings-field"><label>Kata sandi baru</label><input type="password" name="new_password" minlength="8" required></div><div class="settings-actions"><button class="btn secondary">Perbarui kata sandi</button></div></form></section><section class="panel settings-panel"><div class="settings-panel-title"><span class="settings-icon">♧</span><div><b>Preferensi notifikasi</b><small>Preferensi ini hanya tersimpan pada perangkat lokal</small></div></div><form method="post"><input type="hidden" name="csrf" value="'.e(csrf_token()).'"><input type="hidden" name="action" value="preferences"><label class="preference-row"><span><b>Ringkasan mingguan</b><small>Pengingat untuk mengecek progres tim</small></span><input type="checkbox" name="weekly_digest" '.(!empty($prefs['weekly_digest'])?'checked':'').'></label><label class="preference-row"><span><b>Peringatan WEI</b><small>Pengingat saat data kontribusi perlu diperiksa</small></span><input type="checkbox" name="wei_alert" '.(!empty($prefs['wei_alert'])?'checked':'').'></label><div class="settings-actions"><button class="btn primary">Simpan preferensi</button></div></form></section></div><aside class="panel settings-aside"><div class="profile-large">'.e(mb_strtoupper(mb_substr($user['name'],0,1))).'</div><h3>'.e($user['name']).'</h3><p>'.e($user['email']).'</p><span class="status-badge good">● Akun aktif</span><div class="aside-divider"></div><div class="aside-info"><span>Peran</span><b>'.e(ucfirst((string)($user['role']??'mahasiswa'))).'</b></div><div class="aside-info"><span>Workspace</span><b>FairShare</b></div><div class="aside-divider"></div><a class="btn danger full-width" href="logout.php">Keluar dari akun ↗</a></aside></div>'; layout_end();
