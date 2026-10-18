<?php
require_once __DIR__.'/includes/bootstrap.php';
if(current_user())redirect('dashboard.php');
$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
 verify_csrf(); $name=trim($_POST['name']??''); $email=mb_strtolower(trim($_POST['email']??'')); $pass=(string)($_POST['password']??'');
 if($name===''||!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($pass)<8) $error='Isi nama, email valid, dan kata sandi minimal 8 karakter.';
 else {
   try {
     require_once __DIR__.'/data/db.php'; $pdo=fairshare_db();
     if ($pdo) {
       $stmt=$pdo->prepare('INSERT INTO public.users (name,email,password_hash,role) VALUES (:name,:email,:hash,\'mahasiswa\')');
       $stmt->execute(['name'=>$name,'email'=>$email,'hash'=>password_hash($pass,PASSWORD_DEFAULT)]);
       flash('Akun berhasil dibuat. Silakan masuk.'); redirect('login.php');
     }
     $users=read_data('users.json',[]);
     if (array_filter($users,fn($u)=>mb_strtolower((string)$u['email'])===$email)) $error='Email sudah terdaftar.';
     else { $id=max(array_map(fn($u)=>(int)($u['id']??0),$users)?:[0])+1; $users[]=['id'=>$id,'name'=>$name,'email'=>$email,'password_hash'=>password_hash($pass,PASSWORD_DEFAULT),'role'=>'mahasiswa']; if(!write_data('users.json',$users)) $error='Gagal menyimpan akun. Periksa izin folder data.'; else { flash('Akun berhasil dibuat. Silakan masuk.'); redirect('login.php'); } }
   } catch (PDOException $e) {
     error_log('FairShare register: '.$e->getMessage());
     $error = $e->getCode()==='23505' ? 'Email sudah terdaftar.' : 'Pendaftaran ke Supabase gagal. Pastikan schema.sql sudah dijalankan dan koneksi benar.';
   } catch (Throwable $e) { error_log('FairShare register: '.$e->getMessage()); $error='Koneksi Supabase gagal. Periksa data/.env dan ekstensi pdo_pgsql.'; }
 }
}
?><!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Daftar · FairShare</title><link rel="stylesheet" href="assets/style.css?v=3"></head><body class="auth-page"><div class="auth-top"><a class="brand brand-light" href="login.php"><span class="brand-mark brand-logo"><img src="assets/fairshare-logo.svg" alt=""></span><span>FairShare<small>COLLABORATION INTELLIGENCE</small></span></a><span>Mulai kolaborasi lebih transparan</span></div><div class="auth-layout register-layout"><section class="auth-story"><div class="story-chip"><i></i> YOUR TEAM, IN BETTER SYNC</div><h1>Mulai dari tim.<br><span>Tumbuh bersama.</span></h1><p>Buat ruang kerja kolaboratif, kelola tugas, dan bangun riwayat kontribusi yang bisa dipertanggungjawabkan.</p></section><section class="auth-panel"><div class="auth-card"><div class="auth-emblem auth-logo"><img src="assets/fairshare-logo.svg" alt="Logo FairShare"></div><div class="eyebrow center">BUAT AKUN BARU</div><h2>Gabung ke FairShare</h2><p class="auth-muted">Daftarkan akun untuk memulai workspace kamu.</p><?php if($error):?><div class="flash error"><?=e($error)?></div><?php endif;?><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><label>Nama lengkap</label><input name="name" placeholder="Nama kamu" required><label>Email kampus</label><input type="email" name="email" placeholder="nama@kampus.ac.id" required><label>Kata sandi</label><input type="password" name="password" minlength="8" placeholder="Minimal 8 karakter" required><button class="btn primary auth-submit" type="submit">Buat akun <span>→</span></button></form><div class="auth-note">Sudah punya akun? <a href="login.php">Masuk di sini</a></div></div></section></div><footer class="auth-footer">© 2026 FairShare · Politeknik Negeri Malang</footer></body></html>
