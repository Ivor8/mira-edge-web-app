<?php
/**
 * Login Page
 */

require_once 'includes/core/Database.php';
require_once 'includes/core/Session.php';
require_once 'includes/core/Auth.php';
require_once 'includes/functions/helpers.php';

$session = new Session();
$auth = new Auth();

// Check if already logged in
if ($session->isLoggedIn()) {
    $role = $session->getUserRole();
    if (in_array($role, ['super_admin', 'admin', 'team_leader'])) {
        redirect('/admin/index.php');
    } elseif ($role === 'developer') {
        redirect('/developer/dashboard.php');
    } else {
        redirect('/');
    }
}

// Handle login form submission
$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = sanitize($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember = isset($_POST['remember']);
    
    if (empty($username)) {
        $errors['username'] = 'Username or email is required';
    }
    
    if (empty($password)) {
        $errors['password'] = 'Password is required';
    }
    
    if (empty($errors)) {
        $result = $auth->login($username, $password, $remember);
        
        if ($result['success']) {
            $role = $result['user']['role'];
            $session->setFlash('success', 'Welcome back, ' . $result['user']['first_name'] . '!');
            
            // Redirect based on role
            if (in_array($role, ['super_admin', 'admin', 'team_leader'])) {
                redirect('/admin/index.php');
            } elseif ($role === 'developer') {
                redirect ('/mira edge/developer/dashboard.php');
            } else {
                redirect('/');
            }
        } else {
            $errors['general'] = $result['message'];
        }
    }
}

// Check remember me cookie
$auth->checkRememberMe();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | Mira Edge Technologies</title>
    <link rel="stylesheet" href="<?php echo url('assets/css/main.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #000000 0%, #1a1a1a 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            color: #ffffff;
        }
        
        .login-container {
            width: 100%;
            max-width: 450px;
            animation: fadeIn 0.8s ease-out;
        }
        
        .login-card {
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 40px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.5);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .logo {
            text-align: center;
            margin-bottom: 40px;
        }
        
        .logo h1 {
            font-size: 2.5rem;
            font-weight: 800;
            background: linear-gradient(45deg, #ffffff, #cccccc);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            letter-spacing: 2px;
        }
        
        .logo .tech {
            color: #ffffff;
        }
        
        .logo p {
            color: #aaa;
            font-size: 0.9rem;
            margin-top: 5px;
            letter-spacing: 3px;
            text-transform: uppercase;
        }
        
        .form-group {
            margin-bottom: 25px;
            position: relative;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #ddd;
            font-size: 0.9rem;
            font-weight: 500;
        }
        
        .input-with-icon {
            position: relative;
        }
        
        .input-with-icon i {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #888;
            z-index: 1;
        }
        
        .form-control {
            width: 100%;
            padding: 15px 15px 15px 45px;
            background: rgba(255, 255, 255, 0.07);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 10px;
            color: #fff;
            font-size: 1rem;
            transition: all 0.3s ease;
        }
        
        .form-control:focus {
            outline: none;
            border-color: #fff;
            box-shadow: 0 0 0 2px rgba(255, 255, 255, 0.2);
            background: rgba(255, 255, 255, 0.1);
        }
        
        .error-message {
            color: #ff6b6b;
            font-size: 0.85rem;
            margin-top: 5px;
            display: block;
            min-height: 20px;
        }
        
        .checkbox-group {
            display: flex;
            align-items: center;
            margin-bottom: 25px;
        }
        
        .checkbox-group input {
            margin-right: 10px;
            transform: scale(1.2);
        }
        
        .checkbox-group label {
            color: #ccc;
            font-size: 0.9rem;
            cursor: pointer;
        }
        
        .btn-login {
            width: 100%;
            padding: 15px;
            background: linear-gradient(45deg, #000, #333);
            color: #fff;
            border: none;
            border-radius: 10px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            letter-spacing: 1px;
            text-transform: uppercase;
        }
        
        .btn-login:hover {
            background: linear-gradient(45deg, #333, #000);
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.3);
        }
        
        .btn-login:active {
            transform: translateY(0);
        }
        
        .login-footer {
            text-align: center;
            margin-top: 30px;
            color: #888;
            font-size: 0.9rem;
        }
        
        .login-footer a {
            color: #fff;
            text-decoration: none;
            font-weight: 500;
            transition: color 0.3s ease;
        }
        
        .login-footer a:hover {
            color: #ccc;
            text-decoration: underline;
        }
        
        .alert {
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 25px;
            animation: slideIn 0.5s ease-out;
        }
        
        .alert-error {
            background: rgba(255, 107, 107, 0.1);
            border: 1px solid rgba(255, 107, 107, 0.3);
            color: #ff6b6b;
        }
        
        .alert-success {
            background: rgba(107, 255, 157, 0.1);
            border: 1px solid rgba(107, 255, 157, 0.3);
            color: #6bff9d;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        @keyframes slideIn {
            from { opacity: 0; transform: translateX(-20px); }
            to { opacity: 1; transform: translateX(0); }
        }
        
        @media (max-width: 480px) {
            .login-card {
                padding: 30px 20px;
            }
            
            .logo h1 {
                font-size: 2rem;
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-card">
            <div class="logo">
                <h1>MIRA<span class="tech">EDGE</span></h1>

            </div>
            
            <?php if ($session->hasFlash('success')): ?>
                <div class="alert alert-success">
                    <?php echo e($session->getFlash('success')); ?>
                </div>
            <?php endif; ?>
            
            <?php if ($session->hasFlash('error')): ?>
                <div class="alert alert-error">
                    <?php echo e($session->getFlash('error')); ?>
                </div>
            <?php endif; ?>
            
            <?php if (isset($errors['general'])): ?>
                <div class="alert alert-error">
                    <?php echo e($errors['general']); ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="">
                <div class="form-group">
                    <label for="username">Username or Email</label>
                    <div class="input-with-icon">
                        <i class="fas fa-user"></i>
                        <input type="text" 
                               id="username" 
                               name="username" 
                               class="form-control" 
                               value="<?php echo e($_POST['username'] ?? ''); ?>"
                               required>
                    </div>
                    <?php if (isset($errors['username'])): ?>
                        <span class="error-message"><?php echo e($errors['username']); ?></span>
                    <?php endif; ?>
                </div>
                
                <div class="form-group">
                    <label for="password">Password</label>
                    <div class="input-with-icon">
                        <i class="fas fa-lock"></i>
                        <input type="password" 
                               id="password" 
                               name="password" 
                               class="form-control" 
                               required>
                    </div>
                    <?php if (isset($errors['password'])): ?>
                        <span class="error-message"><?php echo e($errors['password']); ?></span>
                    <?php endif; ?>
                </div>
                
                <div class="checkbox-group">
                    <input type="checkbox" id="remember" name="remember">
                    <label for="remember">Remember me</label>
                </div>
                
                <button type="submit" class="btn-login">
                    <i class="fas fa-sign-in-alt"></i> Login
                </button>
            </form>
            
            <div class="login-footer">
                <p>Need help? <a href="mailto:support@miraedgetech.com">Contact Support</a></p>
                <p>© <?php echo date('Y'); ?> Mira Edge Technologies. All rights reserved.</p>
            </div>
        </div>
    </div>
    
    <script>
        // Add focus effects
        document.querySelectorAll('.form-control').forEach(input => {
            input.addEventListener('focus', function() {
                this.parentElement.parentElement.style.transform = 'scale(1.02)';
            });
            
            input.addEventListener('blur', function() {
                this.parentElement.parentElement.style.transform = 'scale(1)';
            });
        });
    </script>
</body>
</html>