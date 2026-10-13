<?php

/**
 * User Management Page
 * 
 * This script provides functionality for an admin to manage users, including approving, setting to pending, deleting users,
 * and changing user roles. It also includes CSRF protection and session security enhancements.
 * 
 */

// Start a new session or resume the existing session
session_start();

// Include the database connection file
require 'dbcon.php';

// Check if the user is logged in and has admin role
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    // Redirect non-admin users to the index page
    header("Location: index.php");
    exit; // Ensure no further code is executed
}

// CSRF token generation
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Handle POST requests for user status and role updates
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die('CSRF token validation failed');
    }

    $username = filter_input(INPUT_POST, 'username', FILTER_SANITIZE_STRING);
    $action = filter_input(INPUT_POST, 'action', FILTER_SANITIZE_STRING);

    // Create a new account (self-registration is disabled, only admins create users)
    if ($action === 'create') {
        $newName = trim($_POST['new_name'] ?? '');
        $newEmail = trim($_POST['new_email'] ?? '');
        $newPosition = trim($_POST['new_position'] ?? '');
        $newRole = ($_POST['new_role'] ?? '') === 'admin' ? 'admin' : 'user';
        $newPassword = $_POST['new_password'] ?? '';
        // Admins are BEA responsible by default (can be removed later with the BEA button)
        $newBea = ($newRole === 'admin' || !empty($_POST['new_bea'])) ? 1 : 0;

        if ($newName === '' || $newPosition === '' || !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['userMessage'] = ['danger', 'Name, position and a valid email are required.'];
        } elseif (strlen($newPassword) < 8) {
            $_SESSION['userMessage'] = ['danger', 'The password must contain at least 8 characters.'];
        } else {
            $checkStmt = mysqli_prepare($con, "SELECT id FROM users WHERE username = ?");
            mysqli_stmt_bind_param($checkStmt, "s", $newEmail);
            mysqli_stmt_execute($checkStmt);
            mysqli_stmt_store_result($checkStmt);
            $exists = mysqli_stmt_num_rows($checkStmt) > 0;
            mysqli_stmt_close($checkStmt);

            if ($exists) {
                $_SESSION['userMessage'] = ['danger', 'A user with this email already exists.'];
            } else {
                // Accounts created by an admin are approved and verified immediately
                $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT);
                $insertStmt = mysqli_prepare($con, "INSERT INTO users (name, username, position, role, password, status, email_verified, is_bea_responsible) VALUES (?, ?, ?, ?, ?, 'approved', 1, ?)");
                mysqli_stmt_bind_param($insertStmt, "sssssi", $newName, $newEmail, $newPosition, $newRole, $hashedPassword, $newBea);
                if (mysqli_stmt_execute($insertStmt)) {
                    $_SESSION['userMessage'] = ['success', 'User ' . $newEmail . ' created.'];
                } else {
                    error_log("Database error: " . mysqli_error($con));
                    $_SESSION['userMessage'] = ['danger', 'User creation failed.'];
                }
                mysqli_stmt_close($insertStmt);
            }
        }

        // Redirect to avoid form resubmission
        header("Location: manage_users.php");
        exit;
    }

    // Initialize query variables
    $query = "";

    // Determine the action to take: approve, set to pending, delete user, set role to admin or user
    switch ($action) {
        case 'approve':
            $query = "UPDATE users SET status='approved' WHERE username=?";
            break;
        case 'pending':
            $query = "UPDATE users SET status='pending' WHERE username=?";
            break;
        case 'delete':
            $query = "DELETE FROM users WHERE username=?";
            break;
        case 'admin':
            $query = "UPDATE users SET role='admin', is_bea_responsible=1 WHERE username=?";
            break;
        case 'user':
            $query = "UPDATE users SET role='user' WHERE username=?";
            break;
        case 'bea_on':
            $query = "UPDATE users SET is_bea_responsible=1 WHERE username=?";
            break;
        case 'bea_off':
            $query = "UPDATE users SET is_bea_responsible=0 WHERE username=?";
            break;
        default:
            die('Invalid action');
    }

    // Execute the prepared statement if a valid action is set
    if (!empty($query)) {
        $statement = mysqli_prepare($con, $query);
        if ($statement) {
            mysqli_stmt_bind_param($statement, "s", $username);
            mysqli_stmt_execute($statement);
            mysqli_stmt_close($statement);
        } else {
            // Log error and handle it gracefully
            error_log("Database error: " . mysqli_error($con));
            die('Database error');
        }
    }
}

// Retrieve and clear the creation result message
$userMessage = $_SESSION['userMessage'] ?? null;
unset($_SESSION['userMessage']);

$positions = [
    'Principal Investigator', 'Research Scientist', 'Postdoctoral Researcher', 'PhD Student',
    'Masters Student', 'Undergraduate', 'Laboratory Technician', 'Research Associate',
    'Lab Manager', 'Animal Care Technician', 'Interns and Volunteers',
];

// Fetch all users from the database
$userquery = "SELECT * FROM users";
$userresult = mysqli_query($con, $userquery);

// Include the header file
require 'header.php';
mysqli_close($con);
?>

<!doctype html>
<html lang="en">

<head>
    <!-- Required meta tags -->
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>User Management | <?php echo htmlspecialchars($labName); ?></title>

    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-1BmE4kWBq78iYhFldvKuhfTAU6auU8tT94WrHftjDbrCEXSU1oBoqyl2QvZ6jIW3" crossorigin="anonymous">

    <!-- Inline CSS for styling -->
    <style>
        body {
            margin: 0;
            padding: 0;
        }

        .main-content {
            justify-content: center;
            align-items: center;
        }

        .action-buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
        }

        .action-buttons .btn {
            flex: 1 1 auto;
        }

        @media (max-width: 576px) {

            .table th,
            .table td {
                display: block;
                width: 100%;
            }

            .table thead {
                display: none;
            }

            .table tr {
                margin-bottom: 15px;
            }

            .table td::before {
                content: attr(data-label);
                font-weight: bold;
                text-transform: uppercase;
                margin-bottom: 5px;
                display: block;
            }
        }
    </style>

    <script>
        var currentAdminUsername = "<?php echo htmlspecialchars($_SESSION['username']); ?>";

        function confirmAdminAction(username) {
            if (username === currentAdminUsername) {
                return confirm("Are you sure you want to change settings for your own account?");
            }
            return true;
        }

        // Admins are BEA responsible by default
        function syncBeaWithRole() {
            var isAdmin = document.getElementById('new_role').value === 'admin';
            var bea = document.getElementById('new_bea');
            if (isAdmin) {
                bea.checked = true;
            }
            bea.disabled = isAdmin;
        }
    </script>
</head>

<body>
    <div class="container mt-4 content">
        <div class="main-content">
            <h1 class="text-center">User Management</h1>

            <?php if ($userMessage) { ?>
                <div class="alert alert-<?php echo htmlspecialchars($userMessage[0]); ?>"><?php echo htmlspecialchars($userMessage[1]); ?></div>
            <?php } ?>

            <div class="card mb-4">
                <div class="card-header"><i class="fas fa-user-plus"></i> Create a user</div>
                <div class="card-body">
                    <form action="manage_users.php" method="post" class="row g-2" autocomplete="off">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <input type="hidden" name="action" value="create">
                        <div class="col-md-4">
                            <label for="new_name" class="form-label">Full name</label>
                            <input type="text" class="form-control" id="new_name" name="new_name" required>
                        </div>
                        <div class="col-md-4">
                            <label for="new_email" class="form-label">Email (login)</label>
                            <input type="email" class="form-control" id="new_email" name="new_email" required>
                        </div>
                        <div class="col-md-4">
                            <label for="new_position" class="form-label">Position</label>
                            <select class="form-select" id="new_position" name="new_position" required>
                                <option value="" disabled selected>Select Position</option>
                                <?php foreach ($positions as $position) { ?>
                                    <option value="<?php echo htmlspecialchars($position); ?>"><?php echo htmlspecialchars($position); ?></option>
                                <?php } ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label for="new_password" class="form-label">Initial password</label>
                            <input type="password" class="form-control" id="new_password" name="new_password" minlength="8" required autocomplete="new-password">
                        </div>
                        <div class="col-md-3">
                            <label for="new_role" class="form-label">Role</label>
                            <select class="form-select" id="new_role" name="new_role" onchange="syncBeaWithRole()">
                                <option value="user" selected>User</option>
                                <option value="admin">Admin</option>
                            </select>
                        </div>
                        <div class="col-md-3 d-flex align-items-end">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="new_bea" name="new_bea" value="1">
                                <label class="form-check-label" for="new_bea">Responsable BEA</label>
                            </div>
                        </div>
                        <div class="col-md-2 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary w-100">Create</button>
                        </div>
                    </form>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Username</th>
                            <th>Status</th>
                            <th>Role</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($row = mysqli_fetch_assoc($userresult)) { ?>
                            <tr>
                                <td data-label="Name"><?php echo htmlspecialchars($row['name']); ?></td>
                                <td data-label="Username"><?php echo htmlspecialchars($row['username']); ?></td>
                                <td data-label="Status"><?php echo htmlspecialchars($row['status']); ?></td>
                                <td data-label="Role"><?php echo htmlspecialchars($row['role']); ?></td>
                                <td data-label="Actions">
                                    <form action="manage_users.php" method="post" class="action-buttons" onsubmit="return confirmAdminAction('<?php echo htmlspecialchars($row['username']); ?>')">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                        <input type="hidden" name="name" value="<?php echo htmlspecialchars($row['name']); ?>">
                                        <input type="hidden" name="username" value="<?php echo htmlspecialchars($row['username']); ?>">

                                        <?php if ($row['status'] === 'pending') { ?>
                                            <button type="submit" class="btn btn-success btn-sm" name="action" value="approve" title="Approve User"><i class="fas fa-check"></i></button>
                                        <?php } elseif ($row['status'] === 'approved') { ?>
                                            <button type="submit" class="btn btn-secondary btn-sm" name="action" value="pending" title="Deactivate User"><i class="fas fa-ban"></i></button>
                                        <?php } ?>

                                        <?php if ($row['role'] === 'user') { ?>
                                            <button type="submit" class="btn btn-warning btn-sm" name="action" value="admin" title="Make Admin"><i class="fas fa-user-shield"></i></button>
                                        <?php } elseif ($row['role'] == 'admin') { ?>
                                            <button type="submit" class="btn btn-info btn-sm" name="action" value="user" title="Make User"><i class="fas fa-user"></i></button>
                                        <?php } ?>
                                        <button type="submit" class="btn btn-<?= !empty($row['is_bea_responsible']) ? 'outline-info' : 'info'; ?> btn-sm" name="action" value="<?= !empty($row['is_bea_responsible']) ? 'bea_off' : 'bea_on'; ?>" title="<?= !empty($row['is_bea_responsible']) ? 'Retirer le rôle responsable BEA' : 'Désigner responsable BEA'; ?>"><i class="fas fa-<?= !empty($row['is_bea_responsible']) ? 'user-times' : 'user-check'; ?>"></i> BEA</button>

                                        <button type="submit" class="btn btn-danger btn-sm" name="action" value="delete" title="Delete User"><i class="fas fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php } ?>
                    </tbody>

                </table>
            </div>
        </div>
    </div>

    <!-- Include the footer file -->
    <?php include 'footer.php'; ?>
</body>

</html>
