<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';

requireRole(['Administrator', 'Hotel Manager', 'Receptionist', 'Guest']);

require_once __DIR__ . '/../includes/header.php';

$sql = "
    SELECT
        r.reservation_id,
        r.confirmation_code,
        r.check_in_date,
        r.check_out_date,
        r.guests_count,
        r.status,
        g.full_name AS guest_name,
        g.phone AS guest_phone,
        rt.name AS room_type_name,
        rt.base_rate
    FROM reservations r
    JOIN guests g ON g.guest_id = r.guest_id
    JOIN room_types rt ON rt.room_type_id = r.room_type_id
    ORDER BY r.reservation_id DESC
";
$reservations = $pdo->query($sql)->fetchAll();
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h3 class="mb-0">Reservations</h3>
    <a class="btn btn-primary" href="/hms/reservations/create.php">+ New Reservation</a>
</div>

<div class="card shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-striped table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Code</th>
                        <th>Guest</th>
                        <th>Phone</th>
                        <th>Room Type</th>
                        <th>Check-In</th>
                        <th>Check-Out</th>
                        <th>Guests</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($reservations)): ?>
                        <tr>
                            <td colspan="9" class="text-center text-muted py-4">No reservations found yet.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($reservations as $res): ?>
                            <?php
                            $badge = match ($res['status']) {
                                'Confirmed'   => 'bg-success',
                                'Checked-In'  => 'bg-primary',
                                'Cancelled'   => 'bg-danger',
                                'Completed'   => 'bg-secondary',
                                default       => 'bg-info text-dark',
                            };
                            ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($res['confirmation_code'], ENT_QUOTES, 'UTF-8') ?></strong></td>
                                <td><?= htmlspecialchars($res['guest_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars($res['guest_phone'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars($res['room_type_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars($res['check_in_date'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars($res['check_out_date'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= (int) $res['guests_count'] ?></td>
                                <td><span class="badge <?= $badge ?>"><?= htmlspecialchars($res['status'], ENT_QUOTES, 'UTF-8') ?></span></td>
                                <td>
                                    <?php if ($res['status'] === 'Confirmed' || $res['status'] === 'Pending'): ?>
                                        <a class="btn btn-sm btn-success" href="/hms/frontdesk/checkin.php?reservation_id=<?= (int) $res['reservation_id'] ?>">Check In</a>
                                        <a class="btn btn-sm btn-outline-danger" href="/hms/reservations/cancel.php?id=<?= (int) $res['reservation_id'] ?>">Cancel</a>
                                    <?php else: ?>
                                        <span class="text-muted small">—</span>
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

<?php require_once __DIR__ . '/../includes/footer.php'; ?>