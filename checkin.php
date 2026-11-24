<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';

requireRole(['Administrator', 'Hotel Manager', 'Receptionist']);

require_once __DIR__ . '/../includes/header.php';

$preselectedResId = isset($_GET['reservation_id']) ? (int) $_GET['reservation_id'] : 0;

$confirmedReservations = $pdo->query(
    "SELECT r.reservation_id, r.confirmation_code, r.check_in_date, r.check_out_date, r.guests_count,
            r.room_type_id, g.guest_id, g.full_name, g.phone, g.id_type, g.id_number, rt.name AS room_type_name, rt.base_rate
     FROM reservations r
     JOIN guests g ON g.guest_id = r.guest_id
     JOIN room_types rt ON rt.room_type_id = r.room_type_id
     WHERE r.status IN ('Pending', 'Confirmed')
     ORDER BY r.check_in_date ASC, r.reservation_id ASC"
)->fetchAll();

$availableRooms = $pdo->query(
    "SELECT r.room_id, r.room_number, r.floor, r.status, r.room_type_id, rt.name AS room_type_name, rt.base_rate
     FROM rooms r
     JOIN room_types rt ON rt.room_type_id = r.room_type_id
     WHERE r.status = 'Available'
     ORDER BY r.room_number ASC"
)->fetchAll();

$errors = [];
$checkedInStayId = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $reservationId = (int) ($_POST['reservation_id'] ?? 0);
    $roomId = (int) ($_POST['room_id'] ?? 0);
    $idType = trim((string) ($_POST['id_type'] ?? ''));
    $idNumber = trim((string) ($_POST['id_number'] ?? ''));

    if (!in_array($idType, ['National ID', 'Passport'], true) || $idNumber === '') {
        $errors[] = 'Valid government ID type and ID/Passport number are required at check-in (FR-015 / BR-005).';
    }
    if ($roomId <= 0) {
        $errors[] = 'Please select an available room to assign.';
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            // Lock selected room
            $roomStmt = $pdo->prepare(
                'SELECT r.room_id, r.room_number, r.room_type_id, r.status, rt.name AS room_type_name, rt.base_rate
                 FROM rooms r
                 JOIN room_types rt ON rt.room_type_id = r.room_type_id
                 WHERE r.room_id = ? FOR UPDATE'
            );
            $roomStmt->execute([$roomId]);
            $room = $roomStmt->fetch();

            if (!$room) {
                throw new RuntimeException('Selected room was not found.');
            }
            if ($room['status'] !== 'Available') {
                throw new RuntimeException('Selected room is currently ' . $room['status'] . ' and cannot be assigned.');
            }

            $guestId = 0;
            $nights = 1;
            $nightDates = [];

            if ($reservationId > 0) {
                $resStmt = $pdo->prepare(
                    "SELECT * FROM reservations WHERE reservation_id = ? AND status IN ('Pending', 'Confirmed') FOR UPDATE"
                );
                $resStmt->execute([$reservationId]);
                $res = $resStmt->fetch();

                if (!$res) {
                    throw new RuntimeException('Reservation not found or already checked in/cancelled.');
                }

                $guestId = (int) $res['guest_id'];
                $startDt = new DateTimeImmutable((string) $res['check_in_date']);
                $endDt = new DateTimeImmutable((string) $res['check_out_date']);
                $nights = max(1, (int) $startDt->diff($endDt)->days);

                for ($i = 0; $i < $nights; $i++) {
                    $nightDates[] = $startDt->modify("+{$i} day")->format('Y-m-d');
                }

                // Update reservation status
                $updRes = $pdo->prepare("UPDATE reservations SET status = 'Checked-In' WHERE reservation_id = ?");
                $updRes->execute([$reservationId]);

                // Release any unattached reservation stay_nights so we bind nights cleanly to the assigned room & stay
                $pdo->prepare('DELETE FROM stay_nights WHERE reservation_id = ? AND stay_id IS NULL')->execute([$reservationId]);
            } else {
                // Walk-in guest details
                $walkName = trim((string) ($_POST['walkin_name'] ?? ''));
                $walkPhone = trim((string) ($_POST['walkin_phone'] ?? ''));
                $walkEmail = trim((string) ($_POST['walkin_email'] ?? ''));
                $walkNights = max(1, (int) ($_POST['walkin_nights'] ?? 1));

                if ($walkName === '' || $walkPhone === '') {
                    throw new RuntimeException('Walk-in guest full name and phone number are required.');
                }

                $insGuest = $pdo->prepare(
                    'INSERT INTO guests (full_name, phone, email, id_type, id_number)
                     VALUES (:name, :phone, :email, :id_type, :id_number)'
                );
                $insGuest->execute([
                    'name'      => $walkName,
                    'phone'     => $walkPhone,
                    'email'     => $walkEmail !== '' ? $walkEmail : null,
                    'id_type'   => $idType,
                    'id_number' => $idNumber,
                ]);
                $guestId = (int) $pdo->lastInsertId();
                $nights = $walkNights;

                $startDt = new DateTimeImmutable(date('Y-m-d'));
                for ($i = 0; $i < $nights; $i++) {
                    $nightDates[] = $startDt->modify("+{$i} day")->format('Y-m-d');
                }
            }

            // Update guest ID record
            $updGuest = $pdo->prepare('UPDATE guests SET id_type = ?, id_number = ? WHERE guest_id = ?');
            $updGuest->execute([$idType, $idNumber, $guestId]);

            // Create active stay
            $insStay = $pdo->prepare(
                "INSERT INTO stays (reservation_id, guest_id, room_id, check_in_at, status)
                 VALUES (:res_id, :guest_id, :room_id, NOW(), 'Active')"
            );
            $insStay->execute([
                'res_id'   => $reservationId > 0 ? $reservationId : null,
                'guest_id' => $guestId,
                'room_id'  => $roomId,
            ]);
            $stayId = (int) $pdo->lastInsertId();

            // Record stay_nights on assigned room (enforces UNIQUE (room_id, night))
            $insNight = $pdo->prepare(
                'INSERT INTO stay_nights (stay_id, reservation_id, room_id, night)
                 VALUES (:stay_id, :res_id, :room_id, :night)'
            );
            foreach ($nightDates as $nd) {
                $insNight->execute([
                    'stay_id' => $stayId,
                    'res_id'  => $reservationId > 0 ? $reservationId : null,
                    'room_id' => $roomId,
                    'night'   => $nd,
                ]);
            }

            // Post initial room charges to folio
            $roomTotal = (float) $room['base_rate'] * $nights;
            $insCharge = $pdo->prepare(
                'INSERT INTO charges (stay_id, description, amount) VALUES (:stay_id, :desc, :amount)'
            );
            $insCharge->execute([
                'stay_id' => $stayId,
                'desc'    => "Room {$room['room_number']} ({$room['room_type_name']}) x {$nights} night(s)",
                'amount'  => $roomTotal,
            ]);

            // Mark room Occupied
            $updRoom = $pdo->prepare("UPDATE rooms SET status = 'Occupied' WHERE room_id = ?");
            $updRoom->execute([$roomId]);

            $pdo->commit();
            $checkedInStayId = $stayId;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e->getCode() === '23000') {
                $errors[] = 'Room conflict on one or more stay nights. Choose another available room.';
            } else {
                $errors[] = 'Database error during check-in: ' . $e->getMessage();
            }
        } catch (RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = $e->getMessage();
        }
    }
}
?>

<h3 class="mb-3">Guest Check-In</h3>

<?php if ($checkedInStayId !== null): ?>
    <div class="alert alert-success">
        Guest checked in successfully! Stay #<?= (int) $checkedInStayId ?> is now active.
        <a href="/hms/billing/folio.php?stay_id=<?= (int) $checkedInStayId ?>" class="alert-link ms-2">Open Folio</a>
        <a href="/hms/frontdesk/checkout.php?stay_id=<?= (int) $checkedInStayId ?>" class="alert-link ms-2">Go to Check-Out</a>
    </div>
<?php endif; ?>

<?php foreach ($errors as $err): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></div>
<?php endforeach; ?>

<div class="card shadow-sm">
    <div class="card-body">
        <form method="POST" action="">
            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <label class="form-label" for="reservation_id">Existing Reservation (or leave blank for Walk-In)</label>
                    <select id="reservation_id" name="reservation_id" class="form-select">
                        <option value="0">-- Walk-In Guest (No Reservation) --</option>
                        <?php foreach ($confirmedReservations as $r): ?>
                            <?php $isSelected = ($preselectedResId === (int) $r['reservation_id']) || ((int) ($_POST['reservation_id'] ?? 0) === (int) $r['reservation_id']); ?>
                            <option value="<?= (int) $r['reservation_id'] ?>" <?= $isSelected ? 'selected' : '' ?>>
                                <?= htmlspecialchars($r['confirmation_code'], ENT_QUOTES, 'UTF-8') ?> —
                                <?= htmlspecialchars($r['full_name'], ENT_QUOTES, 'UTF-8') ?>
                                (<?= htmlspecialchars($r['room_type_name'], ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars($r['check_in_date'], ENT_QUOTES, 'UTF-8') ?> to <?= htmlspecialchars($r['check_out_date'], ENT_QUOTES, 'UTF-8') ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-6">
                    <label class="form-label" for="room_id">Assign Available Room</label>
                    <select id="room_id" name="room_id" class="form-select" required>
                        <option value="">-- Select Available Room --</option>
                        <?php foreach ($availableRooms as $rm): ?>
                            <option value="<?= (int) $rm['room_id'] ?>" <?= ((int) ($_POST['room_id'] ?? 0) === (int) $rm['room_id']) ? 'selected' : '' ?>>
                                Room <?= htmlspecialchars($rm['room_number'], ENT_QUOTES, 'UTF-8') ?> —
                                <?= htmlspecialchars($rm['room_type_name'], ENT_QUOTES, 'UTF-8') ?>
                                (KES <?= number_format((float) $rm['base_rate'], 2) ?>/night)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <h5 class="mb-3">Mandatory Guest Identification (FR-015)</h5>
            <div class="row g-3 mb-4">
                <div class="col-md-4">
                    <label class="form-label" for="id_type">ID Type</label>
                    <select id="id_type" name="id_type" class="form-select" required>
                        <option value="">-- Select ID Type --</option>
                        <option value="National ID" <?= (($_POST['id_type'] ?? '') === 'National ID') ? 'selected' : '' ?>>National ID</option>
                        <option value="Passport" <?= (($_POST['id_type'] ?? '') === 'Passport') ? 'selected' : '' ?>>Passport</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="id_number">ID / Passport Number</label>
                    <input type="text" id="id_number" name="id_number" class="form-control" value="<?= htmlspecialchars((string) ($_POST['id_number'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" required>
                </div>
            </div>

            <hr>
            <h5 class="mb-3">Walk-In Details (Only if no reservation selected)</h5>
            <div class="row g-3 mb-4">
                <div class="col-md-3">
                    <label class="form-label" for="walkin_name">Guest Full Name</label>
                    <input type="text" id="walkin_name" name="walkin_name" class="form-control" value="<?= htmlspecialchars((string) ($_POST['walkin_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="walkin_phone">Phone Number</label>
                    <input type="text" id="walkin_phone" name="walkin_phone" class="form-control" value="<?= htmlspecialchars((string) ($_POST['walkin_phone'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="walkin_email">Email (Optional)</label>
                    <input type="email" id="walkin_email" name="walkin_email" class="form-control" value="<?= htmlspecialchars((string) ($_POST['walkin_email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="walkin_nights">Number of Nights</label>
                    <input type="number" id="walkin_nights" name="walkin_nights" min="1" max="30" class="form-control" value="<?= max(1, (int) ($_POST['walkin_nights'] ?? 1)) ?>">
                </div>
            </div>

            <button type="submit" class="btn btn-success">Complete Check-In</button>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>