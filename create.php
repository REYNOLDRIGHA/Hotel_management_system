<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
requireRole(['Administrator', 'Hotel Manager', 'Receptionist', 'Guest']);

$today = date('Y-m-d');
$tomorrow = date('Y-m-d', strtotime('+1 day'));

$selectedRoomTypeId = isset($_GET['room_type_id']) && $_GET['room_type_id'] !== ''
    ? (int) $_GET['room_type_id']
    : (int) ($_POST['room_type_id'] ?? 0);
$checkIn = (string) ($_GET['check_in'] ?? $_POST['check_in'] ?? $today);
$checkOut = (string) ($_GET['check_out'] ?? $_POST['check_out'] ?? $tomorrow);
$guestsCount = max(1, (int) ($_GET['guests'] ?? $_POST['guests_count'] ?? 1));

$roomTypes = $pdo->query('SELECT room_type_id, name, capacity, base_rate FROM room_types ORDER BY base_rate ASC')->fetchAll();
$existingGuests = $pdo->query('SELECT guest_id, full_name, phone, id_type, id_number FROM guests ORDER BY full_name ASC')->fetchAll();

$errors = [];
$createdCode = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $selectedRoomTypeId = (int) ($_POST['room_type_id'] ?? 0);
    $checkIn = trim((string) ($_POST['check_in'] ?? ''));
    $checkOut = trim((string) ($_POST['check_out'] ?? ''));
    $guestsCount = max(1, (int) ($_POST['guests_count'] ?? 1));

    $guestMode = $_POST['guest_mode'] ?? 'existing';
    $existingGuestId = (int) ($_POST['existing_guest_id'] ?? 0);
    $fullName = trim((string) ($_POST['full_name'] ?? ''));
    $phone = trim((string) ($_POST['phone'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $idType = trim((string) ($_POST['id_type'] ?? ''));
    $idNumber = trim((string) ($_POST['id_number'] ?? ''));

    if ($selectedRoomTypeId <= 0) {
        $errors[] = 'Please select a room type.';
    }
    if ($checkIn === '' || $checkOut === '' || $checkOut <= $checkIn) {
        $errors[] = 'Check-out date must be strictly after check-in date.';
    }

    if ($guestMode === 'existing') {
        if ($existingGuestId <= 0) {
            $errors[] = 'Please select an existing guest or switch to New Guest.';
        }
    } else {
        if ($fullName === '' || $phone === '') {
            $errors[] = 'Full name and phone number are required for a new guest.';
        }
    }

    if (empty($errors)) {
        $startDt = new DateTimeImmutable($checkIn);
        $endDt = new DateTimeImmutable($checkOut);
        $nights = (int) $startDt->diff($endDt)->days;

        $nightDates = [];
        for ($i = 0; $i < $nights; $i++) {
            $nightDates[] = $startDt->modify("+{$i} day")->format('Y-m-d');
        }

        try {
            $pdo->beginTransaction();

            // Verify room type & capacity
            $rtStmt = $pdo->prepare('SELECT room_type_id, name, capacity, base_rate FROM room_types WHERE room_type_id = ?');
            $rtStmt->execute([$selectedRoomTypeId]);
            $rt = $rtStmt->fetch();

            if (!$rt) {
                throw new RuntimeException('Selected room type does not exist.');
            }
            if ($guestsCount > (int) $rt['capacity']) {
                throw new RuntimeException('Guest count exceeds room type capacity (' . (int) $rt['capacity'] . ').');
            }

            // Resolve or create guest
            if ($guestMode === 'existing') {
                $gStmt = $pdo->prepare('SELECT guest_id FROM guests WHERE guest_id = ?');
                $gStmt->execute([$existingGuestId]);
                if (!$gStmt->fetch()) {
                    throw new RuntimeException('Selected guest record was not found.');
                }
                $guestId = $existingGuestId;
            } else {
                $insGuest = $pdo->prepare(
                    'INSERT INTO guests (full_name, phone, email, id_type, id_number)
                     VALUES (:full_name, :phone, :email, :id_type, :id_number)'
                );
                $insGuest->execute([
                    'full_name' => $fullName,
                    'phone'     => $phone,
                    'email'     => $email !== '' ? $email : null,
                    'id_type'   => in_array($idType, ['National ID', 'Passport'], true) ? $idType : null,
                    'id_number' => $idNumber !== '' ? $idNumber : null,
                ]);
                $guestId = (int) $pdo->lastInsertId();
            }

            // Find a room of this type free across all requested nights, locking candidate rooms
            $placeholders = implode(',', array_fill(0, count($nightDates), '?'));
            $roomSql = "
                SELECT r.room_id
                FROM rooms r
                WHERE r.room_type_id = ?
                  AND r.status != 'Out of Service'
                  AND r.room_id NOT IN (
                      SELECT DISTINCT sn.room_id
                      FROM stay_nights sn
                      WHERE sn.night IN ({$placeholders})
                  )
                ORDER BY r.room_id ASC
                LIMIT 1
                FOR UPDATE
            ";
            $roomParams = [$selectedRoomTypeId];
            foreach ($nightDates as $nd) {
                $roomParams[] = $nd;
            }
            $roomStmt = $pdo->prepare($roomSql);
            $roomStmt->execute($roomParams);
            $freeRoom = $roomStmt->fetch();

            if (!$freeRoom) {
                throw new RuntimeException('No room of this type is available for all selected nights.');
            }
            $allocatedRoomId = (int) $freeRoom['room_id'];

            $confirmationCode = 'HMS-' . strtoupper(bin2hex(random_bytes(4)));

            $insRes = $pdo->prepare(
                'INSERT INTO reservations (confirmation_code, guest_id, room_type_id, check_in_date, check_out_date, guests_count, status)
                 VALUES (:code, :guest_id, :room_type_id, :check_in, :check_out, :guests_count, "Confirmed")'
            );
            $insRes->execute([
                'code'         => $confirmationCode,
                'guest_id'     => $guestId,
                'room_type_id' => $selectedRoomTypeId,
                'check_in'     => $checkIn,
                'check_out'    => $checkOut,
                'guests_count' => $guestsCount,
            ]);
            $reservationId = (int) $pdo->lastInsertId();

            // Reserve each night in stay_nights (enforced by UNIQUE (room_id, night))
            $insNight = $pdo->prepare(
                'INSERT INTO stay_nights (stay_id, reservation_id, room_id, night)
                 VALUES (NULL, :reservation_id, :room_id, :night)'
            );
            foreach ($nightDates as $nd) {
                $insNight->execute([
                    'reservation_id' => $reservationId,
                    'room_id'        => $allocatedRoomId,
                    'night'          => $nd,
                ]);
            }

            $pdo->commit();
            $createdCode = $confirmationCode;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            // SQLSTATE 23000 = integrity/unique constraint violation (e.g., overlapping night)
            if ($e->getCode() === '23000') {
                $errors[] = 'Booking conflict detected: a room was just taken for one or more selected nights.';
            } else {
                $errors[] = 'Database error while creating reservation: ' . $e->getMessage();
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

<div class="d-flex justify-content-between align-items-center mb-3">
    <h3 class="mb-0">Create Reservation</h3>
    <a class="btn btn-outline-secondary" href="/hms/reservations/list.php">View All Reservations</a>
</div>

<?php if ($createdCode !== null): ?>
    <div class="alert alert-success">
        Reservation created successfully! Confirmation Code:
        <strong><?= htmlspecialchars($createdCode, ENT_QUOTES, 'UTF-8') ?></strong>.
        <a href="/hms/reservations/list.php" class="alert-link ms-2">Go to Reservations</a>
    </div>
<?php endif; ?>

<?php foreach ($errors as $err): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></div>
<?php endforeach; ?>

<div class="card shadow-sm">
    <div class="card-body">
        <form method="POST" action="">
            <div class="row g-3 mb-4">
                <div class="col-md-4">
                    <label class="form-label" for="room_type_id">Room Type</label>
                    <select id="room_type_id" name="room_type_id" class="form-select" required>
                        <option value="">-- Select Room Type --</option>
                        <?php foreach ($roomTypes as $rt): ?>
                            <option value="<?= (int) $rt['room_type_id'] ?>" <?= $selectedRoomTypeId === (int) $rt['room_type_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($rt['name'], ENT_QUOTES, 'UTF-8') ?> (KES <?= number_format((float) $rt['base_rate'], 2) ?>/night, Max <?= (int) $rt['capacity'] ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="check_in">Check-In Date</label>
                    <input type="date" id="check_in" name="check_in" class="form-control" value="<?= htmlspecialchars($checkIn, ENT_QUOTES, 'UTF-8') ?>" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="check_out">Check-Out Date</label>
                    <input type="date" id="check_out" name="check_out" class="form-control" value="<?= htmlspecialchars($checkOut, ENT_QUOTES, 'UTF-8') ?>" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="guests_count">Guests</label>
                    <input type="number" id="guests_count" name="guests_count" min="1" max="10" class="form-control" value="<?= $guestsCount ?>" required>
                </div>
            </div>

            <hr>
            <h5 class="mb-3">Guest Details</h5>

            <div class="mb-3">
                <div class="form-check form-check-inline">
                    <input class="form-check-input" type="radio" name="guest_mode" id="mode_existing" value="existing" <?= (($_POST['guest_mode'] ?? 'existing') === 'existing') ? 'checked' : '' ?>>
                    <label class="form-check-label" for="mode_existing">Existing Guest</label>
                </div>
                <div class="form-check form-check-inline">
                    <input class="form-check-input" type="radio" name="guest_mode" id="mode_new" value="new" <?= (($_POST['guest_mode'] ?? '') === 'new') ? 'checked' : '' ?>>
                    <label class="form-check-label" for="mode_new">New Guest</label>
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label" for="existing_guest_id">Select Existing Guest</label>
                <select id="existing_guest_id" name="existing_guest_id" class="form-select">
                    <option value="">-- Choose Guest --</option>
                    <?php foreach ($existingGuests as $g): ?>
                        <option value="<?= (int) $g['guest_id'] ?>" <?= ((int) ($_POST['existing_guest_id'] ?? 0) === (int) $g['guest_id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($g['full_name'], ENT_QUOTES, 'UTF-8') ?> (<?= htmlspecialchars($g['phone'], ENT_QUOTES, 'UTF-8') ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="row g-3 mb-4">
                <div class="col-md-4">
                    <label class="form-label" for="full_name">New Guest Full Name</label>
                    <input type="text" id="full_name" name="full_name" class="form-control" value="<?= htmlspecialchars((string) ($_POST['full_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="phone">Phone Number</label>
                    <input type="text" id="phone" name="phone" class="form-control" placeholder="+2547..." value="<?= htmlspecialchars((string) ($_POST['phone'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="email">Email (Optional)</label>
                    <input type="email" id="email" name="email" class="form-control" value="<?= htmlspecialchars((string) ($_POST['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="id_type">ID Type (Optional at Booking)</label>
                    <select id="id_type" name="id_type" class="form-select">
                        <option value="">-- None --</option>
                        <option value="National ID">National ID</option>
                        <option value="Passport">Passport</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="id_number">ID / Passport Number</label>
                    <input type="text" id="id_number" name="id_number" class="form-control" value="<?= htmlspecialchars((string) ($_POST['id_number'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                </div>
            </div>

            <button type="submit" class="btn btn-primary">Confirm Reservation</button>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>