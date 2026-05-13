<?php
// print_id_card.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require __DIR__ . '/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$role = strtolower($_SESSION['role'] ?? '');
if (!in_array($role, ['staff', 'admin'], true)) {
    header('Location: home.php');
    exit;
}

$userId = (int)($_GET['user_id'] ?? 0);
if ($userId <= 0) {
    exit('Invalid user.');
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Print ID Card</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
    body{
        margin:0;
        padding:20px;
        background:#f2f2f2;
        font-family:Arial, sans-serif;
        text-align:center;
    }
    .wrap{
        max-width:900px;
        margin:0 auto;
    }
    .toolbar{
        margin-bottom:20px;
    }
    .btn{
        display:inline-block;
        padding:10px 18px;
        border:none;
        border-radius:6px;
        background:#b30000;
        color:#fff;
        text-decoration:none;
        cursor:pointer;
        font-size:14px;
        margin:0 5px;
    }
    .btn.secondary{
        background:#333;
    }
    .card-preview{
        background:#fff;
        padding:20px;
        border-radius:10px;
        box-shadow:0 8px 24px rgba(0,0,0,.15);
        display:inline-block;
    }
    .card-preview img{
        max-width:100%;
        height:auto;
        border:1px solid #ddd;
    }

    @media print{
        body{
            background:#fff;
            padding:0;
        }
        .toolbar{
            display:none;
        }
        .card-preview{
            box-shadow:none;
            padding:0;
            border:none;
        }
        .card-preview img{
            border:none;
            max-width:100%;
        }
    }
</style>
</head>
<body>
    <div class="wrap">
        <div class="toolbar">
            <button class="btn" onclick="window.print()">Print Now</button>
            <a href="staff_id_cards.php" class="btn secondary">Back</a>
        </div>

        <div class="card-preview">
            <img src="id_rfid_card.php?user_id=<?= $userId ?>" alt="ID Card">
        </div>
    </div>
</body>
</html>