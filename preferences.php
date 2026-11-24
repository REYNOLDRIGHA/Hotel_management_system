<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';

requireRole(['Administrator', 'Hotel Manager', 'Receptionist', 'Housekeeping Supervisor', 'Housekeeper']);

require_once __DIR__ . '/../includes/header.php';

$canEdit = hasRole(['Administrator', 'Hotel Manager', 'Receptionist']);
$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canEdit) {
        $errors[] = 'Only Front Desk, Manager, or Administrator staff can update preferences or special requests.';
    } else {
        try {
            if (isset($_POST['save_guest_prefs'])) {
                $guestId = (int) ($_POST['guest_id'] ?? 0);
                $prefs = trim((string) ($_POST['preferences'] ?? ''));

                if ($guestId <= 0) {
                    throw new RuntimeException('Please select a valid guest.');
                }

                $updGuest = $pdo->prepare('UPDATE guests SET preferences = :prefs WHERE guest_id = :gid');
                $updGuest->execute([
                    'prefs' => $prefs !== '' ? $prefs : null,
                    'gid'   => $guestId,
                ]);
                $success = 'Guest preferences updated.';
            }

            if (isset($_POST['save_special_request'])) {
                $resId = (int) ($_POST['reservation_id'] ?? 0);
                $requests = trim((string) ($_POST['special_requests'] ?? ''));
                $isFlagged = isset($_POST['is_flagged']) ? 1 : 0;

                if ($resId <= 0) {
                    throw new RuntimeException('Please select a valid reservation.');
                }

                $updRes = $pdo->prepare(
                    'UPDATE reservations
                     SET special_requests = :req, is_flagged = :flagged
                     WHERE reservation_id = :rid'
                );
                $updRes->execute([
                    'req'     => $requests !== '' ? $requests : null,
                    'flagged' => $isFlagged,
                    'rid'     => $resId,
                ]);
                $success = 'Reservation special request & staff flag updated.';
            }
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
}

$guests = $pdo->query(
    'SELECT guest_id, full_name, phone, preferences, loyalty_points
     FROM guests
     ORDER BY full_name ASC'
)->fetchAll();

$reservations = $pdo->query(
    'SELECT r.reservation_id, r.confirmation_code, r.check_in_date, r.check_out_date,
            r.status, r.special_requests, r.is_flagged,
            g.full_name AS guest_name, g.preferences AS guest_preferences,
            rt.name AS room_type_name
     FROM reservations r
     JOIN guests g ON g.guest_id = r.guest_id
     JOIN room_types rt ON rt.room_type_id = r.room_type_id
     ORDER BY r.is_flagged DESC, r.reservation_id DESC'
)->fetchAll();

$flaggedItems = array_filter(
    $reservations,
    static fn(array $row): bool => (int) ($row['is_flagged'] ?? 0) === 1
        || trim((string) ($row['special_requests'] ?? '')) !== ''
        || trim((string) ($row['guest_preferences'] ?? '')) !== ''
);
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h3 class="mb-0">Guest Preferences &amp; Special Requests</h3>
        <small class="text-muted">Record standing guest preferences, special requests, and staff flags (FR-020)</small>
    </div>
    <a href="/hms/reservations/list.php" class="btn btn-outline-secondary btn-sm">Back to Reservations</a>
</div>

<?php if ($success !== ''): ?>
    <div class="alert alert-success"><?= h($success) ?></div>
<?php endif; ?>

<?php foreach ($errors as $err): ?>
    <div class="alert alert-danger"><?= h($err) ?></div>
<?php endforeach; ?>

<div class="card shadow-sm mb-4">
    <div class="card-header">
        <strong>Staff Alerts &amp; Flagged Guest Notes (<?= count($flaggedItems) ?>)</strong>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-striped mb-0">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Guest</th>
                        <th>Room Type &amp; Dates</th>
                        <th>Standing Preferences</th>
                        <th>Special Request</th>
                        <th>Flag</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($flaggedItems)): ?>
                        <tr>
                            <td colspan="6" class="text-center text-muted py-3">
                                No preferences or special requests recorded yet.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($flaggedItems as $item): ?>
                            <?php $flagged = (int) ($item['is_flagged'] ?? 0) === 1; ?>
                            <tr class="<?= $flagged ? 'table-warning' : '' ?>">
                                <td class="fw-semibold"><?= h((string) $item['confirmation_code']) ?></td>
                                <td><?= h((string) $item['guest_name']) ?></td>
                                <td class="small">
                                    <?= h((string) $item['room_type_name']) ?><br>
                                    <span class="text-muted"><?= h((string) $item['check_in_date']) ?> &rarr; <?= h((string) $item['check_out_date']) ?></span>
                                </td>
                                <td class="small"><?= h((string) ($item['guest_preferences'] ?? '—')) ?></td>
                                <td class="small"><?= h((string) ($item['special_requests'] ?? '—')) ?></td>
                                <td>
                                    <?php if ($flagged): ?>
                                        <span class="badge bg-danger">Flagged for Staff</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Note</span>
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

<?php if ($canEdit): ?>
    <div class="row g-4">
        <div class="col-lg-6">
            <div class="card shadow-sm">
                <div class="card-header"><strong>Update Standing Guest Preferences</strong></div>
                <div class="card-body">
                    <form method="POST" action="">
                        <div class="mb-3">
                            <label class="form-label" for="guest_id">Guest</label>
                            <select id="guest_id" name="guest_id" class="form-select" required>
                                <?php foreach ($guests as $g): ?>
                                    <option value="<?= (int) $g['guest_id'] ?>">
                                        <?= h((string) $g['full_name']) ?> (<?= h((string) $g['phone']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="preferences">Preferences (e.g. High floor, quiet room, extra pillows)</label>
                            <input type="text" id="preferences" name="preferences" class="form-control"
                                   placeholder="Enter guest preferences or leave blank to clear">
                        </div>
                        <button type="submit" name="save_guest_prefs" class="btn btn-primary">Save Preferences</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card shadow-sm">
                <div class="card-header"><strong>Record Reservation Special Request</strong></div>
                <div class="card-body">
                    <form method="POST" action="">
                        <div class="mb-3">
                            <label class="form-label" for="reservation_id">Reservation</label>
                            <select id="reservation_id" name="reservation_id" class="form-select" required>
                                <?php foreach ($reservations as $r): ?>
                                    <option value="<?= (int) $r['reservation_id'] ?>">
                                        <?= h((string) $r['confirmation_code']) ?> — <?= h((string) $r['guest_name']) ?> (<?= h((string) $r['status']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="special_requests">Special Request</label>
                            <input type="text" id="special_requests" name="special_requests" class="form-control"
                                   placeholder="e.g. Late arrival 11 PM, baby crib, airport pickup">
                        </div>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" id="is_flagged" name="is_flagged" value="1" checked>
                            <label class="form-check-label" for="is_flagged">
                                Flag prominently for Front Desk &amp; Housekeeping staff
                            </label>
                        </div>
                        <button type="submit" name="save_special_request" class="btn btn-primary">Save Special Request</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>