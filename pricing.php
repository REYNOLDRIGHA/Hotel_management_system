<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';

$pricingHelper = __DIR__ . '/../includes/pricing.php';
if (is_file($pricingHelper)) {
    require_once $pricingHelper;
}

if (!function_exists('calculateEffectiveNightlyRate')) {
    /**
     * Calculates the effective nightly rate for a room type on a specific date.
     * Active matching rules multiply sequentially on top of base_rate.
     */
    function calculateEffectiveNightlyRate(
        PDO $pdo,
        int $roomTypeId,
        float $baseRate,
        string $stayDate,
        ?string $promoCode = null
    ): array {
        $stmt = $pdo->prepare(
            'SELECT * FROM rate_rules
             WHERE is_active = 1
               AND (room_type_id IS NULL OR room_type_id = :room_type_id)
             ORDER BY rule_id ASC'
        );
        $stmt->execute(['room_type_id' => $roomTypeId]);
        $rules = $stmt->fetchAll();

        $dayOfWeek = (int) date('N', strtotime($stayDate)); // 5 = Fri, 6 = Sat, 7 = Sun
        $isWeekend = in_array($dayOfWeek, [5, 6, 7], true);
        $normalizedPromo = strtoupper(trim((string) $promoCode));

        $effectiveRate = $baseRate;
        $appliedRules = [];

        foreach ($rules as $rule) {
            $type = (string) $rule['rule_type'];
            $mult = (float) $rule['multiplier'];
            $matches = false;

            if ($type === 'SEASONAL') {
                $start = (string) ($rule['start_date'] ?? '');
                $end = (string) ($rule['end_date'] ?? '');
                if ($start !== '' && $end !== '' && $stayDate >= $start && $stayDate <= $end) {
                    $matches = true;
                }
            } elseif ($type === 'WEEKEND') {
                if ($isWeekend) {
                    $matches = true;
                }
            } elseif ($type === 'PROMOTIONAL') {
                $ruleCode = strtoupper(trim((string) ($rule['promo_code'] ?? '')));
                if ($ruleCode !== '' && $normalizedPromo !== '' && $ruleCode === $normalizedPromo) {
                    $matches = true;
                }
            }

            if ($matches) {
                $effectiveRate *= $mult;
                $appliedRules[] = [
                    'rule_name'  => (string) $rule['rule_name'],
                    'rule_type'  => $type,
                    'multiplier' => $mult,
                ];
            }
        }

        return [
            'base_rate'      => round($baseRate, 2),
            'effective_rate' => round($effectiveRate, 2),
            'applied_rules'  => $appliedRules,
        ];
    }
}

requireRole(['Hotel Manager', 'Administrator']);

require_once __DIR__ . '/../includes/header.php';

$currentUserId = (int) ($_SESSION['user_id'] ?? 0);
$errors = [];
$success = '';

$roomTypes = $pdo->query('SELECT room_type_id, name, base_rate FROM room_types ORDER BY room_type_id ASC')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['save_rule'])) {
            $ruleName = trim((string) ($_POST['rule_name'] ?? ''));
            $ruleType = (string) ($_POST['rule_type'] ?? '');
            $roomTypeIdRaw = (string) ($_POST['room_type_id'] ?? '');
            $roomTypeId = $roomTypeIdRaw !== '' ? (int) $roomTypeIdRaw : null;
            $startDate = trim((string) ($_POST['start_date'] ?? ''));
            $endDate = trim((string) ($_POST['end_date'] ?? ''));
            $promoCode = strtoupper(trim((string) ($_POST['promo_code'] ?? '')));
            $multiplier = (float) ($_POST['multiplier'] ?? 0);
            $isActive = isset($_POST['is_active']) ? 1 : 0;

            if ($ruleName === '') {
                throw new RuntimeException('Rule name is required.');
            }
            if (!in_array($ruleType, ['SEASONAL', 'WEEKEND', 'PROMOTIONAL'], true)) {
                throw new RuntimeException('Invalid rate rule type.');
            }
            if ($multiplier <= 0) {
                throw new RuntimeException('Multiplier must be greater than 0 (e.g. 1.25 for +25%, 0.85 for -15%).');
            }

            if ($ruleType === 'SEASONAL') {
                if ($startDate === '' || $endDate === '' || $endDate < $startDate) {
                    throw new RuntimeException('Seasonal rules require a valid start date and end date.');
                }
                $promoCode = '';
            } elseif ($ruleType === 'PROMOTIONAL') {
                if ($promoCode === '') {
                    throw new RuntimeException('Promotional rules require a promo code.');
                }
                $startDate = '';
                $endDate = '';
            } else {
                $startDate = '';
                $endDate = '';
                $promoCode = '';
            }

            $ins = $pdo->prepare(
                'INSERT INTO rate_rules
                    (rule_name, rule_type, room_type_id, start_date, end_date, promo_code, multiplier, is_active, created_by)
                 VALUES
                    (:rule_name, :rule_type, :room_type_id, :start_date, :end_date, :promo_code, :multiplier, :is_active, :created_by)'
            );
            $ins->execute([
                'rule_name'    => $ruleName,
                'rule_type'    => $ruleType,
                'room_type_id' => $roomTypeId,
                'start_date'   => $startDate !== '' ? $startDate : null,
                'end_date'     => $endDate !== '' ? $endDate : null,
                'promo_code'   => $promoCode !== '' ? $promoCode : null,
                'multiplier'   => round($multiplier, 4),
                'is_active'    => $isActive,
                'created_by'   => $currentUserId > 0 ? $currentUserId : null,
            ]);

            $success = "Rate multiplier rule '{$ruleName}' saved.";
        }

        if (isset($_POST['toggle_rule'])) {
            $ruleId = (int) ($_POST['rule_id'] ?? 0);
            $nextActive = (int) ($_POST['next_active'] ?? 0) === 1 ? 1 : 0;

            if ($ruleId <= 0) {
                throw new RuntimeException('Invalid rate rule.');
            }

            $upd = $pdo->prepare('UPDATE rate_rules SET is_active = :active WHERE rule_id = :id');
            $upd->execute(['active' => $nextActive, 'id' => $ruleId]);
            $success = $nextActive === 1 ? 'Rate rule activated.' : 'Rate rule deactivated.';
        }

        if (isset($_POST['delete_rule'])) {
            $ruleId = (int) ($_POST['rule_id'] ?? 0);
            if ($ruleId <= 0) {
                throw new RuntimeException('Invalid rate rule.');
            }

            $del = $pdo->prepare('DELETE FROM rate_rules WHERE rule_id = :id');
            $del->execute(['id' => $ruleId]);
            $success = 'Rate rule deleted.';
        }
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

$previewDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['preview_date'] ?? ''))
    ? (string) $_GET['preview_date']
    : date('Y-m-d');
$previewPromo = strtoupper(trim((string) ($_GET['preview_promo'] ?? '')));

$rules = $pdo->query(
    'SELECT rr.*, rt.name AS room_type_name
     FROM rate_rules rr
     LEFT JOIN room_types rt ON rt.room_type_id = rr.room_type_id
     ORDER BY rr.is_active DESC, rr.rule_id DESC'
)->fetchAll();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h3 class="mb-0">Seasonal, Weekend &amp; Promotional Pricing</h3>
        <small class="text-muted">Configure rate multipliers on top of room base rates (FR-008)</small>
    </div>
    <a href="/hms/rooms/search.php" class="btn btn-outline-secondary btn-sm">Room Search</a>
</div>

<?php if ($success !== ''): ?>
    <div class="alert alert-success"><?= h($success) ?></div>
<?php endif; ?>

<?php foreach ($errors as $err): ?>
    <div class="alert alert-danger"><?= h($err) ?></div>
<?php endforeach; ?>

<div class="row g-4 mb-4">
    <div class="col-lg-5">
        <div class="card shadow-sm">
            <div class="card-header"><strong>Add Rate Multiplier Rule</strong></div>
            <div class="card-body">
                <form method="POST" action="">
                    <div class="mb-3">
                        <label class="form-label" for="rule_name">Rule Name</label>
                        <input type="text" id="rule_name" name="rule_name" class="form-control"
                               placeholder="e.g. Festive Peak Season / Flash Promo" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="rule_type">Pricing Type</label>
                        <select id="rule_type" name="rule_type" class="form-select" required>
                            <option value="SEASONAL">Seasonal (Date Range)</option>
                            <option value="WEEKEND">Weekend (Fri–Sun)</option>
                            <option value="PROMOTIONAL">Promotional (Promo Code)</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="room_type_id">Room Type Scope</label>
                        <select id="room_type_id" name="room_type_id" class="form-select">
                            <option value="">All Room Types</option>
                            <?php foreach ($roomTypes as $rt): ?>
                                <option value="<?= (int) $rt['room_type_id'] ?>">
                                    <?= h((string) $rt['name']) ?> (Base: KES <?= number_format((float) $rt['base_rate'], 2) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label" for="start_date">Start Date (Seasonal)</label>
                            <input type="date" id="start_date" name="start_date" class="form-control">
                        </div>
                        <div class="col-6">
                            <label class="form-label" for="end_date">End Date (Seasonal)</label>
                            <input type="date" id="end_date" name="end_date" class="form-control">
                        </div>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label" for="promo_code">Promo Code</label>
                            <input type="text" id="promo_code" name="promo_code" class="form-control"
                                   placeholder="e.g. FESTIVE26">
                        </div>
                        <div class="col-6">
                            <label class="form-label" for="multiplier">Multiplier</label>
                            <input type="number" step="0.01" min="0.05" max="10" id="multiplier" name="multiplier"
                                   class="form-control" value="1.20" required>
                        </div>
                    </div>

                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1" checked>
                        <label class="form-check-label" for="is_active">Rule Active</label>
                    </div>

                    <button type="submit" name="save_rule" class="btn btn-primary w-100">Save Multiplier Rule</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card shadow-sm mb-4">
            <div class="card-header"><strong>Live Nightly Rate Preview (KES)</strong></div>
            <div class="card-body">
                <form method="GET" action="" class="row g-2 align-items-end mb-3">
                    <div class="col-md-5">
                        <label class="form-label" for="preview_date">Preview Date</label>
                        <input type="date" id="preview_date" name="preview_date" class="form-control"
                               value="<?= h($previewDate) ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="preview_promo">Promo Code</label>
                        <input type="text" id="preview_promo" name="preview_promo" class="form-control"
                               value="<?= h($previewPromo) ?>" placeholder="Optional code">
                    </div>
                    <div class="col-md-3">
                        <button type="submit" class="btn btn-secondary w-100">Calculate</button>
                    </div>
                </form>

                <div class="table-responsive">
                    <table class="table table-sm table-bordered mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Room Type</th>
                                <th class="text-end">Base Rate</th>
                                <th class="text-end">Effective Rate</th>
                                <th>Applied Rules</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($roomTypes as $rt): ?>
                                <?php
                                $calc = calculateEffectiveNightlyRate(
                                    $pdo,
                                    (int) $rt['room_type_id'],
                                    (float) $rt['base_rate'],
                                    $previewDate,
                                    $previewPromo
                                );
                                $ruleNames = array_map(
                                    static fn(array $ar): string => $ar['rule_name'] . ' (x' . rtrim(rtrim(number_format($ar['multiplier'], 4), '0'), '.') . ')',
                                    $calc['applied_rules']
                                );
                                ?>
                                <tr>
                                    <td class="fw-semibold"><?= h((string) $rt['name']) ?></td>
                                    <td class="text-end">KES <?= number_format($calc['base_rate'], 2) ?></td>
                                    <td class="text-end fw-bold text-primary">KES <?= number_format($calc['effective_rate'], 2) ?></td>
                                    <td class="small">
                                        <?= empty($ruleNames) ? '<span class="text-muted">Base rate only</span>' : h(implode(', ', $ruleNames)) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-header"><strong>Configured Rate Rules (<?= count($rules) ?>)</strong></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-striped mb-0">
                        <thead>
                            <tr>
                                <th>Rule</th>
                                <th>Type</th>
                                <th>Scope / Window</th>
                                <th class="text-end">Mult.</th>
                                <th>Status</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($rules)): ?>
                                <tr><td colspan="6" class="text-center text-muted py-3">No pricing rules configured yet.</td></tr>
                            <?php else: ?>
                                <?php foreach ($rules as $r): ?>
                                    <?php $active = (int) $r['is_active'] === 1; ?>
                                    <tr>
                                        <td class="fw-semibold"><?= h((string) $r['rule_name']) ?></td>
                                        <td><span class="badge bg-dark"><?= h((string) $r['rule_type']) ?></span></td>
                                        <td class="small">
                                            <div>Room Type: <?= h((string) ($r['room_type_name'] ?? 'All')) ?></div>
                                            <?php if ($r['rule_type'] === 'SEASONAL'): ?>
                                                <div class="text-muted"><?= h((string) $r['start_date']) ?> &rarr; <?= h((string) $r['end_date']) ?></div>
                                            <?php elseif ($r['rule_type'] === 'PROMOTIONAL'): ?>
                                                <div class="text-muted">Code: <strong><?= h((string) $r['promo_code']) ?></strong></div>
                                            <?php else: ?>
                                                <div class="text-muted">Fri–Sun nights</div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end fw-bold">&times;<?= number_format((float) $r['multiplier'], 2) ?></td>
                                        <td>
                                            <span class="badge <?= $active ? 'bg-success' : 'bg-secondary' ?>">
                                                <?= $active ? 'Active' : 'Inactive' ?>
                                            </span>
                                        </td>
                                        <td class="text-end text-nowrap">
                                            <form method="POST" action="" class="d-inline">
                                                <input type="hidden" name="rule_id" value="<?= (int) $r['rule_id'] ?>">
                                                <input type="hidden" name="next_active" value="<?= $active ? 0 : 1 ?>">
                                                <button type="submit" name="toggle_rule" class="btn btn-sm btn-outline-secondary">
                                                    <?= $active ? 'Disable' : 'Enable' ?>
                                                </button>
                                            </form>
                                            <form method="POST" action="" class="d-inline" onsubmit="return confirm('Delete this rate rule?');">
                                                <input type="hidden" name="rule_id" value="<?= (int) $r['rule_id'] ?>">
                                                <button type="submit" name="delete_rule" class="btn btn-sm btn-outline-danger">Delete</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>