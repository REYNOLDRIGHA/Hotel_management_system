<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/header.php';

$sql = "
    SELECT
        r.room_id,
        r.room_number,
        r.floor,
        r.status,
        rt.name AS room_type_name,
        rt.capacity,
        rt.base_rate
    FROM rooms r
    JOIN room_types rt ON rt.room_type_id = r.room_type_id
    ORDER BY r.floor ASC, r.room_number ASC
";
$rooms = $pdo->query($sql)->fetchAll();

$badgeMap = [
    'Available'      => 'bg-success',
    'Reserved'       => 'bg-info text-dark',
    'Occupied'       => 'bg-primary',
    'Dirty'          => 'bg-danger',
    'Cleaning'       => 'bg-warning text-dark',
    'Out of Service' => 'bg-secondary',
];
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h3 class="mb-0">Rooms &amp; Real-Time Status</h3>
    <a class="btn btn-primary" href="/hms/rooms/search.php">Check Availability</a>
</div>

<div class="card shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-striped table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Room #</th>
                        <th>Floor</th>
                        <th>Room Type</th>
                        <th>Capacity</th>
                        <th>Base Rate</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rooms as $room): ?>
                        <?php $badgeClass = $badgeMap[$room['status']] ?? 'bg-secondary'; ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($room['room_number'], ENT_QUOTES, 'UTF-8') ?></strong></td>
                            <td><?= (int) $room['floor'] ?></td>
                            <td><?= htmlspecialchars($room['room_type_name'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= (int) $room['capacity'] ?></td>
                            <td>KES <?= number_format((float) $room['base_rate'], 2) ?></td>
                            <td><span class="badge <?= $badgeClass ?>"><?= htmlspecialchars($room['status'], ENT_QUOTES, 'UTF-8') ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>