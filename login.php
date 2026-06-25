<?php
session_start();
require_once 'db.php';

if (isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true) {
    header("Location: index.php");
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (!empty($username) && !empty($password)) {
        $stmt = $conn->prepare("SELECT username, password FROM accounts WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $row = $result->fetch_assoc();
            
            $passwordMatch = password_verify($password, $row['password']) || $row['password'] === $password;
            
            if ($passwordMatch) {
                $_SESSION['loggedin'] = true;
                $_SESSION['username'] = $row['username'];
                header("Location: index.php");
                exit;
            } else {
                $error = "Invalid username or password!";
            }
        } else {
            $error = "Invalid username or password!";
        }
        $stmt->close();
    } else {
        $error = "Please enter both username and password!";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - LibraSys</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
            height: 100vh;
            display: flex;
            align-items: center;
        }
        .login-card {
            max-width: 420px;
            width: 100%;
            border-radius: 16px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.2);
            background: white;
        }
        .navbar-brand {
            font-weight: bold;
        }
    </style>
</head>
<body>

<div class="container">
    <div class="login-card card p-5 mx-auto">
        <div class="text-center mb-4">
            <h1 class="text-primary"><i class="fas fa-book"></i> LibraSys</h1>
            <p class="text-muted">Library Management System</p>
        </div>
        
        <?php if ($error): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="mb-3">
                <label class="form-label">Username</label>
                <input type="text" name="username" class="form-control form-control-lg" placeholder="Enter your username" required>
            </div>
            <div class="mb-4">
                <label class="form-label">Password</label>
                <input type="password" name="password" class="form-control form-control-lg" placeholder="Enter your password" required>
            </div>
            <button type="submit" class="btn btn-primary btn-lg w-100">Login</button>

            <div class="text-center mt-3">
                <a href="index.php" class="text-muted text-decoration-none small">Not a librarian?</a>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>