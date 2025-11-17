<<<<<<< HEAD
<?php
require __DIR__.'/auth.php'; require_role('trainer');
require __DIR__.'/db.php';

$trainer_id = (int)$_SESSION['user_id'];

// handle create
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action']) && $_POST['action']==='create') {
  $facility_id = (int)$_POST['facility_id'];
  $title = trim($_POST['title']);
  $start = $_POST['start_time']; // 'YYYY-mm-dd HH:ii'
  $end   = $_POST['end_time'];
  $cap   = max(1,(int)$_POST['capacity']);

  $stmt = $conn->prepare("INSERT INTO schedules (facility_id, trainer_id, title, start_time, end_time, capacity) VALUES (?,?,?,?,?,?)");
  $stmt->bind_param('iisssi',$facility_id,$trainer_id,$title,$start,$end,$cap);
  $stmt->execute(); $stmt->close();
  header('Location: schedules_manage.php?ok=1'); exit;
}

// handle delete (trainer can delete own schedules only)
if (isset($_GET['delete'])) {
  $sid = (int)$_GET['delete'];
  $conn->query("DELETE FROM schedules WHERE id={$sid} AND trainer_id={$trainer_id}");
  header('Location: schedules_manage.php?deleted=1'); exit;
}

// data for page
$fac = $conn->query("SELECT id,name FROM facilities WHERE is_active=1 ORDER BY name");
$my = $conn->query("SELECT s.*, f.name AS facility
                    FROM schedules s JOIN facilities f ON f.id=s.facility_id
                    WHERE s.trainer_id={$trainer_id}
                    ORDER BY s.start_time DESC");
?>
<!doctype html><html><head>
<meta charset="utf-8"><title>Manage Schedules</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head><body class="bg-dark text-white">
<div class="container py-4">
  <h3>🗓 Create/Manage Schedules</h3>

  <div class="card bg-secondary border-0 mb-4">
    <div class="card-body">
      <form method="post">
        <input type="hidden" name="action" value="create">
        <div class="form-row">
          <div class="form-group col-md-3">
            <label>Facility</label>
            <select name="facility_id" class="form-control" required>
              <?php while($f=$fac->fetch_assoc()): ?>
                <option value="<?=$f['id']?>"><?=htmlspecialchars($f['name'])?></option>
              <?php endwhile; $fac->free(); ?>
            </select>
          </div>
          <div class="form-group col-md-3">
            <label>Title</label>
            <input type="text" name="title" class="form-control" placeholder="HIIT / Open Slot" required>
          </div>
          <div class="form-group col-md-3">
            <label>Start</label>
            <input type="datetime-local" name="start_time" class="form-control" required>
          </div>
          <div class="form-group col-md-2">
            <label>End</label>
            <input type="datetime-local" name="end_time" class="form-control" required>
          </div>
          <div class="form-group col-md-1">
            <label>Cap</label>
            <input type="number" name="capacity" class="form-control" min="1" value="10" required>
          </div>
        </div>
        <button class="btn btn-danger">Create</button>
      </form>
    </div>
  </div>

  <h5 class="mb-2">Your Schedules</h5>
  <?php if ($my->num_rows===0): ?>
    <div class="alert alert-secondary">No schedules yet.</div>
  <?php else: ?>
    <div class="list-group">
      <?php while($s=$my->fetch_assoc()): ?>
        <div class="list-group-item bg-secondary text-white border-0 mb-2" id="s<?=$s['id']?>">
          <div class="d-flex justify-content-between">
            <div>
              <strong><?=htmlspecialchars($s['title'])?></strong>
              <div><small><?=htmlspecialchars($s['facility'])?></small></div>
              <div><small><?=date('M d, Y g:ia', strtotime($s['start_time']))?> – <?=date('g:ia', strtotime($s['end_time']))?></small></div>
              <div><small>Capacity: <?=$s['capacity']?></small></div>
            </div>
            <div>
              <a href="?delete=<?=$s['id']?>" class="btn btn-outline-light btn-sm" onclick="return confirm('Delete this schedule?')">Delete</a>
            </div>
          </div>
        </div>
      <?php endwhile; $my->free(); ?>
    </div>
  <?php endif; ?>

  <a href="home.php" class="btn btn-outline-light mt-3">⬅ Back</a>
</div>
=======
<?php
require __DIR__.'/auth.php'; require_role('trainer');
require __DIR__.'/db.php';

$trainer_id = (int)$_SESSION['user_id'];

// handle create
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action']) && $_POST['action']==='create') {
  $facility_id = (int)$_POST['facility_id'];
  $title = trim($_POST['title']);
  $start = $_POST['start_time']; // 'YYYY-mm-dd HH:ii'
  $end   = $_POST['end_time'];
  $cap   = max(1,(int)$_POST['capacity']);

  $stmt = $conn->prepare("INSERT INTO schedules (facility_id, trainer_id, title, start_time, end_time, capacity) VALUES (?,?,?,?,?,?)");
  $stmt->bind_param('iisssi',$facility_id,$trainer_id,$title,$start,$end,$cap);
  $stmt->execute(); $stmt->close();
  header('Location: schedules_manage.php?ok=1'); exit;
}

// handle delete (trainer can delete own schedules only)
if (isset($_GET['delete'])) {
  $sid = (int)$_GET['delete'];
  $conn->query("DELETE FROM schedules WHERE id={$sid} AND trainer_id={$trainer_id}");
  header('Location: schedules_manage.php?deleted=1'); exit;
}

// data for page
$fac = $conn->query("SELECT id,name FROM facilities WHERE is_active=1 ORDER BY name");
$my = $conn->query("SELECT s.*, f.name AS facility
                    FROM schedules s JOIN facilities f ON f.id=s.facility_id
                    WHERE s.trainer_id={$trainer_id}
                    ORDER BY s.start_time DESC");
?>
<!doctype html><html><head>
<meta charset="utf-8"><title>Manage Schedules</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head><body class="bg-dark text-white">
<div class="container py-4">
  <h3>🗓 Create/Manage Schedules</h3>

  <div class="card bg-secondary border-0 mb-4">
    <div class="card-body">
      <form method="post">
        <input type="hidden" name="action" value="create">
        <div class="form-row">
          <div class="form-group col-md-3">
            <label>Facility</label>
            <select name="facility_id" class="form-control" required>
              <?php while($f=$fac->fetch_assoc()): ?>
                <option value="<?=$f['id']?>"><?=htmlspecialchars($f['name'])?></option>
              <?php endwhile; $fac->free(); ?>
            </select>
          </div>
          <div class="form-group col-md-3">
            <label>Title</label>
            <input type="text" name="title" class="form-control" placeholder="HIIT / Open Slot" required>
          </div>
          <div class="form-group col-md-3">
            <label>Start</label>
            <input type="datetime-local" name="start_time" class="form-control" required>
          </div>
          <div class="form-group col-md-2">
            <label>End</label>
            <input type="datetime-local" name="end_time" class="form-control" required>
          </div>
          <div class="form-group col-md-1">
            <label>Cap</label>
            <input type="number" name="capacity" class="form-control" min="1" value="10" required>
          </div>
        </div>
        <button class="btn btn-danger">Create</button>
      </form>
    </div>
  </div>

  <h5 class="mb-2">Your Schedules</h5>
  <?php if ($my->num_rows===0): ?>
    <div class="alert alert-secondary">No schedules yet.</div>
  <?php else: ?>
    <div class="list-group">
      <?php while($s=$my->fetch_assoc()): ?>
        <div class="list-group-item bg-secondary text-white border-0 mb-2" id="s<?=$s['id']?>">
          <div class="d-flex justify-content-between">
            <div>
              <strong><?=htmlspecialchars($s['title'])?></strong>
              <div><small><?=htmlspecialchars($s['facility'])?></small></div>
              <div><small><?=date('M d, Y g:ia', strtotime($s['start_time']))?> – <?=date('g:ia', strtotime($s['end_time']))?></small></div>
              <div><small>Capacity: <?=$s['capacity']?></small></div>
            </div>
            <div>
              <a href="?delete=<?=$s['id']?>" class="btn btn-outline-light btn-sm" onclick="return confirm('Delete this schedule?')">Delete</a>
            </div>
          </div>
        </div>
      <?php endwhile; $my->free(); ?>
    </div>
  <?php endif; ?>

  <a href="home.php" class="btn btn-outline-light mt-3">⬅ Back</a>
</div>
>>>>>>> b78dc527f4ca1b402224214aa4f78775c370647f
</body></html>