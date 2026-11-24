<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';

requireRole(['Administrator']);

require_once __DIR__ . '/../includes/header.php';

$allowedRoles = [
    'Administrator',
    'Hotel Manager',
    'Receptionist',
    'Housekeeping Supervisor',
    'Housekeeper',
    'Accountant / Cashier',
    'Guest',
];

$currentAdminId = (int) ($_SESSION['user_id'] ?? 0);
$errors = [];
$success = '';
$editUser = null;

if (isset($_GET['edit'])) {
    $editId = (int) $_GET['edit'];
    if ($editId > 0) {
        $stmt = $pdo->prepare('SELECT user_id, full_name, username, email, role, is_active FROM users WHERE user_id = ?');
        $stmt->execute([$editId]);
        $fetched = $stmt->fetch();
        if ($fetched) {
            $editUser = $fetched;
        } else {
            $errors[] = 'User account not found for editing.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // 1. Create User
        if (isset($_POST['create_user'])) {
            $fullName = trim((string) ($_POST['full_name'] ?? ''));
            $username = trim((string) ($_POST['username'] ?? ''));
            $email = trim((string) ($_POST['email'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');
            $role = (string) ($_POST['role'] ?? '');
            $isActive = isset($_POST['is_active']) ? 1 : 0;

            if ($fullName === '' || $username === '' || $email === '' || $password === '') {
                throw new RuntimeException('Full name, username, email, and password are required.');
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Please enter a valid email address.');
            }
            if (strlen($password) < 8) {
                throw new RuntimeException('Password must be at least 8 characters long.');
            }
            if (!in_array($role, $allowedRoles, true)) {
                throw new RuntimeException('Please select a valid role.');
            }

            $hash = password_hash($password, PASSWORD_BCRYPT);
            $ins = $pdo->prepare(
                'INSERT INTO users (full_name, username, email, password_hash, role, is_active)
                 VALUES (:full_name, :username, :email, :password_hash, :role, :is_active)'
            );
            $ins->execute([
                'full_name'     => $fullName,
                'username'      => $username,
                'email'         => $email,
                'password_hash' => $hash,
                'role'          => $role,
                'is_active'     => $isActive,
            ]);

            $success = "User account '{$username}' created successfully.";
        }

        // 2. Update User
        if (isset($_POST['update_user'])) {
            $targetId = (int) ($_POST['user_id'] ?? 0);
            $fullName = trim((string) ($_POST['full_name'] ?? ''));
            $username = trim((string) ($_POST['username'] ?? ''));
            $email = trim((string) ($_POST['email'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');
            $role = (string) ($_POST['role'] ?? '');
            $isActive = isset($_POST['is_active']) ? 1 : 0;

            if ($targetId <= 0) {
                throw new RuntimeException('Invalid user selected.');
            }
            if ($fullName === '' || $username === '' || $email === '') {
                throw new RuntimeException('Full name, username, and email are required.');
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Please enter a valid email address.');
            }
            if (!in_array($role, $allowedRoles, true)) {
                throw new RuntimeException('Please select a valid role.');
            }
            if ($targetId === $currentAdminId && $isActive === 0) {
                throw new RuntimeException('You cannot deactivate your own logged-in administrator account.');
            }

            if ($password !== '') {
                if (strlen($password) < 8) {
                    throw new RuntimeException('New password must be at least 8 characters long.');
                }
                $hash = password_hash($password, PASSWORD_BCRYPT);
                $upd = $pdo->prepare(
                    'UPDATE users
                     SET full_name = :full_name, username = :username, email = :email,
                         password_hash = :password_hash, role = :role, is_active = :is_active
                     WHERE user_id = :user_id'
                );
                $upd->execute([
                    'full_name'     => $fullName,
                    'username'      => $username,
                    'email'         => $email,
                    'password_hash' => $hash,
                    'role'          => $role,
                    'is_active'     => $isActive,
                    'user_id'       => $targetId,
                ]);
            } else {
                $upd = $pdo->prepare(
                    'UPDATE users
                     SET full_name = :full_name, username = :username, email = :email,
                         role = :role, is_active = :is_active
                     WHERE user_id = :user_id'
                );
                $upd->execute([
                    'full_name' => $fullName,
                    'username'  => $username,
                    'email'     => $email,
                    'role'      => $role,
                    'is_active' => $isActive,
                    'user_id'   => $targetId,
                ]);
            }

            $success = "User account '{$username}' updated.";
            $editUser = null;
        }

        // 3. Activate / Deactivate User
        if (isset($_POST['toggle_active'])) {
            $targetId = (int) ($_POST['user_id'] ?? 0);
            $newStatus = (int) ($_POST['next_active'] ?? 0) === 1 ? 1 : 0;

            if ($targetId <= 0) {
                throw new RuntimeException('Invalid user account.');
            }
            if ($targetId === $currentAdminId && $newStatus === 0) {
                throw new RuntimeException('You cannot deactivate your own logged-in administrator account.');
            }

            $tog = $pdo->prepare('UPDATE users SET is_active = :active WHERE user_id = :user_id');
            $tog->execute([
                'active'  => $newStatus,
                'user_id' => $targetId,
            ]);

            $success = $newStatus === 1 ? 'User account activated.' : 'User account deactivated.';
        }

        // 4. Delete User
        if (isset($_POST['delete_user'])) {
            $targetId = (int) ($_POST['user_id'] ?? 0);

            if ($targetId <= 0) {
                throw new RuntimeException('Invalid user account.');
            }
            if ($targetId === $currentAdminId) {
                throw new RuntimeException('You cannot delete your own logged-in administrator account.');
            }

            $del = $pdo->prepare('DELETE FROM users WHERE user_id = :user_id');
            $del->execute(['user_id' => $targetId]);

            $success = 'User account deleted.';
            if ($editUser && (int) $editUser['user_id'] === $targetId) {
                $editUser = null;
            }
        }
    } catch (PDOException $e) {
        if ((string) $e->getCode() === '23000') {
            $errors[] = 'Username or email already exists, or the account cannot be deleted due to linked records (deactivate it instead).';
        } else {
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

$users = $pdo->query(
    'SELECT user_id, full_name, username, email, role, is_active, created_at
     FROM users
     ORDER BY user_id ASC'
)->fetchAll();

$formValues = $editUser ?? [
    'user_id'   => 0,
    'full_name' => '',
    'username'  => '',
    'email'     => '',
    'role'      => 'Receptionist',
    'is_active' => 1,
];
$isEditing = $editUser !== null;
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h3 class="mb-0">User Account Administration</h3>
        <small class="text-muted">Create, update, activate/deactivate, and delete system user accounts (FR-003)</small>
    </div>
    <?php if ($isEditing): ?>
        <a href="/hms/admin/users.php" class="btn btn-outline-secondary btn-sm">Cancel Edit</a>
    <?php endif; ?>
</div>

<?php if ($success !== ''): ?>
    <div class="alert alert-success"><?= h($success) ?></div>
<?php endif; ?>

<?php foreach ($errors as $err): ?>
    <div class="alert alert-danger"><?= h($err) ?></div>
<?php endforeach; ?>

<div class="row g-4">
    <div class="col-lg-4">
        <div class="card shadow-sm">
            <div class="card-header">
                <strong><?= $isEditing ? 'Edit User #' . (int) $formValues['user_id'] : 'Create New User' ?></strong>
            </div>
            <div class="card-body">
                <form method="POST" action="/hms/admin/users.php">
                    <?php if ($isEditing): ?>
                        <input type="hidden" name="user_id" value="<?= (int) $formValues['user_id'] ?>">
                    <?php endif; ?>

                    <div class="mb-3">
                        <label class="form-label" for="full_name">Full Name</label>
                        <input type="text" id="full_name" name="full_name" class="form-control"
                               value="<?= h((string) $formValues['full_name']) ?>" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="username">Username</label>
                        <input type="text" id="username" name="username" class="form-control"
                               value="<?= h((string) $formValues['username']) ?>" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="email">Email Address</label>
                        <input type="email" id="email" name="email" class="form-control"
                               value="<?= h((string) $formValues['email']) ?>" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="password">
                            Password <?= $isEditing ? '<span class="text-muted small">(leave blank to keep current)</span>' : '' ?>
                        </label>
                        <input type="password" id="password" name="password" class="form-control"
                               minlength="8" <?= $isEditing ? '' : 'required' ?>>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="role">Role</label>
                        <select id="role" name="role" class="form-select" required>
                            <?php foreach ($allowedRoles as $r): ?>
                                <option value="<?= h($r) ?>" <?= $formValues['role'] === $r ? 'selected' : '' ?>>
                                    <?= h($r) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1"
                               <?= (int) $formValues['is_active'] === 1 ? 'checked' : '' ?>>
                        <label class="form-check-label" for="is_active">Account Active</label>
                    </div>

                    <?php if ($isEditing): ?>
                        <button type="submit" name="update_user" class="btn btn-primary w-100">Save Changes</button>
                    <?php else: ?>
                        <button type="submit" name="create_user" class="btn btn-primary w-100">Create Account</button>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="card shadow-sm">
            <div class="card-header">
                <strong>System Users (<?= count($users) ?>)</strong>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-striped mb-0">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Name &amp; Username</th>
                                <th>Email</th>
                                <th>Role</th>
                                <th>Status</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $u): ?>
                                <?php
                                $uid = (int) $u['user_id'];
                                $active = (int) $u['is_active'] === 1;
                                $isSelf = $uid === $currentAdminId;
                                ?>
                                <tr>
                                    <td><?= $uid ?></td>
                                    <td>
                                        <div class="fw-semibold"><?= h((string) $u['full_name']) ?></div>
                                        <div class="small text-muted">@<?= h((string) $u['username']) ?></div>
                                    </td>
                                    <td class="small"><?= h((string) $u['email']) ?></td>
                                    <td><span class="badge bg-dark"><?= h((string) $u['role']) ?></span></td>
                                    <td>
                                        <?php if ($active): ?>
                                            <span class="badge bg-success">Active</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end text-nowrap">
                                        <a href="/hms/admin/users.php?edit=<?= $uid ?>" class="btn btn-sm btn-outline-primary">Edit</a>

                                        <?php if (!$isSelf): ?>
                                            <form method="POST" action="/hms/admin/users.php" class="d-inline">
                                                <input type="hidden" name="user_id" value="<?= $uid ?>">
                                                <input type="hidden" name="next_active" value="<?= $active ? 0 : 1 ?>">
                                                <button type="submit" name="toggle_active"
                                                        class="btn btn-sm <?= $active ? 'btn-outline-warning' : 'btn-outline-success' ?>">
                                                    <?= $active ? 'Deactivate' : 'Activate' ?>
                                                </button>
                                            </form>

                                            <form method="POST" action="/hms/admin/users.php" class="d-inline"
                                                  onsubmit="return confirm('Delete user account @<?= h((string) $u['username']) ?>? This cannot be undone.');">
                                                <input type="hidden" name="user_id" value="<?= $uid ?>">
                                                <button type="submit" name="delete_user" class="btn btn-sm btn-outline-danger">Delete</button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>