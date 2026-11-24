<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';

if (!empty($_SESSION['user_id'])) {
    header('Location: /hms/dashboard.php');
    exit;
}

$error = '';

/**
 * Helper to fetch user by username/email, reconnecting once if MySQL dropped the connection (2006).
 */
function findUserForLogin(PDO &$pdo, string $loginInput): array|false
{
    $sql = 'SELECT user_id, full_name, username, email, password_hash, role, is_active
            FROM users
            WHERE username = :username OR email = :email
            LIMIT 1';

    $runQuery = static function (PDO $conn) use ($sql, $loginInput): array|false {
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            'username' => $loginInput,
            'email'    => $loginInput,
        ]);
        return $stmt->fetch();
    };

    try {
        return $runQuery($pdo);
    } catch (PDOException $e) {
        $driverCode = (int) ($e->errorInfo[1] ?? 0);
        if ($driverCode === 2006 || str_contains($e->getMessage(), '2006')) {
            // Re-establish PDO connection using the same config settings
            $dbConfigFile = __DIR__ . '/../config/db.php';
            if (is_file($dbConfigFile)) {
                require $dbConfigFile;
            }
            if ($pdo instanceof PDO) {
                return $runQuery($pdo);
            }
        }
        throw $e;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $loginInput = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($loginInput === '' || $password === '') {
        $error = 'Please enter both username/email and password.';
    } else {
        try {
            $user = findUserForLogin($pdo, $loginInput);

            if (!$user || !(int) $user['is_active']) {
                $error = 'Invalid credentials or inactive account.';
            } elseif (!password_verify($password, (string) $user['password_hash'])) {
                $error = 'Invalid credentials.';
            } else {
                session_regenerate_id(true);
                $_SESSION['user_id'] = (int) $user['user_id'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['last_activity'] = time();

                header('Location: /hms/dashboard.php');
                exit;
            }
        } catch (PDOException) {
            $error = 'Database connection was interrupted. Please ensure MySQL is running and try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In — Reynolds Hotels</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --hms-bg: #f6f4ef;
            --hms-surface: #ffffff;
            --hms-ink: #1e293b;
            --hms-muted: #64748b;
            --hms-border: #e5dfd3;
            --hms-navy: #13203a;
            --hms-navy-hover: #1d3258;
            --hms-gold: #b48a4a;
        }

        body {
            background-color: var(--hms-bg);
            color: var(--hms-ink);
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif;
        }

        .login-card {
            background-color: var(--hms-surface);
            border: 1px solid var(--hms-border);
            border-top: 4px solid var(--hms-gold);
            border-radius: 0.9rem;
            box-shadow: 0 12px 32px rgba(17, 29, 52, 0.08);
        }

        .brand-title {
            font-family: 'Playfair Display', Georgia, serif;
            color: var(--hms-navy);
            font-weight: 700;
        }

        .btn-reynolds {
            background-color: var(--hms-navy);
            border-color: var(--hms-navy);
            color: #ffffff;
            font-weight: 600;
        }

        .btn-reynolds:hover {
            background-color: var(--hms-navy-hover);
            border-color: var(--hms-navy-hover);
            color: #ffffff;
        }
    </style>
</head>
<body class="d-flex align-items-center min-vh-100">
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-4">
            <div class="card login-card">
                <div class="card-body p-4">
                    <div class="text-center mb-3">
                        <h4 class="brand-title mb-1">Reynolds Hotels</h4>
                        <div class="small text-muted">Hotel Management System</div>
                    </div>

                    <?php if (isset($_GET['timeout'])): ?>
                        <div class="alert alert-warning py-2">Session timed out due to inactivity.</div>
                    <?php endif; ?>

                    <?php if ($error !== ''): ?>
                        <div class="alert alert-danger py-2"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>

                    <form method="POST" action="">
                        <div class="mb-3">
                            <label class="form-label" for="username">Username or Email</label>
                            <input class="form-control" type="text" id="username" name="username" required autofocus>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="password">Password</label>
                            <input class="form-control" type="password" id="password" name="password" required>
                        </div>
                        <button class="btn btn-reynolds w-100 py-2" type="submit">Log In</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>