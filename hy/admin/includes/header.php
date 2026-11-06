<?php
/* Opens an admin page. Set before including: $pageTitle (string), $active (nav key), $admin (array).
   Set $bare = true for the sign-in page (no sidebar). */
$bare = $bare ?? false;
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= h($pageTitle ?? 'Admin') ?> | HEISVERSE admin</title>
<link rel="icon" href="../assets/icons/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="../css/admin.css">
</head>
<body class="<?= $bare ? 'bare' : 'app' ?>">
<?php if ($bare): ?>
<main class="auth">
<?php else: ?>
<div class="shell">
<?php require __DIR__ . '/sidebar.php'; ?>
<main class="content">
<?php endif; ?>
<?php foreach (flash_take() as [$ft, $fm]): ?>
  <div class="flash <?= h($ft) ?>" role="<?= $ft === 'error' ? 'alert' : 'status' ?>"><?= h($fm) ?></div>
<?php endforeach; ?>
