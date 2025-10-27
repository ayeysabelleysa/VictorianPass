<?php
session_start();
include 'connect.php';

// Check if already logged in
if (isset($_SESSION['admin_id'])) {
    header("Location: admin.php");
    exit;
}

$error = '';

// Handle login form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = $_POST['email'];
    $password = $_POST['password'];
    
    // Validate input
    if (empty($email) || empty($password)) {
        $error = "Please enter both email and password";
    } else {
        // Query the staff table
        $query = "SELECT * FROM staff WHERE email = ?";
        $stmt = $con->prepare($query);
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows == 1) {
            $staff = $result->fetch_assoc();
            
            // In a production environment, use password_verify() with hashed passwords
            // For now, we're using plain text comparison as per the provided schema
            if ($password == $staff['password']) {
                // Set session variables
                $_SESSION['admin_id'] = $staff['id'];
                $_SESSION['admin_email'] = $staff['email'];
                $_SESSION['admin_role'] = $staff['role'];
                
                // Redirect to admin dashboard
                header("Location: admin.php");
                exit;
            } else {
                $error = "Invalid password";
            }
        } else {
            $error = "Email not found";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width,initial-scale=1"/>
    <title>VictorianPass | Admin Login</title>
    <link rel="icon" type="image/png" href="mainpage/logo.svg">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-dark: #2b2623;
            --nav-cream: #f4efe6;
            --accent: #23412e;
            --header-beige: #f7efe3;
            --card: #ffffff;
            --muted: #8b918d;
            --shadow: 0 8px 18px rgba(0,0,0,0.08);
        }
        * {
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }
        body {
            margin: 0;
            background: #f3efe9;
            color: #222;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
        }
        .login-container {
            background: var(--card);
            border-radius: 12px;
            box-shadow: var(--shadow);
            width: 400px;
            padding: 30px;
        }
        .login-header {
            text-align: center;
            margin-bottom: 30px;
        }
        .login-header img {
            height: 60px;
            margin-bottom: 15px;
        }
        .login-header h1 {
            margin: 0;
            font-size: 1.5rem;
            color: var(--accent);
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            font-size: 0.9rem;
        }
        .form-group input {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 1rem;
        }
        .btn-login {
            background: var(--accent);
            color: white;
            border: none;
            border-radius: 6px;
            padding: 12px;
            font-size: 1rem;
            font-weight: 600;
            width: 100%;
            cursor: pointer;
            transition: background-color 0.2s;
        }
        .btn-login:hover {
            background: #1a3023;
        }
        .error-message {
            color: #e74c3c;
            text-align: center;
            margin-bottom: 20px;
            font-size: 0.9rem;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <img src="mainpage/logo.svg" alt="VictorianPass Logo">
            <h1>Admin Login</h1>
        </div>
        
        <?php if (!empty($error)): ?>
            <div class="error-message"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <form method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
            <div class="form-group">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" required>
            </div>
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required>
            </div>
            <button type="submit" class="btn-login">Login</button>
        </form>
    </div>
</body>
</html>