<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';

requireRole(['Administrator', 'Hotel Manager', 'Receptionist', 'Accountant / Cashier']);

require_once __DIR__ . '/../includes/header.php';

$currentUserId = (int) ($_SESSION['user_id'] ?? 0);
$canApproveRefunds = hasRole(['Administrator', 'Hotel Manager']);

$stays = $pdo->query(
    "SELECT s.stay_id, s.status, s.check_in_at, g.guest_id, g.full_name, g.loyalty_points, r.room_number
     FROM stays s
     JOIN guests g ON g.guest_id = s.guest_id
     JOIN rooms r ON r.room_id = s.room_id
     ORDER BY s.stay_id DESC"
)->fetchAll();

$stayId = isset($_GET['stay_id']) ? (int) $_GET['stay_id'] : (int) ($_POST['stay_id'] ?? ($stays[0]['stay_id'] ?? 0));
$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $stayId = (int) ($_POST['stay_id'] ?? 0);

    try {
        if ($stayId <= 0) {
            throw new RuntimeException('Please select a valid stay.');
        }

        $stayStmt = $pdo->prepare('SELECT * FROM stays WHERE stay_id = ?');
        $stayStmt->execute([$stayId]);
        $stayRow = $stayStmt->fetch();
        if (!$stayRow) {
            throw new RuntimeException('Stay not found.');
        }

        if (isset($_POST['add_charge'])) {
            $desc = trim((string) ($_POST['description'] ?? ''));
            $amount = (float) ($_POST['amount'] ?? 0);

            if ($desc === '' || $amount <= 0) {
                throw new RuntimeException('Charge description and a positive KES amount are required.');
            }

            $ins = $pdo->prepare('INSERT INTO charges (stay_id, description, amount) VALUES (:stay_id, :desc, :amount)');
            $ins->execute([
                'stay_id' => $stayId,
                'desc'    => $desc,
                'amount'  => round($amount, 2),
            ]);
            $success = 'Charge added to folio.';
        }

        if (isset($_POST['add_payment'])) {
            $method = (string) ($_POST['method'] ?? '');
            $amount = (float) ($_POST['pay_amount'] ?? 0);
            $refNo = trim((string) ($_POST['reference_no'] ?? ''));

            if (!in_array($method, ['Cash', 'Card', 'Mobile Money'], true)) {
                throw new RuntimeException('Please choose a valid payment method.');
            }
            if ($amount <= 0) {
                throw new RuntimeException('Payment amount must be greater than zero.');
            }

            $pdo->beginTransaction();

            $insPay = $pdo->prepare(
                'INSERT INTO payments (stay_id, method, amount, reference_no)
                 VALUES (:stay_id, :method, :amount, :ref_no)'
            );
            $insPay->execute([
                'stay_id' => $stayId,
                'method'  => $method,
                'amount'  => round($amount, 2),
                'ref_no'  => $refNo !== '' ? $refNo : null,
            ]);

            // Earn 1 loyalty point per 100 KES paid (simple weekend ledger rule)
            $earnedPoints = (int) floor($amount / 100);
            if ($earnedPoints > 0) {
                $guestId = (int) $stayRow['guest_id'];
                $insLedger = $pdo->prepare(
                    "INSERT INTO loyalty_transactions (guest_id, stay_id, txn_type, points, notes)
                     VALUES (:guest_id, :stay_id, 'EARN', :points, :notes)"
                );
                $insLedger->execute([
                    'guest_id' => $guestId,
                    'stay_id'  => $stayId,
                    'points'   => $earnedPoints,
                    'notes'    => "Earned on payment of KES " . number_format($amount, 2),
                ]);

                $updPts = $pdo->prepare('UPDATE guests SET loyalty_points = loyalty_points + :pts WHERE guest_id = :gid');
                $updPts->execute(['pts' => $earnedPoints, 'gid' => $guestId]);
            }

            $pdo->commit();
            $success = 'Payment recorded' . ($earnedPoints > 0 ? " (+{$earnedPoints} loyalty points earned)." : '.');
        }

        // FR-025: Initiate refund / reversal request (Receptionist, Accountant / Cashier, Manager, Admin)
        if (isset($_POST['request_refund'])) {
            $targetType = (string) ($_POST['target_type'] ?? '');
            $targetId = (int) ($_POST['target_id'] ?? 0);
            $reason = trim((string) ($_POST['reason'] ?? ''));

            if (!in_array($targetType, ['CHARGE', 'PAYMENT'], true) || $targetId <= 0) {
                throw new RuntimeException('Invalid item selected for reversal.');
            }
            if ($reason === '') {
                throw new RuntimeException('Please provide a reason for the refund or reversal.');
            }

            if ($targetType === 'CHARGE') {
                $itemStmt = $pdo->prepare('SELECT charge_id, amount, is_reversed FROM charges WHERE charge_id = ? AND stay_id = ?');
                $itemStmt->execute([$targetId, $stayId]);
                $item = $itemStmt->fetch();
                if (!$item) {
                    throw new RuntimeException('Charge not found for this stay.');
                }
                if ((int) $item['is_reversed'] === 1) {
                    throw new RuntimeException('This charge has already been reversed.');
                }
                $refundAmount = round((float) $item['amount'], 2);
                $pointsToDeduct = 0;
            } else {
                $itemStmt = $pdo->prepare('SELECT payment_id, amount, is_reversed FROM payments WHERE payment_id = ? AND stay_id = ?');
                $itemStmt->execute([$targetId, $stayId]);
                $item = $itemStmt->fetch();
                if (!$item) {
                    throw new RuntimeException('Payment not found for this stay.');
                }
                if ((int) $item['is_reversed'] === 1) {
                    throw new RuntimeException('This payment has already been reversed.');
                }
                $refundAmount = round((float) $item['amount'], 2);
                $pointsToDeduct = (int) floor($refundAmount / 100);
            }

            $dupStmt = $pdo->prepare(
                "SELECT refund_id FROM refund_requests
                 WHERE stay_id = ? AND target_type = ? AND target_id = ? AND status = 'Pending'
                 LIMIT 1"
            );
            $dupStmt->execute([$stayId, $targetType, $targetId]);
            if ($dupStmt->fetch()) {
                throw new RuntimeException('A pending refund/reversal request already exists for this item.');
            }

            $insReq = $pdo->prepare(
                "INSERT INTO refund_requests
                    (stay_id, target_type, target_id, amount, points_to_deduct, reason, status, requested_by)
                 VALUES
                    (:stay_id, :target_type, :target_id, :amount, :points_to_deduct, :reason, 'Pending', :requested_by)"
            );
            $insReq->execute([
                'stay_id'          => $stayId,
                'target_type'      => $targetType,
                'target_id'        => $targetId,
                'amount'           => $refundAmount,
                'points_to_deduct' => $pointsToDeduct,
                'reason'           => $reason,
                'requested_by'     => $currentUserId > 0 ? $currentUserId : null,
            ]);

            $success = 'Refund/reversal request submitted for manager approval.';
        }

        // FR-025 & FR-037: Approve or Reject refund/reversal request (Manager / Administrator only)
        if (isset($_POST['review_refund'])) {
            if (!$canApproveRefunds) {
                throw new RuntimeException('Only a Hotel Manager or Administrator can approve or reject refunds/reversals.');
            }

            $refundId = (int) ($_POST['refund_id'] ?? 0);
            $decision = (string) ($_POST['decision'] ?? '');

            if ($refundId <= 0 || !in_array($decision, ['APPROVE', 'REJECT'], true)) {
                throw new RuntimeException('Invalid refund review action.');
            }

            $pdo->beginTransaction();

            $reqStmt = $pdo->prepare('SELECT * FROM refund_requests WHERE refund_id = ? AND stay_id = ? FOR UPDATE');
            $reqStmt->execute([$refundId, $stayId]);
            $req = $reqStmt->fetch();

            if (!$req) {
                throw new RuntimeException('Refund request not found.');
            }
            if ($req['status'] !== 'Pending') {
                throw new RuntimeException('This refund request has already been reviewed.');
            }

            if ($decision === 'REJECT') {
                $rejStmt = $pdo->prepare(
                    "UPDATE refund_requests
                     SET status = 'Rejected', approved_by = :uid, reviewed_at = NOW()
                     WHERE refund_id = :rid"
                );
                $rejStmt->execute([
                    'uid' => $currentUserId > 0 ? $currentUserId : null,
                    'rid' => $refundId,
                ]);
                $pdo->commit();
                $success = "Refund/reversal request #{$refundId} rejected.";
            } else {
                $targetType = (string) $req['target_type'];
                $targetId = (int) $req['target_id'];
                $refundAmount = round((float) $req['amount'], 2);
                $pointsToDeduct = (int) $req['points_to_deduct'];

                if ($targetType === 'CHARGE') {
                    $updCharge = $pdo->prepare('UPDATE charges SET is_reversed = 1 WHERE charge_id = ? AND stay_id = ? AND is_reversed = 0');
                    $updCharge->execute([$targetId, $stayId]);
                    if ($updCharge->rowCount() === 0) {
                        throw new RuntimeException('Charge is missing or already reversed.');
                    }
                } else {
                    $updPayment = $pdo->prepare('UPDATE payments SET is_reversed = 1 WHERE payment_id = ? AND stay_id = ? AND is_reversed = 0');
                    $updPayment->execute([$targetId, $stayId]);
                    if ($updPayment->rowCount() === 0) {
                        throw new RuntimeException('Payment is missing or already reversed.');
                    }

                    // FR-037: Deduct points earned on reversed payment and log REVERSAL
                    if ($pointsToDeduct > 0) {
                        $guestId = (int) $stayRow['guest_id'];

                        $insRevLedger = $pdo->prepare(
                            "INSERT INTO loyalty_transactions (guest_id, stay_id, txn_type, points, notes)
                             VALUES (:guest_id, :stay_id, 'REVERSAL', :points, :notes)"
                        );
                        $insRevLedger->execute([
                            'guest_id' => $guestId,
                            'stay_id'  => $stayId,
                            'points'   => -$pointsToDeduct,
                            'notes'    => "Reversed {$pointsToDeduct} pts for refunded payment #{$targetId} (KES " . number_format($refundAmount, 2) . ")",
                        ]);

                        $deductPts = $pdo->prepare(
                            'UPDATE guests SET loyalty_points = GREATEST(0, loyalty_points - :pts) WHERE guest_id = :gid'
                        );
                        $deductPts->execute([
                            'pts' => $pointsToDeduct,
                            'gid' => $guestId,
                        ]);
                    }
                }

                $appStmt = $pdo->prepare(
                    "UPDATE refund_requests
                     SET status = 'Approved', approved_by = :uid, reviewed_at = NOW()
                     WHERE refund_id = :rid"
                );
                $appStmt->execute([
                    'uid' => $currentUserId > 0 ? $currentUserId : null,
                    'rid' => $refundId,
                ]);

                $pdo->commit();
                $success = "Refund/reversal #{$refundId} approved"
                    . ($pointsToDeduct > 0 ? " ({$pointsToDeduct} loyalty points reversed)." : '.');
            }
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $errors[] = $e->getMessage();
    }
}

$currentStay = null;
$charges = [];
$payments = [];
$refundRequests = [];
$transactions = [];
$totalCharges = 0.0;
$totalPaid = 0.0;
$balance = 0.0;

if ($stayId > 0) {
    $sStmt = $pdo->prepare(
        'SELECT s.*, g.full_name, g.phone, g.loyalty_points, r.room_number
         FROM stays s
         JOIN guests g ON g.guest_id = s.guest_id
         JOIN rooms r ON r.room_id = s.room_id
         WHERE s.stay_id = ?'
    );
    $sStmt->execute([$stayId]);
    $currentStay = $sStmt->fetch();

    if ($currentStay) {
        $cStmt = $pdo->prepare('SELECT * FROM charges WHERE stay_id = ? ORDER BY charge_id ASC');
        $cStmt->execute([$stayId]);
        $charges = $cStmt->fetchAll();

        $pStmt = $pdo->prepare('SELECT * FROM payments WHERE stay_id = ? ORDER BY payment_id ASC');
        $pStmt->execute([$stayId]);
        $payments = $pStmt->fetchAll();

        foreach ($charges as $c) {
            if ((int) ($c['is_reversed'] ?? 0) === 0) {
                $totalCharges += (float) $c['amount'];
            }
        }
        foreach ($payments as $p) {
            if ((int) ($p['is_reversed'] ?? 0) === 0) {
                $totalPaid += (float) $p['amount'];
            }
        }
        $balance = round($totalCharges - $totalPaid, 2);

        $rrStmt = $pdo->prepare(
            'SELECT rr.*, u_req.full_name AS requested_by_name, u_app.full_name AS approved_by_name
             FROM refund_requests rr
             LEFT JOIN users u_req ON u_req.user_id = rr.requested_by
             LEFT JOIN users u_app ON u_app.user_id = rr.approved_by
             WHERE rr.stay_id = ?
             ORDER BY rr.refund_id DESC'
        );
        $rrStmt->execute([$stayId]);
        $refundRequests = $rrStmt->fetchAll();

        $txStmt = $pdo->prepare(
            "SELECT * FROM (
                SELECT
                    c.charge_id AS entry_id,
                    c.posted_at AS txn_at,
                    IF(c.is_reversed = 1, 'Charge (Reversed)', 'Charge') AS txn_type,
                    c.description AS details,
                    IF(c.is_reversed = 1, 0.00, c.amount) AS charge_kes,
                    0.00 AS payment_kes
                FROM charges c
                WHERE c.stay_id = ?

                UNION ALL

                SELECT
                    p.payment_id AS entry_id,
                    p.paid_at AS txn_at,
                    IF(p.is_reversed = 1, 'Payment (Refunded)', 'Payment') AS txn_type,
                    CONCAT(p.method, IF(p.reference_no IS NOT NULL AND p.reference_no != '', CONCAT(' (Ref: ', p.reference_no, ')'), '')) AS details,
                    0.00 AS charge_kes,
                    IF(p.is_reversed = 1, 0.00, p.amount) AS payment_kes
                FROM payments p
                WHERE p.stay_id = ?
            ) AS folio_history
            ORDER BY txn_at ASC, entry_id ASC"
        );
        $txStmt->execute([$stayId, $stayId]);
        $transactions = $txStmt->fetchAll();
    }
}
?>

<style>
@media print {
    .no-print,
    .navbar,
    nav,
    footer,
    form,
    .btn {
        display: none !important;
    }
    body {
        background: #fff !important;
        color: #000 !important;
    }
    .card {
        border: 1px solid #ccc !important;
        box-shadow: none !important;
    }
}
</style>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h3 class="mb-0">Guest Folio &amp; Billing (KES)</h3>
    <?php if ($currentStay): ?>
        <button type="button" class="btn btn-outline-dark no-print" onclick="window.print()">Print / Save PDF</button>
    <?php endif; ?>
</div>

<?php if ($success !== ''): ?>
    <div class="alert alert-success no-print"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<?php foreach ($errors as $err): ?>
    <div class="alert alert-danger no-print"><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></div>
<?php endforeach; ?>

<div class="card shadow-sm mb-4 no-print">
    <div class="card-body">
        <form method="GET" action="" class="row g-3 align-items-end">
            <div class="col-md-8">
                <label class="form-label" for="stay_id">Select Stay</label>
                <select id="stay_id" name="stay_id" class="form-select">
                    <?php if (empty($stays)): ?>
                        <option value="0">No stays created yet (check in a guest first)</option>
                    <?php else: ?>
                        <?php foreach ($stays as $st): ?>
                            <option value="<?= (int) $st['stay_id'] ?>" <?= $stayId === (int) $st['stay_id'] ? 'selected' : '' ?>>
                                Stay #<?= (int) $st['stay_id'] ?> — <?= htmlspecialchars($st['full_name'], ENT_QUOTES, 'UTF-8') ?> (Room <?= htmlspecialchars($st['room_number'], ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars($st['status'], ENT_QUOTES, 'UTF-8') ?>)
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </div>
            <div class="col-md-4">
                <button type="submit" class="btn btn-secondary w-100">Load Folio</button>
            </div>
        </form>
    </div>
</div>

<?php if ($currentStay): ?>
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card shadow-sm"><div class="card-body">
                <div class="text-muted small">Guest &amp; Room</div>
                <div class="fw-bold"><?= htmlspecialchars($currentStay['full_name'], ENT_QUOTES, 'UTF-8') ?></div>
                <div class="small">Room <?= htmlspecialchars($currentStay['room_number'], ENT_QUOTES, 'UTF-8') ?> (<?= htmlspecialchars($currentStay['status'], ENT_QUOTES, 'UTF-8') ?>)</div>
            </div></div>
        </div>
        <div class="col-md-3">
            <div class="card shadow-sm"><div class="card-body">
                <div class="text-muted small">Total Charges</div>
                <div class="fs-5 fw-bold">KES <?= number_format($totalCharges, 2) ?></div>
            </div></div>
        </div>
        <div class="col-md-3">
            <div class="card shadow-sm"><div class="card-body">
                <div class="text-muted small">Total Payments</div>
                <div class="fs-5 fw-bold text-success">KES <?= number_format($totalPaid, 2) ?></div>
            </div></div>
        </div>
        <div class="col-md-3">
            <div class="card shadow-sm"><div class="card-body">
                <div class="text-muted small">Balance Due</div>
                <div class="fs-5 fw-bold <?= $balance > 0 ? 'text-danger' : 'text-success' ?>">KES <?= number_format($balance, 2) ?></div>
                <div class="small text-muted">Loyalty Pts: <?= (int) $currentStay['loyalty_points'] ?></div>
            </div></div>
        </div>
    </div>

    <div class="row g-4 mb-4 no-print">
        <div class="col-md-6">
            <div class="card shadow-sm">
                <div class="card-header bg-white"><strong>Add Folio Charge</strong></div>
                <div class="card-body">
                    <form method="POST" action="">
                        <input type="hidden" name="stay_id" value="<?= (int) $stayId ?>">
                        <div class="mb-3">
                            <label class="form-label" for="description">Description</label>
                            <input type="text" id="description" name="description" class="form-control" placeholder="e.g. Restaurant Dinner / Laundry" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="amount">Amount (KES)</label>
                            <input type="number" step="0.01" min="1" id="amount" name="amount" class="form-control" required>
                        </div>
                        <button type="submit" name="add_charge" class="btn btn-primary">Post Charge</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card shadow-sm">
                <div class="card-header bg-white"><strong>Record Payment</strong></div>
                <div class="card-body">
                    <form method="POST" action="">
                        <input type="hidden" name="stay_id" value="<?= (int) $stayId ?>">
                        <div class="mb-3">
                            <label class="form-label" for="method">Payment Method</label>
                            <select id="method" name="method" class="form-select" required>
                                <option value="Cash">Cash</option>
                                <option value="Mobile Money">Mobile Money</option>
                                <option value="Card">Card</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="pay_amount">Amount Paid (KES)</label>
                            <input type="number" step="0.01" min="1" id="pay_amount" name="pay_amount" class="form-control" value="<?= $balance > 0 ? $balance : '' ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="reference_no">Reference No. (Optional)</label>
                            <input type="text" id="reference_no" name="reference_no" class="form-control" placeholder="e.g. M-Pesa / Receipt code">
                        </div>
                        <button type="submit" name="add_payment" class="btn btn-success">Record Payment</button>
                        <?php if ($currentStay['status'] === 'Active' && $balance <= 0): ?>
                            <a href="/hms/frontdesk/checkout.php?stay_id=<?= (int) $stayId ?>" class="btn btn-warning ms-2">Proceed to Check-Out</a>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm mb-4 no-print">
        <div class="card-header bg-white"><strong>Refunds &amp; Reversals (FR-025 / FR-037)</strong></div>
        <div class="card-body">
            <form method="POST" action="" class="row g-3 align-items-end mb-4">
                <input type="hidden" name="stay_id" value="<?= (int) $stayId ?>">
                <div class="col-md-3">
                    <label class="form-label" for="target_type">Item Type</label>
                    <select id="target_type" name="target_type" class="form-select" required>
                        <option value="CHARGE">Charge Reversal</option>
                        <option value="PAYMENT">Payment Refund (Reverses Points)</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="target_id">Select Charge or Payment</label>
                    <select id="target_id" name="target_id" class="form-select" required>
                        <optgroup label="Active Charges">
                            <?php foreach ($charges as $c): ?>
                                <?php if ((int) ($c['is_reversed'] ?? 0) === 0): ?>
                                    <option value="<?= (int) $c['charge_id'] ?>">
                                        Charge #<?= (int) $c['charge_id'] ?> — <?= htmlspecialchars((string) $c['description'], ENT_QUOTES, 'UTF-8') ?> (KES <?= number_format((float) $c['amount'], 2) ?>)
                                    </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </optgroup>
                        <optgroup label="Active Payments">
                            <?php foreach ($payments as $p): ?>
                                <?php if ((int) ($p['is_reversed'] ?? 0) === 0): ?>
                                    <?php $estPts = (int) floor((float) $p['amount'] / 100); ?>
                                    <option value="<?= (int) $p['payment_id'] ?>">
                                        Payment #<?= (int) $p['payment_id'] ?> — <?= htmlspecialchars((string) $p['method'], ENT_QUOTES, 'UTF-8') ?> (KES <?= number_format((float) $p['amount'], 2) ?><?= $estPts > 0 ? ", -{$estPts} pts" : '' ?>)
                                    </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </optgroup>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="reason">Reason</label>
                    <input type="text" id="reason" name="reason" class="form-control" placeholder="e.g. Overcharge / Guest cancelled" required>
                </div>
                <div class="col-md-2">
                    <button type="submit" name="request_refund" class="btn btn-outline-danger w-100">Request</button>
                </div>
            </form>

            <div class="table-responsive">
                <table class="table table-sm table-bordered mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>Target</th>
                            <th class="text-end">Amount (KES)</th>
                            <th class="text-end">Pts Reversal</th>
                            <th>Reason</th>
                            <th>Requested By</th>
                            <th>Status</th>
                            <?php if ($canApproveRefunds): ?>
                                <th>Manager Action</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($refundRequests)): ?>
                            <tr>
                                <td colspan="<?= $canApproveRefunds ? 8 : 7 ?>" class="text-center text-muted py-3">
                                    No refund or reversal requests for this stay.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($refundRequests as $rr): ?>
                                <?php
                                $badgeClass = match ($rr['status']) {
                                    'Approved' => 'bg-success',
                                    'Rejected' => 'bg-danger',
                                    default   => 'bg-warning text-dark',
                                };
                                ?>
                                <tr>
                                    <td><?= (int) $rr['refund_id'] ?></td>
                                    <td><?= htmlspecialchars((string) $rr['target_type'], ENT_QUOTES, 'UTF-8') ?> #<?= (int) $rr['target_id'] ?></td>
                                    <td class="text-end"><?= number_format((float) $rr['amount'], 2) ?></td>
                                    <td class="text-end"><?= (int) $rr['points_to_deduct'] ?></td>
                                    <td><?= htmlspecialchars((string) $rr['reason'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td class="small"><?= htmlspecialchars((string) ($rr['requested_by_name'] ?? 'Staff'), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td>
                                        <span class="badge <?= $badgeClass ?>"><?= htmlspecialchars((string) $rr['status'], ENT_QUOTES, 'UTF-8') ?></span>
                                        <?php if (!empty($rr['approved_by_name'])): ?>
                                            <div class="small text-muted"><?= htmlspecialchars((string) $rr['approved_by_name'], ENT_QUOTES, 'UTF-8') ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <?php if ($canApproveRefunds): ?>
                                        <td>
                                            <?php if ($rr['status'] === 'Pending'): ?>
                                                <form method="POST" action="" class="d-inline-flex gap-1">
                                                    <input type="hidden" name="stay_id" value="<?= (int) $stayId ?>">
                                                    <input type="hidden" name="refund_id" value="<?= (int) $rr['refund_id'] ?>">
                                                    <button type="submit" name="review_refund" value="1" onclick="this.form.decision.value='APPROVE'" class="btn btn-sm btn-success">Approve</button>
                                                    <button type="submit" name="review_refund" value="1" onclick="this.form.decision.value='REJECT'" class="btn btn-sm btn-outline-danger">Reject</button>
                                                    <input type="hidden" name="decision" value="">
                                                </form>
                                            <?php else: ?>
                                                <span class="small text-muted">Reviewed</span>
                                            <?php endif; ?>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-4 no-print">
        <div class="col-md-6">
            <div class="card shadow-sm">
                <div class="card-header bg-white"><strong>Charges Ledger</strong></div>
                <div class="card-body p-0">
                    <table class="table table-sm table-striped mb-0">
                        <thead class="table-light"><tr><th>Description</th><th>Amount</th><th>Posted</th></tr></thead>
                        <tbody>
                            <?php if (empty($charges)): ?>
                                <tr><td colspan="3" class="text-center text-muted py-3">No charges posted.</td></tr>
                            <?php else: ?>
                                <?php foreach ($charges as $c): ?>
                                    <?php $rev = (int) ($c['is_reversed'] ?? 0) === 1; ?>
                                    <tr class="<?= $rev ? 'table-secondary text-muted' : '' ?>">
                                        <td>
                                            <?= htmlspecialchars($c['description'], ENT_QUOTES, 'UTF-8') ?>
                                            <?php if ($rev): ?><span class="badge bg-secondary ms-1">Reversed</span><?php endif; ?>
                                        </td>
                                        <td>KES <?= number_format((float) $c['amount'], 2) ?></td>
                                        <td class="small text-muted"><?= htmlspecialchars($c['posted_at'], ENT_QUOTES, 'UTF-8') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card shadow-sm">
                <div class="card-header bg-white"><strong>Payments Ledger</strong></div>
                <div class="card-body p-0">
                    <table class="table table-sm table-striped mb-0">
                        <thead class="table-light"><tr><th>Method</th><th>Ref</th><th>Amount</th><th>Paid</th></tr></thead>
                        <tbody>
                            <?php if (empty($payments)): ?>
                                <tr><td colspan="4" class="text-center text-muted py-3">No payments recorded.</td></tr>
                            <?php else: ?>
                                <?php foreach ($payments as $p): ?>
                                    <?php $rev = (int) ($p['is_reversed'] ?? 0) === 1; ?>
                                    <tr class="<?= $rev ? 'table-secondary text-muted' : '' ?>">
                                        <td>
                                            <?= htmlspecialchars($p['method'], ENT_QUOTES, 'UTF-8') ?>
                                            <?php if ($rev): ?><span class="badge bg-secondary ms-1">Refunded</span><?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars((string) ($p['reference_no'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                                        <td>KES <?= number_format((float) $p['amount'], 2) ?></td>
                                        <td class="small text-muted"><?= htmlspecialchars($p['paid_at'], ENT_QUOTES, 'UTF-8') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm" id="transaction-history">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <div>
                <strong>Transaction History Statement</strong>
                <div class="small text-muted">
                    Stay #<?= (int) $currentStay['stay_id'] ?> &bull;
                    Guest: <?= htmlspecialchars((string) $currentStay['full_name'], ENT_QUOTES, 'UTF-8') ?>
                    (<?= htmlspecialchars((string) $currentStay['phone'], ENT_QUOTES, 'UTF-8') ?>) &bull;
                    Room <?= htmlspecialchars((string) $currentStay['room_number'], ENT_QUOTES, 'UTF-8') ?>
                </div>
            </div>
            <span class="small text-muted">Generated: <?= date('Y-m-d H:i') ?></span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-bordered mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Date / Time</th>
                            <th>Type</th>
                            <th>Description / Details</th>
                            <th class="text-end">Charges (KES)</th>
                            <th class="text-end">Payments (KES)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($transactions)): ?>
                            <tr><td colspan="5" class="text-center text-muted py-3">No transactions recorded for this stay.</td></tr>
                        <?php else: ?>
                            <?php foreach ($transactions as $tx): ?>
                                <tr>
                                    <td class="small"><?= htmlspecialchars((string) $tx['txn_at'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars((string) $tx['txn_type'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars((string) $tx['details'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td class="text-end">
                                        <?= (float) $tx['charge_kes'] > 0 ? number_format((float) $tx['charge_kes'], 2) : '—' ?>
                                    </td>
                                    <td class="text-end">
                                        <?= (float) $tx['payment_kes'] > 0 ? number_format((float) $tx['payment_kes'], 2) : '—' ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <tfoot class="table-light">
                        <tr>
                            <th colspan="3" class="text-end">Totals</th>
                            <th class="text-end">KES <?= number_format($totalCharges, 2) ?></th>
                            <th class="text-end">KES <?= number_format($totalPaid, 2) ?></th>
                        </tr>
                        <tr>
                            <th colspan="4" class="text-end">Balance Due</th>
                            <th class="text-end <?= $balance > 0 ? 'text-danger' : 'text-success' ?>">KES <?= number_format($balance, 2) ?></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>