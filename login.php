<?php
require_once __DIR__.'/includes/bootstrap.php';
if(current_user())redirect('dashboard.php');
$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
 verify_csrf();
 $email=mb_strtolower(trim($_POST['email']??'')); $password=(string)($_POST['password']??'');
 $authenticated = null;
 try {
   require_once __DIR__.'/data/db.php';
   $pdo = fairshare_db();
   if ($pdo) {
     $stmt=$pdo->prepare('SELECT id,name,email,password_hash,role FROM public.users WHERE LOWER(email)=LOWER(:email) LIMIT 1');
     $stmt->execute(['email'=>$email]); $row=$stmt->fetch();
     if ($row && password_verify($password,(string)$row['password_hash'])) $authenticated=$row;
   }
 } catch (Throwable $e) {
   error_log('FairShare login: '.$e->getMessage());
   $error='Database Supabase belum bisa diakses. Periksa data/.env dan aktifkan pdo_pgsql di XAMPP.';
 }
 // Compatibility: accounts created by the older JSON-only version still work.
 if ($authenticated===null && $error==='') {
   $users=read_data('users.json',[]);
   foreach($users as $u) if(mb_strtolower((string)($u['email']??''))===$email && !empty($u['password_hash']) && password_verify($password,(string)$u['password_hash'])) { $authenticated=$u; break; }
 }
 if ($authenticated) {
   session_regenerate_id(true);
   $_SESSION['user']=['id'=>(int)$authenticated['id'],'name'=>$authenticated['name'],'email'=>$authenticated['email'],'role'=>$authenticated['role'] ?? 'mahasiswa'];
   log_activity('Login berhasil','Pengguna masuk ke workspace','success'); redirect('dashboard.php');
 }
 if ($error==='') $error='Email atau kata sandi tidak cocok.';
}
?><!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Masuk · FairShare</title><link rel="stylesheet" href="assets/style.css?v=3"></head><body class="auth-page"><div class="auth-top"><a class="brand brand-light" href="login.php"><span class="brand-mark brand-logo"><img src="assets/fairshare-logo.svg" alt=""></span><span>FairShare<small>COLLABORATION INTELLIGENCE</small></span></a><span>Platform transparansi kontribusi</span></div><div class="auth-layout"><section class="auth-story"><div class="story-chip"><i></i> WORKSPACE FOR BETTER TEAMWORK</div><h1>Kerja tim yang<br><span>lebih transparan.</span></h1><p>Ubah aktivitas kolaborasi menjadi insight yang jelas. Pantau progres, pahami kontribusi, dan bangun kerja kelompok yang lebih adil.</p><div class="story-stats"><div><b>01</b><span>Kontribusi terukur</span></div><div><b>02</b><span>Audit transparan</span></div><div><b>03</b><span>Progres terpantau</span></div></div><div class="story-orbit orbit-one"></div><div class="story-orbit orbit-two"></div></section><section class="auth-panel"><div class="auth-card"><div class="auth-emblem auth-logo"><img src="assets/fairshare-logo.svg" alt="Logo FairShare"></div><div class="eyebrow center">SELAMAT DATANG KEMBALI</div><h2>Masuk ke FairShare</h2><p class="auth-muted">Lanjutkan memantau kontribusi dan progres tim kamu.</p><?php if($error):?><div class="flash error"><?=e($error)?></div><?php endif;?><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><label>Email kampus</label><input type="email" name="email" placeholder="nama@kampus.ac.id" required autocomplete="username"><label>Kata sandi</label><input type="password" name="password" placeholder="Masukkan kata sandi" required autocomplete="current-password"><button class="btn primary auth-submit" type="submit">Masuk ke workspace <span>→</span></button></form><div class="auth-note"><b>Demo lokal</b><span>demo@fairshare.local · demo12345</span></div><div class="auth-note">Belum punya akun? <a href="register.php">Buat akun baru</a></div><div class="auth-privacy">Data aktivitas ditampilkan untuk transparansi proyek. FairShare tidak memantau perangkat secara invasif.</div></div></section></div><footer class="auth-footer">© 2026 FairShare · Politeknik Negeri Malang</footer></body></html>
