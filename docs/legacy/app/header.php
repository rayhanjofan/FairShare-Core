<?php
require_once __DIR__ . '/helpers.php';
$page_title = $page_title ?? APP_NAME;
$active = $active ?? '';
$u = user_data();
$notice = flash_get();
$menu = [
  ['dashboard.php', 'Dashboard', '◫'],
  ['contributions.php', 'Kontribusi Saya', '↗'],
  ['teams.php', 'Tim & Kelompok', '♧'],
  ['audit.php', 'Audit & Telemetry', '⌁'],
  ['settings.php', 'Pengaturan', '⚙'],
];
?>
<!doctype html>
<html lang="id">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($page_title) ?> · FairShare</title>
  <link rel="stylesheet" href="assets/style.css">
</head>

<body>
  <?php if (logged_in()): ?>
    <aside class="sidebar" id="sidebar">
      <a class="brand" href="dashboard.php"><span class="brand-icon">F</span><span>FairShare</span></a>
      <div class="side-label">WORKSPACE</div>
      <nav class="side-nav">
        <?php foreach ($menu as [$href, $label, $icon]): ?>
          <a class="<?= $active === $href ? 'active' : '' ?>" href="<?= e($href) ?>"><span
              class="nav-icon"><?= e($icon) ?></span><?= e($label) ?></a>
        <?php endforeach; ?>
      </nav>
      <div class="project-label">PROJECT</div>
      <div class="project-chip"><span class="project-avatar">F</span><span><strong>FairShare
            Project</strong><small>Workspace · Polinema</small></span><span class="dots">•••</span></div>
      <div class="side-user"><span
          class="user-avatar"><?= e(strtoupper(substr($u['name'], 0, 1))) ?></span><span><strong><?= e($u['name']) ?></strong><small>Anggota
            tim</small></span><a href="logout.php" title="Keluar">↪</a></div>
    </aside>
  <?php endif; ?>
  <div class="<?= logged_in() ? 'app-shell' : 'auth-shell' ?>">
    <?php if (logged_in()): ?>
      <header class="topbar"><button class="menu-toggle" id="menuToggle" aria-label="Buka menu">☰</button>
        <div class="crumb"><span>FAIRSHARE</span><small>/ <?= e(strtoupper(str_replace('.php', '', $active))) ?></small>
        </div>
        <div class="top-actions"><button class="icon-button" title="Notifikasi">♧<i></i></button><span
            class="top-avatar"><?= e(strtoupper(substr($u['name'], 0, 1))) ?></span><span
            class="user-top"><?= e($u['name']) ?></span></div>
      </header>
    <?php endif; ?>
    <main class="<?= logged_in() ? 'main-content' : 'auth-content' ?>">
      <?php if ($notice): ?>
        <div class="notice <?= e($notice['type']) ?>"><?= e($notice['message']) ?></div><?php endif; ?>