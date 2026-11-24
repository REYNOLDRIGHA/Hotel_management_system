<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';

requireRole(['Administrator', 'Hotel Manager', 'Housekeeping Supervisor', 'Housekeeper']);

require_once __DIR__ . '/../includes/header.php';

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['complete_task'])) {
            $taskId = (int) ($_POST['task_id'] ?? 0);
            $pdo->beginTransaction();

            $tStmt = $pdo->prepare('SELECT * FROM housekeeping_tasks WHERE task_id = ? FOR UPDATE');
            $tStmt->execute([$taskId]);
            $task = $tStmt->fetch();
            if (!$task) {
                throw new RuntimeException('Task not found.');
            }

            $updTask = $pdo->prepare(
                "UPDATE housekeeping_tasks SET status = 'Completed', completed_at = NOW() WHERE task_id = ?"
            );
            $updTask->execute([$taskId]);

            // Mark room Available if it was Dirty or Cleaning
            $updRoom = $pdo->prepare(
                "UPDATE rooms SET status = 'Available' WHERE room_id = ? AND status IN ('Dirty', 'Cleaning')"
            );
            $updRoom->execute([(int) $task['room_id']]);

            $pdo->commit();
            $success = "Task #{$taskId} marked Completed and room set to Available.";
        }

        if (isset($_POST['update_room_status'])) {
            $roomId = (int) ($_POST['room_id'] ?? 0);
            $newStatus = (string) ($_POST['room_status'] ?? '');

            if (!in_array($newStatus, ['Available', 'Dirty', 'Cleaning', 'Out of Service'], true)) {
                throw new RuntimeException('Invalid room status selected.');
            }

            $upd = $pdo->prepare('UPDATE rooms SET status = ? WHERE room_id = ?');
            $upd->execute([$newStatus, $roomId]);
            $success = "Room status updated to {$newStatus}.";
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $errors[] = $e->getMessage();
    }
}

$tasks = $pdo->query(
    "SELECT ht.*, r.room_number, rt.name AS room_type_name
     FROM housekeeping_tasks ht
     JOIN rooms r ON r.room_id = ht.room_id
     JOIN room_types rt ON rt.room_type_id = r.room_type_id
     ORDER BY FIELD(ht.status, 'Pending', 'In Progress', 'Completed'), ht.task_id DESC"
)->fetchAll();

$rooms = $pdo->query(
    'SELECT r.room_id, r.room_number, r.status, rt.name AS room_type_name
     FROM rooms r
     JOIN room_types rt ON rt.room_type_id = r.room_type_id
     ORDER BY r.room_number ASC'
)->fetchAll();
?>

<h3 class="mb-3">Housekeeping Tasks &amp; Room Status</h3>

<?php if ($success !== ''): ?>
    <div class="alert alert-success"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<?php foreach ($errors as $err): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></div>
<?php endforeach; ?>

<div class="card shadow-sm mb-4">
    <div class="card-header bg-white"><strong>Cleaning &amp; Maintenance Tasks</strong></div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-striped table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Task #</th>
                        <th>Room</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th>Notes</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($tasks)): ?>
                        <tr><td colspan="6" class="text-center text-muted py-4">No housekeeping tasks yet.</td></tr>
                    <?php else: ?>
                        <?php foreach ($tasks as $t): ?>
                            <?php
                            $tBadge = match ($t['status']) {
                                'Completed'   => 'bg-success',
                                'In Progress' => 'bg-info text-dark',
                                default       => 'bg-warning text-dark',
                            };
                            ?>
                            <tr>
                                <td>#<?= (int) $t['task_id'] ?></td>
                                <td><strong><?= htmlspecialchars($t['room_number'], ENT_QUOTES, 'UTF-8') ?></strong> (<?= htmlspecialchars($t['room_type_name'], ENT_QUOTES, 'UTF-8') ?>)</td>
                                <td><?= htmlspecialchars($t['task_type'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><span class="badge <?= $tBadge ?>"><?= htmlspecialchars($t['status'], ENT_QUOTES, 'UTF-8') ?></span></td>
                                <td><?= htmlspecialchars((string) ($t['notes'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                                <td>
                                    <?php if ($t['status'] !== 'Completed'): ?>
                                        <form method="POST" action="" class="d-inline">
                                            <input type="hidden" name="task_id" value="<?= (int) $t['task_id'] ?>">
                                            <button type="submit" name="complete_task" class="btn btn-sm btn-success">Complete &amp; Mark Available</button>
                                        </form>
                                    <?php else: ?>
                                        <span class="text-muted small">Done</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-header bg-white"><strong>Quick Room Status Override</strong></div>
    <div class="card-body">
        <form method="POST" action="" class="row g-3 align-items-end">
            <div class="col-md-5">
                <label class="form-label" for="room_id">Room</label>
                <select id="room_id" name="room_id" class="form-select" required>
                    <?php foreach ($rooms as $rm): ?>
                        <option value="<?= (int) $rm['room_id'] ?>">
                            Room <?= htmlspecialchars($rm['room_number'], ENT_QUOTES, 'UTF-8') ?> — <?= htmlspecialchars($rm['room_type_name'], ENT_QUOTES, 'UTF-8') ?> (Current: <?= htmlspecialchars($rm['status'], ENT_QUOTES, 'UTF-8') ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label" for="room_status">New Status</label>
                <select id="room_status" name="room_status" class="form-select" required>
                    <option value="Available">Available</option>
                    <option value="Dirty">Dirty</option>
                    <option value="Cleaning">Cleaning</option>
                    <option value="Out of Service">Out of Service</option>
                </select>
            </div>
            <div class="col-md-3">
                <button type="submit" name="update_room_status" class="btn btn-primary w-100">Update Status</button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>