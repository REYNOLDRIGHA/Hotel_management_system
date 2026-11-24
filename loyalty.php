<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';

requireRole(['Administrator', 'Hotel Manager', 'Receptionist', 'Accountant / Cashier']);

require_once __DIR__ . '/../includes/header.php';

// Redemption rate: 10 loyalty points = KES 50 (5 KES per point)
const POINTS_TO_KES = 5.0;

$guests = $pdo->query(
    'SELECT guest_id, full_name, phone, email, loyalty_points
     FROM guests
     ORDER BY full_name ASC'
)->fetchAll();

$selectedGuestId = isset($_GET['guest_id']) ? (int) $_GET['guest_id'] : (int) ($_POST['guest_id'] ?? ($guests[0]['guest_id'] ?? 0));
$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['redeem_points'])) {
    $guestId = (int) ($_POST['guest_id'] ?? 0);
    $pointsToRedeem = (int) ($_POST['points_to_redeem'] ?? 0);
    $stayIdRaw = (int) ($_POST['stay_id'] ?? 0);
    $selectedGuestId = $guestId;

    try {
        if ($guestId <= 0) {
            throw new RuntimeException('Please select a guest.');
        }
        if ($pointsToRedeem <= 0) {
            throw new RuntimeException('Enter a positive number of points to redeem.');
        }

        $pdo->beginTransaction();

        $gStmt = $pdo->prepare('SELECT guest_id, full_name, loyalty_points FROM guests WHERE guest_id = ? FOR UPDATE');
        $gStmt->execute([$guestId]);
        $guestRow = $gStmt->fetch();

        if (!$guestRow) {
            throw new RuntimeException('Guest record not found.');
        }

        $currentPts = (int) $guestRow['loyalty_points'];
        if ($pointsToRedeem > $currentPts) {
            throw new RuntimeException("Insufficient loyalty points. Available balance: {$currentPts} pts.");
        }

        $validStayId = null;
        if ($stayIdRaw > 0) {
            $sStmt = $pdo->prepare('SELECT stay_id FROM stays WHERE stay_id = ? AND guest_id = ?');
            $sStmt->execute([$stayIdRaw, $guestId]);
            if ($sStmt->fetch()) {
                $validStayId = $stayIdRaw;
            }
        }

        $creditKes = round($pointsToRedeem * POINTS_TO_KES, 2);

        $updGuest = $pdo->prepare('UPDATE guests SET loyalty_points = loyalty_points - :pts WHERE guest_id = :gid');
        $updGuest->execute(['pts' => $pointsToRedeem, 'gid' => $guestId]);

        $insLedger = $pdo->prepare(
            "INSERT INTO loyalty_transactions (guest_id, stay_id, txn_type, points, notes)
             VALUES (:guest_id, :stay_id, 'REDEEM', :points, :notes)"
        );
        $insLedger->execute([
            'guest_id' => $guestId,
            'stay_id'  => $validStayId,
            'points'   => $pointsToRedeem,
            'notes'    => "Redeemed {$pointsToRedeem} pts (value: KES " . number_format($creditKes, 2) . ')',
        ]);

        // If linked to a stay, post a discount/credit entry on the folio as a payment credit
        if ($validStayId !== null) {
            $insPay = $pdo->prepare(
                "INSERT INTO payments (stay_id, method, amount, reference_no)
                 VALUES (:stay_id, 'Cash', :amount, :ref_no)"
            );
            $insPay->execute([
                'stay_id' => $validStayId,
                'amount'  => $creditKes,
                'ref_no'  => "LOYALTY-{$pointsToRedeem}PTS",
            ]);
        }

        $pdo->commit();
        $success = "Redeemed {$pointsToRedeem} points (KES " . number_format($creditKes, 2) . ') for ' . $guestRow['full_name'] . '.';

        // Refresh guests list
        $guests = $pdo->query(
            'SELECT guest_id, full_name, phone, email, loyalty_points
             FROM guests
             ORDER BY full_name ASC'
        )->fetchAll();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $errors[] = $e->getMessage();
    }
}

$currentGuest = null;
$ledger = [];
$guestStays = [];

if ($selectedGuestId > 0) {
    $cgStmt = $pdo->prepare('SELECT * FROM guests WHERE guest_id = ?');
    $cgStmt->execute([$selectedGuestId]);
    $currentGuest = $cgStmt->fetch();

    if ($currentGuest) {
        $lStmt = $pdo->prepare(
            'SELECT lt.*, s.stay_id
             FROM loyalty_transactions lt
             LEFT JOIN stays s ON s.stay_id = lt.stay_id
             WHERE lt.guest_id = ?
             ORDER BY lt.txn_id DESC'
        );
        $lStmt->execute([$selectedGuestId]);
        $ledger = $lStmt->fetchAll();

        $gsStmt = $pdo->prepare(
            "SELECT s.stay_id, s.status, r.room_number
             FROM stays s
             JOIN rooms r ON r.room_id = s.room_id
             WHERE s.guest_id = ?
             ORDER BY s.stay_id DESC"
        );
        $gsStmt->execute([$selectedGuestId]);
        $guestStays = $gsStmt->fetchAll();
    }
}
?>

<h3 class="mb-3">Loyalty Points Ledger &amp; Redemption</h3>

<?php if ($success !== ''): ?>
    <div class="alert alert-success"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<?php foreach ($errors as $err): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></div>
<?php endforeach; ?>

<div class="card shadow-sm mb-4">
    <div class="card-body">
        <form method="GET" action="" class="row g-3 align-items-end">
            <div class="col-md-8">
                <label class="form-label" for="guest_id">Select Guest</label>
                <select id="guest_id" name="guest_id" class="form-select">
                    <?php foreach ($guests as $g): ?>
                        <option value="<?= (int) $g['guest_id'] ?>" <?= $selectedGuestId === (int) $g['guest_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($g['full_name'], ENT_QUOTES, 'UTF-8') ?> — <?= (int) $g['loyalty_points'] ?> pts
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <button type="submit" class="btn btn-secondary w-100">Load Ledger</button>
            </div>
        </form>
    </div>
</div>

<?php if ($currentGuest): ?>
    <?php $estValue = (int) $currentGuest['loyalty_points'] * POINTS_TO_KES; ?>
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card shadow-sm"><div class="card-body">
                <div class="text-muted small">Guest</div>
                <div class="fs-5 fw-bold"><?= htmlspecialchars($currentGuest['full_name'], ENT_QUOTES, 'UTF-8') ?></div>
                <div class="small text-muted"><?= htmlspecialchars($currentGuest['phone'], ENT_QUOTES, 'UTF-8') ?></div>
            </div></div>
        </div>
        <div class="col-md-4">
            <div class="card shadow-sm"><div class="card-body">
                <div class="text-muted small">Current Points Balance</div>
                <div class="fs-4 fw-bold text-primary"><?= (int) $currentGuest['loyalty_points'] ?> pts</div>
            </div></div>
        </div>
        <div class="col-md-4">
            <div class="card shadow-sm"><div class="card-body">
                <div class="text-muted small">Redemption Value (@ KES <?= number_format(POINTS_TO_KES, 0) ?>/pt)</div>
                <div class="fs-4 fw-bold text-success">KES <?= number_format($estValue, 2) ?></div>
            </div></div>
        </div>
    </div>

    <div class="card shadow-sm mb-4">
        <div class="card-header bg-white"><strong>Redeem Loyalty Points</strong></div>
        <div class="card-body">
            <form method="POST" action="" class="row g-3 align-items-end">
                <input type="hidden" name="guest_id" value="<?= (int) $currentGuest['guest_id'] ?>">
                <div class="col-md-4">
                    <label class="form-label" for="points_to_redeem">Points to Redeem</label>
                    <input type="number" id="points_to_redeem" name="points_to_redeem" min="1" max="<?= (int) $currentGuest['loyalty_points'] ?>" class="form-control" required>
                </div>
                <div class="col-md-5">
                    <label class="form-label" for="stay_id">Apply Credit to Stay Folio (Optional)</label>
                    <select id="stay_id" name="stay_id" class="form-select">
                        <option value="0">-- Ledger Redemption Only --</option>
                        <?php foreach ($guestStays as $gs): ?>
                            <option value="<?= (int) $gs['stay_id'] ?>">
                                Stay #<?= (int) $gs['stay_id'] ?> — Room <?= htmlspecialchars($gs['room_number'], ENT_QUOTES, 'UTF-8') ?> (<?= htmlspecialchars($gs['status'], ENT_QUOTES, 'UTF-8') ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <button type="submit" name="redeem_points" class="btn btn-primary w-100" <?= (int) $currentGuest['loyalty_points'] <= 0 ? 'disabled' : '' ?>>
                        Redeem Points
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-header bg-white"><strong>Transaction History</strong></div>
        <div class="card-body p-0">
            <table class="table table-striped mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Txn #</th>
                        <th>Type</th>
                        <th>Points</th>
                        <th>Stay</th>
                        <th>Notes</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($ledger)): ?>
                        <tr><td colspan="6" class="text-center text-muted py-4">No loyalty transactions recorded yet.</td></tr>
                    <?php else: ?>
                        <?php foreach ($ledger as $tx): ?>
                            <?php
                            $tBadge = match ($tx['txn_type']) {
                                'EARN'     => 'bg-success',
                                'REDEEM'   => 'bg-warning text-dark',
                                'REVERSAL' => 'bg-danger',
                                default    => 'bg-secondary',
                            };
                            ?>
                            <tr>
                                <td>#<?= (int) $tx['txn_id'] ?></td>
                                <td><span class="badge <?= $tBadge ?>"><?= htmlspecialchars($tx['txn_type'], ENT_QUOTES, 'UTF-8') ?></span></td>
                                <td><strong><?= (int) $tx['points'] ?></strong> pts</td>
                                <td><?= $tx['stay_id'] ? '#' . (int) $tx['stay_id'] : '—' ?></td>
                                <td><?= htmlspecialchars((string) ($tx['notes'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="small text-muted"><?= htmlspecialchars($tx['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>