<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
requireLogin();

$fullName = $_SESSION['full_name'] ?? 'User';
$role = $_SESSION['role'] ?? 'Guest';
$currentPath = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reynolds Hotels — Hotel Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="/hms/assets/css/style.css" rel="stylesheet">
    <style>
        :root {
            --hms-bg: #f6f4ef;
            --hms-surface: #ffffff;
            --hms-ink: #1e293b;
            --hms-muted: #64748b;
            --hms-border: #e5dfd3;
            --hms-navy: #162644;
            --hms-navy-hover: #223964;
            --hms-gold: #b48a4a;
            --hms-gold-soft: rgba(180, 138, 74, 0.16);
        }

        body {
            background-color: var(--hms-bg);
            color: var(--hms-ink);
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif;
        }

        h1, h2, h3, .navbar-brand {
            font-family: 'Playfair Display', Georgia, serif;
        }

        nav.navbar.hms-navbar {
            background-color: #13203a;
            background-image: linear-gradient(100deg, #111d34 0%, #1b2f54 100%);
            border-bottom: 2px solid var(--hms-gold);
            box-shadow: 0 6px 22px rgba(17, 29, 52, 0.16);
            padding-top: 0.6rem;
            padding-bottom: 0.6rem;
        }

        nav.navbar.hms-navbar .navbar-brand {
            color: #ffffff;
            font-size: 1.25rem;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .hms-brand-mark {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 30px;
            height: 30px;
            border-radius: 8px;
            background: var(--hms-gold-soft);
            border: 1px solid rgba(180, 138, 74, 0.65);
            color: #ebd09e;
            font-size: 0.88rem;
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-weight: 700;
        }

        nav.navbar.hms-navbar .nav-link {
            color: rgba(255, 255, 255, 0.88);
            font-weight: 500;
            font-size: 0.86rem;
            white-space: nowrap;
            border-radius: 8px;
            padding: 0.4rem 0.55rem;
        }

        nav.navbar.hms-navbar .nav-link:hover,
        nav.navbar.hms-navbar .nav-link.active {
            color: #ffffff;
            background-color: rgba(255, 255, 255, 0.14);
        }

        nav.navbar.hms-navbar .nav-link.active {
            box-shadow: inset 0 -2px 0 var(--hms-gold);
        }

        .hms-user-pill {
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 999px;
            padding: 0.28rem 0.7rem;
            color: #ffffff;
        }

        .hms-role-badge {
            background-color: var(--hms-gold);
            color: #111d34;
            font-weight: 700;
            border-radius: 999px;
            padding: 0.22em 0.55em;
        }

        .hms-logout-btn {
            color: #ffffff;
            border-color: rgba(255, 255, 255, 0.55);
            font-weight: 600;
        }

        .hms-logout-btn:hover {
            background-color: var(--hms-gold);
            border-color: var(--hms-gold);
            color: #111d34;
        }

        .card {
            background-color: var(--hms-surface);
            border: 1px solid var(--hms-border);
            border-radius: 0.75rem;
            box-shadow: 0 6px 24px rgba(22, 38, 68, 0.05);
        }

        @media print {
            .hms-navbar,
            .no-print {
                display: none !important;
            }
        }
    </style>
</head>
<body>
<nav class="navbar navbar-expand-xl navbar-dark hms-navbar mb-4">
    <div class="container-fluid">
        <a class="navbar-brand text-nowrap" href="/hms/dashboard.php">
            <span class="hms-brand-mark">R</span>
            <span>Reynolds Hotels</span>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#hmsNav" aria-controls="hmsNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="hmsNav">
            <ul class="navbar-nav me-auto mb-2 mb-xl-0">
                <li class="nav-item">
                    <a class="nav-link <?= $currentPath === '/hms/dashboard.php' ? 'active' : '' ?>" href="/hms/dashboard.php">Dashboard</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $currentPath === '/hms/rooms/search.php' ? 'active' : '' ?>" href="/hms/rooms/search.php">Search Rooms</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $currentPath === '/hms/rooms/index.php' ? 'active' : '' ?>" href="/hms/rooms/index.php">Rooms</a>
                </li>

                <?php if (hasRole(['Administrator', 'Hotel Manager', 'Receptionist', 'Guest'])): ?>
                    <li class="nav-item">
                        <a class="nav-link <?= str_starts_with($currentPath, '/hms/reservations/') ? 'active' : '' ?>" href="/hms/reservations/list.php">Reservations</a>
                    </li>
                <?php endif; ?>

                <?php if (hasRole(['Administrator', 'Hotel Manager', 'Receptionist'])): ?>
                    <li class="nav-item">
                        <a class="nav-link <?= $currentPath === '/hms/frontdesk/checkin.php' ? 'active' : '' ?>" href="/hms/frontdesk/checkin.php">Check-In</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= $currentPath === '/hms/frontdesk/checkout.php' ? 'active' : '' ?>" href="/hms/frontdesk/checkout.php">Check-Out</a>
                    </li>
                <?php endif; ?>

                <?php if (hasRole(['Administrator', 'Hotel Manager', 'Receptionist', 'Accountant / Cashier'])): ?>
                    <li class="nav-item">
                        <a class="nav-link <?= $currentPath === '/hms/billing/folio.php' ? 'active' : '' ?>" href="/hms/billing/folio.php">Folio</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= $currentPath === '/hms/billing/loyalty.php' ? 'active' : '' ?>" href="/hms/billing/loyalty.php">Loyalty</a>
                    </li>
                <?php endif; ?>

                <?php if (hasRole(['Administrator', 'Hotel Manager', 'Housekeeping Supervisor', 'Housekeeper'])): ?>
                    <li class="nav-item">
                        <a class="nav-link <?= str_starts_with($currentPath, '/hms/housekeeping/') ? 'active' : '' ?>" href="/hms/housekeeping/tasks.php">Housekeeping</a>
                    </li>
                <?php endif; ?>

                <?php if (hasRole(['Administrator'])): ?>
                    <li class="nav-item">
                        <a class="nav-link <?= str_starts_with($currentPath, '/hms/admin/') ? 'active' : '' ?>" href="/hms/admin/users.php">Users</a>
                    </li>
                <?php endif; ?>
            </ul>

            <div class="d-flex align-items-center gap-2 mt-2 mt-xl-0 flex-shrink-0">
                <span class="navbar-text hms-user-pill small text-nowrap">
                    <?= h($fullName) ?>
                    <span class="badge hms-role-badge ms-1"><?= h($role) ?></span>
                </span>
                <a class="btn btn-sm hms-logout-btn text-nowrap flex-shrink-0" href="/hms/auth/logout.php">Logout</a>
            </div>
        </div>
    </div>
</nav>
<div class="container pb-5"></div>