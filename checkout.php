<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';

requireRole(['Administrator', 'Hotel Manager', 'Receptionist']);

require_once __DIR__ . '/../includes/header.php';

$activeStays = $pdo->query(
    "SELECT s.stay_id, s.check_in_at, g.guest_id, g.full_name, g.phone, r.room_id, r.room_number, rt.name AS room_type_name
     FROM stays s
     JOIN guests g ON g.guest_id = s.guest_id
     JOIN rooms r ON r.room_id = s.room_id
     JOIN room_types rt ON rt.room_type_id = r.room_type_id
     WHERE s.status = 'Active'
     ORDER BY s.stay_id DESC"
)->fetchAll();

$selectedStayId = isset($_GET['stay_id']) ? (int) $_GET['stay_id'] : (int) ($_POST['stay_id'] ?? ($activeStays[0]['stay_id'] ?? 0));
$errors = [];
$checkedOutMessage = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['complete_checkout'])) {
    $stayId = (int) ($_POST['stay_id'] ?? 0);
    $selectedStayId = $stayId;

    try {
        $pdo->beginTransaction();

        $stayStmt = $pdo->prepare("SELECT * FROM stays WHERE stay_id = ? AND status = 'Active' FOR UPDATE");
        $stayStmt->execute([$stayId]);
        $stay = $stayStmt->fetch();

        if (!$stay) {
            throw new RuntimeException('Active stay not found.');
        }

        $cStmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM charges WHERE stay_id = ?');
        $cStmt->execute([$stayId]);
        $totalCharges = (float) $cStmt->fetchColumn();

        $pStmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM payments WHERE stay_id = ?');
        $pStmt->execute([$stayId]);
        $totalPaid = (float) $pStmt->fetchColumn();

        $balance = round($totalCharges - $totalPaid, 2);

        if ($balance > 0.0) {
            throw new RuntimeException('Folio has an outstanding balance of KES ' . number_format($balance, 2) . '. Settle balance before check-out (BR-006).');
        }

        // Close stay
        $updStay = $pdo->prepare("UPDATE stays SET status = 'Checked-Out', check_out_at = NOW() WHERE stay_id = ?");
        $updStay->execute([$stayId]);

        // Release future stay_nights tied to this stay
        $delNights = $pdo->prepare('DELETE FROM stay_nights WHERE stay_id = ?');
        $delNights->execute([$stayId]);

        // Mark room Dirty (FR-016, BR-008)
        $roomId = (int) $stay['room_id'];
        $updRoom = $pdo->prepare("UPDATE rooms SET status = 'Dirty' WHERE room_id = ?");
        $updRoom->execute([$roomId]);

        // Create cleaning task automatically (FR-026)
        $insTask = $pdo->prepare(
            "INSERT INTO housekeeping_tasks (room_id, task_type, status, notes)
             VALUES (:room_id, 'Cleaning', 'Pending', :notes)"
        );
        $insTask->execute([
            'room_id' => $roomId,
            'notes'   => "Auto-generated after check-out for Stay #{$stayId}",
        ]);

        $pdo->commit();
        $checkedOutMessage = "Stay #{$stayId} checked out. Room marked Dirty and cleaning task created.";

        // Refresh active stays list
        $activeStays = $pdo->query(
            "SELECT s.stay_id, s.check_in_at, g.guest_id, g.full_name, g.phone, r.room_id, r.room_number, rt.name AS room_type_name
             FROM stays s
             JOIN guests g ON g.guest_id = s.guest_id
             JOIN rooms r ON r.room_id = s.room_id
             JOIN room_types rt ON rt.room_type_id = r.room_type_id
             WHERE s.status = 'Active'
             ORDER BY s.stay_id DESC"
        )->fetchAll();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $errors[] = $e->getMessage();
    }
}

$currentStay = null;
$chargesTotal = 0.0;
$paymentsTotal = 0.0;
$balanceDue = 0.0;

if ($selectedStayId > 0) {
    $sStmt = $pdo->prepare(
        "SELECT s.*, g.full_name, g.phone, r.room_number, rt.name AS room_type_name
         FROM stays s
         JOIN guests g ON g.guest_id = s.guest_id
         JOIN rooms r ON r.room_id = s.room_id
         JOIN room_types rt ON rt.room_type_id = r.room_type_id
         WHERE s.stay_id = ?"
    );
    $sStmt->execute([$selectedStayId]);
    $currentStay = $sStmt->fetch();

    if ($currentStay) {
        $cStmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM charges WHERE stay_id = ?');
        $cStmt->execute([$selectedStayId]);
        $chargesTotal = (float) $cStmt->fetchColumn();

        $pStmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM payments WHERE stay_id = ?');
        $pStmt->execute([$selectedStayId]);
        $paymentsTotal = (float) $pStmt->fetchColumn();

        $balanceDue = round($chargesTotal - $paymentsTotal, 2);
    }
}
?>

<h3 class="mb-3">Guest Check-Out</h3>

<?php if ($checkedOutMessage !== null): ?>
    <div class="alert alert-success"><?= htmlspecialchars($checkedOutMessage, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<?php foreach ($errors as $err): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></div>
<?php endforeach; ?>

<div class="card shadow-sm mb-4">
    <div class="card-body">
        <form method="GET" action="" class="row g-3 align-items-end">
            <div class="col-md-8">
                <label class="form-label" for="stay_id">Select Active Stay</label>
                <select id="stay_id" name="stay_id" class="form-select">
                    <?php if (empty($activeStays)): ?>
                        <option value="0">No active stays</option>
                    <?php else: ?>
                        <?php foreach ($activeStays as $st): ?>
                            <option value="<?= (int) $st['stay_id'] ?>" <?= $selectedStayId === (int) $st['stay_id'] ? 'selected' : '' ?>>
                                Stay #<?= (int) $st['stay_id'] ?> — <?= htmlspecialchars($st['full_name'], ENT_QUOTES, 'UTF-8') ?> (Room <?= htmlspecialchars($st['room_number'], ENT_QUOTES, 'UTF-8') ?>)
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </div>
            <div class="col-md-4">
                <button type="submit" class="btn btn-secondary w-100">Load Stay</button>
            </div>
        </form>
    </div>
</div>

<?php if ($currentStay && $currentStay['status'] === 'Active'): ?>
    <div class="card shadow-sm">
        <div class="card-body">
            <h5>Stay #<?= (int) $currentStay['stay_id'] ?> Summary</h5>
            <p class="mb-1"><strong>Guest:</strong> <?= htmlspecialchars($currentStay['full_name'], ENT_QUOTES, 'UTF-8') ?> (<?= htmlspecialchars($currentStay['phone'], ENT_QUOTES, 'UTF-8') ?>)</p>
            <p class="mb-1"><strong>Room:</strong> <?= htmlspecialchars($currentStay['room_number'], ENT_QUOTES, 'UTF-8') ?> — <?= htmlspecialchars($currentStay['room_type_name'], ENT_QUOTES, 'UTF-8') ?></p>
            <p class="mb-1"><strong>Total Charges:</strong> KES <?= number_format($chargesTotal, 2) ?></p>
            <p class="mb-1"><strong>Total Paid:</strong> KES <?= number_format($paymentsTotal, 2) ?></p>
            <p class="mb-3">
                <strong>Balance Due:</strong>
                <span class="<?= $balanceDue > 0 ? 'text-danger fw-bold' : 'text-success fw-bold' ?>">
                    KES <?= number_format($balanceDue, 2) ?>
                </span>
            </p>

            <?php if ($balanceDue > 0): ?>
                <div class="alert alert-warning">
                    Balance must be settled before completing check-out.
                    <a href="/hms/billing/folio.php?stay_id=<?= (int) $currentStay['stay_id'] ?>" class="alert-link ms-2">Record Payment in Folio</a>
                </div>
            <?php else: ?>
                <form method="POST" action="">
                    <input type="hidden" name="stay_id" value="<?= (int) $currentStay['stay_id'] ?>">
                    <button type="submit" name="complete_checkout" class="btn btn-warning">Complete Check-Out</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>