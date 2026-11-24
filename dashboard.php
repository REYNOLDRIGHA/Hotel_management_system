<?php
declare(strict_types=1);

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/header.php';

$totalRooms = (int) $pdo->query('SELECT COUNT(*) FROM rooms')->fetchColumn();
$availableRooms = (int) $pdo->query("SELECT COUNT(*) FROM rooms WHERE status = 'Available'")->fetchColumn();
$occupiedRooms = (int) $pdo->query("SELECT COUNT(*) FROM rooms WHERE status = 'Occupied'")->fetchColumn();
$dirtyRooms = (int) $pdo->query("SELECT COUNT(*) FROM rooms WHERE status = 'Dirty'")->fetchColumn();
$activeStays = (int) $pdo->query("SELECT COUNT(*) FROM stays WHERE status = 'Active'")->fetchColumn();
$pendingTasks = (int) $pdo->query("SELECT COUNT(*) FROM housekeeping_tasks WHERE status != 'Completed'")->fetchColumn();

$occupancyRate = $totalRooms > 0 ? round(($occupiedRooms / $totalRooms) * 100, 1) : 0.0;
?>


<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div>
        <h3 class="mb-1">Front Office &amp; Property Overview</h3>
        <div class="d-flex flex-wrap align-items-center gap-2 text-muted small">
            <span>Hotel Management System (KES)</span>
            <span>&bull;</span>
            <span>Occupancy: <strong class="text-dark"><?= $occupancyRate ?>%</strong></span>
            <span>&bull;</span>
            <span><?= date('l, d M Y') ?></span>
        </div>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <?php if (hasRole(['Administrator', 'Hotel Manager', 'Receptionist'])): ?>
            <a class="btn btn-primary" href="/hms/rooms/search.php">Search &amp; Book</a>
            <a class="btn btn-success" href="/hms/frontdesk/checkin.php">Check-In Guest</a>
            <a class="btn btn-warning" href="/hms/frontdesk/checkout.php">Check-Out Guest</a>
        <?php endif; ?>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-4 col-lg-2">
        <div class="card hms-kpi-card kpi-navy shadow-sm text-center h-100">
            <div class="card-body py-3">
                <div class="hms-kpi-label mb-1">Total Rooms</div>
                <div class="fs-3 fw-bold text-dark"><?= $totalRooms ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-lg-2">
        <div class="card hms-kpi-card kpi-green shadow-sm text-center h-100">
            <div class="card-body py-3">
                <div class="hms-kpi-label mb-1">Available</div>
                <div class="fs-3 fw-bold text-success"><?= $availableRooms ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-lg-2">
        <div class="card hms-kpi-card kpi-blue shadow-sm text-center h-100">
            <div class="card-body py-3">
                <div class="hms-kpi-label mb-1">Occupied</div>
                <div class="fs-3 fw-bold text-primary"><?= $occupiedRooms ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-lg-2">
        <div class="card hms-kpi-card kpi-red shadow-sm text-center h-100">
            <div class="card-body py-3">
                <div class="hms-kpi-label mb-1">Dirty</div>
                <div class="fs-3 fw-bold text-danger"><?= $dirtyRooms ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-lg-2">
        <div class="card hms-kpi-card kpi-gold shadow-sm text-center h-100">
            <div class="card-body py-3">
                <div class="hms-kpi-label mb-1">Active Stays</div>
                <div class="fs-3 fw-bold" style="color: #966f34;"><?= $activeStays ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-lg-2">
        <div class="card hms-kpi-card kpi-amber shadow-sm text-center h-100">
            <div class="card-body py-3">
                <div class="hms-kpi-label mb-1">Open Tasks</div>
                <div class="fs-3 fw-bold text-warning"><?= $pendingTasks ?></div>
            </div>
        </div>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-header">
        <h5 class="mb-0" style="font-family: 'Playfair Display', Georgia, serif; color: #162644;">Quick Navigation</h5>
    </div>
    <div class="card-body">
        <p class="text-muted small mb-3">Jump straight into a core hotel operation:</p>
        <div class="row g-3">
            <div class="col-md-6 col-lg-3">
                <a class="hms-action-tile" href="/hms/rooms/index.php">
                    <span>Room Status Board</span>
                    <span class="hms-action-arrow">&rarr;</span>
                </a>
            </div>

            <?php if (hasRole(['Administrator', 'Hotel Manager', 'Receptionist', 'Guest'])): ?>
                <div class="col-md-6 col-lg-3">
                    <a class="hms-action-tile" href="/hms/reservations/list.php">
                        <span>View Reservations</span>
                        <span class="hms-action-arrow">&rarr;</span>
                    </a>
                </div>
            <?php endif; ?>

            <?php if (hasRole(['Administrator', 'Hotel Manager', 'Receptionist', 'Accountant / Cashier'])): ?>
                <div class="col-md-6 col-lg-3">
                    <a class="hms-action-tile" href="/hms/billing/folio.php">
                        <span>Folios &amp; Loyalty</span>
                        <span class="hms-action-arrow">&rarr;</span>
                    </a>
                </div>
            <?php endif; ?>

            <?php if (hasRole(['Administrator', 'Hotel Manager', 'Housekeeping Supervisor', 'Housekeeper'])): ?>
                <div class="col-md-6 col-lg-3">
                    <a class="hms-action-tile" href="/hms/housekeeping/tasks.php">
                        <span>Housekeeping Tasks</span>
                        <span class="hms-action-arrow">&rarr;</span>
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>