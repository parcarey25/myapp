<?php
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
require __DIR__.'/db.php';

$slug = $_GET['f'] ?? '';
$st = $conn->prepare("SELECT id,name,description,image FROM facilities WHERE slug=? AND is_active=1 LIMIT 1");
$st->bind_param('s', $slug);
$st->execute();
$res = $st->get_result();
$fac = $res ? $res->fetch_assoc() : null;
if ($res) $res->free();
$st->close();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title><?= $fac ? htmlspecialchars($fac['name']) : 'Facility' ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/css/bootstrap.min.css" rel="stylesheet">
<style>body{background:#111;color:#fff;font-family:'Poppins',sans-serif}.navbar{background:linear-gradient(90deg,#000,#b30000)}</style>
</head>
<body>
<nav class="navbar navbar-dark">
  <a class="navbar-brand ml-3" href="facilities.php"><img src="photo/logo.jpg" height="32" class="mr-2" alt="">RJL Fitness</a>
</nav>
<div class="container py-4">
  <?php if(!$fac): ?>
    <div class="alert alert-secondary">Facility not found.</div>
  <?php else: ?>
    <div class="row">
      <div class="col-md-6 mb-3"><img src="<?= htmlspecialchars($fac['image'] ?: 'photo/logo.jpg') ?>" class="img-fluid rounded" alt=""></div>
      <div class="col-md-6">
        <h3><?= htmlspecialchars($fac['name']) ?></h3>
        <p class="text-muted"><?= nl2br(htmlspecialchars($fac['description'] ?: '')) ?></p>
        <a class="btn btn-danger" href="schedules.php?facility=<?= urlencode($slug) ?>">Reserve a Spot</a>
      </div>
    </div>
  <?php endif; ?>
</div>
</body>
</html>