<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';

requireRole(['Administrator', 'Hotel Manager', 'Receptionist']);

require_once __DIR__ . '/../includes/header.php';

$today = date('Y-m-d');
$tomorrow = date('Y-m-d', strtotime('+1 day'));

$checkIn = $_GET['check_in'] ?? $today;
$checkOut = $_GET['check_out'] ?? $tomorrow;
$roomTypeId = isset($_GET['room_type_id']) && $_GET['room_type_id'] !== '' ? (int) $_GET['room_type_id'] : null;
$guestsCount = isset($_GET['guests']) && (int) $_GET['guests'] > 0 ? (int) $_GET['guests'] : 1;

$roomTypes = $pdo->query('SELECT room_type_id, name, capacity, base_rate FROM room_types ORDER BY base_rate ASC')->fetchAll();

$errors = [];
$availableByType = [];
$nights = 0;

if ($checkOut <= $checkIn) {
    $errors[] = 'Check-out date must be after check-in date.';
} else {
    $startDt = new DateTimeImmutable($checkIn);
    $endDt = new DateTimeImmutable($checkOut);
    $nights = (int) $startDt->diff($endDt)->days;

    // Build list of nights: [check_in, check_out)
    $nightDates = [];
    for ($i = 0; $i < $nights; $i++) {
        $nightDates[] = $startDt->modify("+{$i} day")->format('Y-m-d');
    }

    // Count free rooms per room_type that are not Out of Service and have no stay_nights on any requested night
    $placeholders = implode(',', array_fill(0, count($nightDates), '?'));
    $sql = "
        SELECT
            rt.room_type_id,
            rt.name,
            rt.capacity,
            rt.base_rate,
            COUNT(r.room_id) AS free_rooms
        FROM room_types rt
        JOIN rooms r ON r.room_type_id = rt.room_type_id
        WHERE rt.capacity >= ?
          AND r.status != 'Out of Service'
          AND r.room_id NOT IN (
              SELECT DISTINCT sn.room_id
              FROM stay_nights sn
              WHERE sn.night IN ({$placeholders})
          )
    ";
    $params = [$guestsCount];
    foreach ($nightDates as $nd) {
        $params[] = $nd;
    }

    if ($roomTypeId !== null) {
        $sql .= ' AND rt.room_type_id = ?';
        $params[] = $roomTypeId;
    }

    $sql .= ' GROUP BY rt.room_type_id, rt.name, rt.capacity, rt.base_rate HAVING free_rooms > 0 ORDER BY rt.base_rate ASC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $availableByType = $stmt->fetchAll();
}
?>

<h3 class="mb-3">Search Room Availability</h3>

<div class="card shadow-sm mb-4">
    <div class="card-body">
        <form method="GET" action="" class="row g-3 align-items-end">
            <div class="col-md-3">
                <label class="form-label" for="check_in">Check-In Date</label>
                <input type="date" id="check_in" name="check_in" class="form-control" value="<?= htmlspecialchars($checkIn, ENT_QUOTES, 'UTF-8') ?>" required>
            </div>
            <div class="col-md-3">
                <label class="form-label" for="check_out">Check-Out Date</label>
                <input type="date" id="check_out" name="check_out" class="form-control" value="<?= htmlspecialchars($checkOut, ENT_QUOTES, 'UTF-8') ?>" required>
            </div>
            <div class="col-md-3">
                <label class="form-label" for="room_type_id">Room Type</label>
                <select id="room_type_id" name="room_type_id" class="form-select">
                    <option value="">All Room Types</option>
                    <?php foreach ($roomTypes as $rt): ?>
                        <option value="<?= (int) $rt['room_type_id'] ?>" <?= $roomTypeId === (int) $rt['room_type_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($rt['name'], ENT_QUOTES, 'UTF-8') ?> (Max <?= (int) $rt['capacity'] ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-1">
                <label class="form-label" for="guests">Guests</label>
                <input type="number" id="guests" name="guests" min="1" max="10" class="form-control" value="<?= $guestsCount ?>" required>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100">Search</button>
            </div>
        </form>
    </div>
</div>

<?php foreach ($errors as $err): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></div>
<?php endforeach; ?>

<?php if (empty($errors)): ?>
    <div class="card shadow-sm">
        <div class="card-header bg-white">
            <strong>Available Room Types</strong>
            <span class="text-muted ms-2">(<?= $nights ?> <?= $nights === 1 ? 'night' : 'nights' ?>: <?= htmlspecialchars($checkIn, ENT_QUOTES, 'UTF-8') ?> to <?= htmlspecialchars($checkOut, ENT_QUOTES, 'UTF-8') ?>)</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-striped table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Room Type</th>
                            <th>Max Guests</th>
                            <th>Rate / Night (KES)</th>
                            <th>Total (<?= $nights ?> nights)</th>
                            <th>Free Rooms</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($availableByType)): ?>
                            <tr>
                                <td colspan="6" class="text-center text-muted py-4">No rooms available for the selected dates and criteria.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($availableByType as $row): ?>
                                <?php $totalRate = (float) $row['base_rate'] * $nights; ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8') ?></strong></td>
                                    <td><?= (int) $row['capacity'] ?></td>
                                    <td>KES <?= number_format((float) $row['base_rate'], 2) ?></td>
                                    <td>KES <?= number_format($totalRate, 2) ?></td>
                                    <td><span class="badge bg-success"><?= (int) $row['free_rooms'] ?> available</span></td>
                                    <td>
                                        <a class="btn btn-sm btn-success" href="/hms/reservations/create.php?room_type_id=<?= (int) $row['room_type_id'] ?>&check_in=<?= urlencode($checkIn) ?>&check_out=<?= urlencode($checkOut) ?>&guests=<?= $guestsCount ?>">
                                            Book Now
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>