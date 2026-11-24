<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
requireRole(['Administrator', 'Hotel Manager', 'Receptionist', 'Guest']);

$reservationId = isset($_GET['id']) ? (int) $_GET['id'] : (int) ($_POST['reservation_id'] ?? 0);
$error = '';
$done = false;

$stmt = $pdo->prepare(
    'SELECT r.*, g.full_name AS guest_name, rt.name AS room_type_name
     FROM reservations r
     JOIN guests g ON g.guest_id = r.guest_id
     JOIN room_types rt ON rt.room_type_id = r.room_type_id
     WHERE r.reservation_id = ?'
);
$stmt->execute([$reservationId]);
$reservation = $stmt->fetch();

if (!$reservation) {
    echo '<div class="alert alert-danger">Reservation not found.</div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_cancel'])) {
    try {
        $pdo->beginTransaction();

        if (!in_array($reservation['status'], ['Pending', 'Confirmed'], true)) {
            throw new RuntimeException('Only Pending or Confirmed reservations can be cancelled.');
        }

        // Release reserved stay_nights rows for this reservation
        $delNights = $pdo->prepare('DELETE FROM stay_nights WHERE reservation_id = ? AND stay_id IS NULL');
        $delNights->execute([$reservationId]);

        $upd = $pdo->prepare("UPDATE reservations SET status = 'Cancelled' WHERE reservation_id = ?");
        $upd->execute([$reservationId]);

        $pdo->commit();
        $done = true;
        $reservation['status'] = 'Cancelled';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e->getMessage();
    }
}
?>

<h3 class="mb-3">Cancel Reservation</h3>

<?php if ($done): ?>
    <div class="alert alert-success">
        Reservation <strong><?= htmlspecialchars($reservation['confirmation_code'], ENT_QUOTES, 'UTF-8') ?></strong> has been cancelled and room nights released.
        <a href="/hms/reservations/list.php" class="alert-link ms-2">Back to Reservations</a>
    </div>
<?php endif; ?>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<div class="card shadow-sm">
    <div class="card-body">
        <p><strong>Confirmation Code:</strong> <?= htmlspecialchars($reservation['confirmation_code'], ENT_QUOTES, 'UTF-8') ?></p>
        <p><strong>Guest:</strong> <?= htmlspecialchars($reservation['guest_name'], ENT_QUOTES, 'UTF-8') ?></p>
        <p><strong>Room Type:</strong> <?= htmlspecialchars($reservation['room_type_name'], ENT_QUOTES, 'UTF-8') ?></p>
        <p><strong>Dates:</strong> <?= htmlspecialchars($reservation['check_in_date'], ENT_QUOTES, 'UTF-8') ?> to <?= htmlspecialchars($reservation['check_out_date'], ENT_QUOTES, 'UTF-8') ?></p>
        <p><strong>Current Status:</strong> <?= htmlspecialchars($reservation['status'], ENT_QUOTES, 'UTF-8') ?></p>

        <?php if (in_array($reservation['status'], ['Pending', 'Confirmed'], true) && !$done): ?>
            <form method="POST" action="">
                <input type="hidden" name="reservation_id" value="<?= (int) $reservationId ?>">
                <button type="submit" name="confirm_cancel" class="btn btn-danger">Confirm Cancellation</button>
                <a href="/hms/reservations/list.php" class="btn btn-secondary ms-2">Keep Reservation</a>
            </form>
        <?php else: ?>
            <a href="/hms/reservations/list.php" class="btn btn-secondary">Back to Reservations</a>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>