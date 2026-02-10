<?php
/**
 * Authentication and Authorization Management
 */

class Auth {
    private $db;
    private $session;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
        $this->session = new Session();
    }
    
    /**
     * Register a new user
     */
    public function register($data) {
        try {
            // Validate input
            $errors = $this->validateRegistration($data);
            if (!empty($errors)) {
                return ['success' => false, 'errors' => $errors];
            }
            
            // Hash password
            $password_hash = password_hash($data['password'], PASSWORD_BCRYPT, [
                'cost' => DatabaseConfig::PASSWORD_COST
            ]);
            
            // Insert user
            $stmt = $this->db->prepare("
                INSERT INTO users 
                (username, email, password_hash, first_name, last_name, phone, role, status) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $role = $data['role'] ?? 'developer';
            $status = 'active';
            
            $stmt->execute([
                $data['username'],
                $data['email'],
                $password_hash,
                $data['first_name'],
                $data['last_name'],
                $data['phone'] ?? null,
                $role,
                $status
            ]);
            
            return ['success' => true, 'user_id' => $this->db->lastInsertId()];
            
        } catch (PDOException $e) {
            error_log("Registration Error: " . $e->getMessage());
            return ['success' => false, 'errors' => ['general' => 'Registration failed. Please try again.']];
        }
    }
    
    /**
     * Login user
     */
    public function login($usernameOrEmail, $password, $remember = false) {
        try {
            // Find user by username or email
            $stmt = $this->db->prepare("
                SELECT * FROM users 
                WHERE (username = ? OR email = ?) 
                AND status = 'active'
            ");
            $stmt->execute([$usernameOrEmail, $usernameOrEmail]);
            $user = $stmt->fetch();
            
            if (!$user || !password_verify($password, $user['password_hash'])) {
                return ['success' => false, 'message' => 'Invalid credentials'];
            }
            
            // Update last login
            $stmt = $this->db->prepare("UPDATE users SET last_login = NOW() WHERE user_id = ?");
            $stmt->execute([$user['user_id']]);
            
            // Remove sensitive data
            unset($user['password_hash']);
            
            // Set session
            $this->session->setUser($user);
            
            // Set remember me cookie if requested
            if ($remember) {
                $this->setRememberMe($user['user_id']);
            }
            
            return ['success' => true, 'user' => $user];
            
        } catch (PDOException $e) {
            error_log("Login Error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Login failed. Please try again.'];
        }
    }
    
    /**
     * Logout user
     */
    public function logout() {
        // Clear remember me token
        if (isset($_COOKIE['remember_token'])) {
            $this->clearRememberToken($_COOKIE['remember_token']);
            setcookie('remember_token', '', time() - 3600, '/');
        }
        
        $this->session->destroy();
    }
    
    /**
     * Check if user is logged in via remember me
     */
    public function checkRememberMe() {
        if (isset($_COOKIE['remember_token']) && !$this->session->isLoggedIn()) {
            $token = $_COOKIE['remember_token'];
            
            try {
                // Find user by remember token
                $stmt = $this->db->prepare("
                    SELECT u.* FROM users u
                    INNER JOIN password_resets pr ON u.user_id = pr.user_id
                    WHERE pr.token = ? AND pr.expires_at > NOW() AND pr.used = FALSE
                ");
                $stmt->execute([$token]);
                $user = $stmt->fetch();
                
                if ($user) {
                    // Mark token as used
                    $stmt = $this->db->prepare("UPDATE password_resets SET used = TRUE WHERE token = ?");
                    $stmt->execute([$token]);
                    
                    // Create new token
                    $this->setRememberMe($user['user_id']);
                    
                    // Set session
                    unset($user['password_hash']);
                    $this->session->setUser($user);
                    
                    return true;
                }
            } catch (PDOException $e) {
                error_log("Remember Me Error: " . $e->getMessage());
            }
        }
        return false;
    }
    
    /**
     * Set remember me token
     */
    private function setRememberMe($userId) {
        $token = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', time() + (30 * 24 * 60 * 60)); // 30 days
        
        try {
            // Clear old tokens
            $stmt = $this->db->prepare("DELETE FROM password_resets WHERE user_id = ? AND used = FALSE");
            $stmt->execute([$userId]);
            
            // Insert new token
            $stmt = $this->db->prepare("
                INSERT INTO password_resets (user_id, token, expires_at) 
                VALUES (?, ?, ?)
            ");
            $stmt->execute([$userId, $token, $expires]);
            
            // Set cookie
            setcookie('remember_token', $token, time() + (30 * 24 * 60 * 60), '/');
            
        } catch (PDOException $e) {
            error_log("Remember Token Error: " . $e->getMessage());
        }
    }
    
    /**
     * Clear remember token
     */
    private function clearRememberToken($token) {
        try {
            $stmt = $this->db->prepare("UPDATE password_resets SET used = TRUE WHERE token = ?");
            $stmt->execute([$token]);
        } catch (PDOException $e) {
            error_log("Clear Token Error: " . $e->getMessage());
        }
    }
    
    /**
     * Validate registration data
     */
    private function validateRegistration($data) {
        $errors = [];
        
        // Username validation
        if (empty($data['username'])) {
            $errors['username'] = 'Username is required';
        } elseif (strlen($data['username']) < 3) {
            $errors['username'] = 'Username must be at least 3 characters';
        } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $data['username'])) {
            $errors['username'] = 'Username can only contain letters, numbers, and underscores';
        } else {
            // Check if username exists
            $stmt = $this->db->prepare("SELECT user_id FROM users WHERE username = ?");
            $stmt->execute([$data['username']]);
            if ($stmt->fetch()) {
                $errors['username'] = 'Username already exists';
            }
        }
        
        // Email validation
        if (empty($data['email'])) {
            $errors['email'] = 'Email is required';
        } elseif (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Invalid email format';
        } else {
            // Check if email exists
            $stmt = $this->db->prepare("SELECT user_id FROM users WHERE email = ?");
            $stmt->execute([$data['email']]);
            if ($stmt->fetch()) {
                $errors['email'] = 'Email already registered';
            }
        }
        
        // Password validation
        if (empty($data['password'])) {
            $errors['password'] = 'Password is required';
        } elseif (strlen($data['password']) < 8) {
            $errors['password'] = 'Password must be at least 8 characters';
        } elseif ($data['password'] !== $data['confirm_password']) {
            $errors['confirm_password'] = 'Passwords do not match';
        }
        
        // First name validation
        if (empty($data['first_name'])) {
            $errors['first_name'] = 'First name is required';
        }
        
        // Last name validation
        if (empty($data['last_name'])) {
            $errors['last_name'] = 'Last name is required';
        }
        
        return $errors;
    }
    
    /**
     * Get user by ID
     */
    public function getUserById($userId) {
        try {
            $stmt = $this->db->prepare("
                SELECT user_id, username, email, first_name, last_name, phone, 
                       profile_image, position, role, status, created_at
                FROM users 
                WHERE user_id = ?
            ");
            $stmt->execute([$userId]);
            return $stmt->fetch();
        } catch (PDOException $e) {
            error_log("Get User Error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Update user profile
     */
    public function updateProfile($userId, $data) {
        try {
            $updates = [];
            $params = [];
            
            if (isset($data['first_name'])) {
                $updates[] = "first_name = ?";
                $params[] = $data['first_name'];
            }
            
            if (isset($data['last_name'])) {
                $updates[] = "last_name = ?";
                $params[] = $data['last_name'];
            }
            
            if (isset($data['phone'])) {
                $updates[] = "phone = ?";
                $params[] = $data['phone'];
            }
            
            if (isset($data['position'])) {
                $updates[] = "position = ?";
                $params[] = $data['position'];
            }
            
            if (isset($data['bio'])) {
                $updates[] = "bio = ?";
                $params[] = $data['bio'];
            }
            
            if (!empty($updates)) {
                $updates[] = "updated_at = NOW()";
                $params[] = $userId;
                
                $sql = "UPDATE users SET " . implode(', ', $updates) . " WHERE user_id = ?";
                $stmt = $this->db->prepare($sql);
                $stmt->execute($params);
                
                // Update session if current user
                $currentUser = $this->session->getUser();
                if ($currentUser && $currentUser['user_id'] == $userId) {
                    $updatedUser = array_merge($currentUser, $data);
                    $this->session->setUser($updatedUser);
                }
                
                return ['success' => true];
            }
            
            return ['success' => false, 'message' => 'No data to update'];
            
        } catch (PDOException $e) {
            error_log("Update Profile Error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Update failed'];
        }
    }
    
    /**
     * Change password
     */
    public function changePassword($userId, $currentPassword, $newPassword) {
        try {
            // Get current password hash
            $stmt = $this->db->prepare("SELECT password_hash FROM users WHERE user_id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch();
            
            if (!$user || !password_verify($currentPassword, $user['password_hash'])) {
                return ['success' => false, 'message' => 'Current password is incorrect'];
            }
            
            // Update password
            $newHash = password_hash($newPassword, PASSWORD_BCRYPT, [
                'cost' => DatabaseConfig::PASSWORD_COST
            ]);
            
            $stmt = $this->db->prepare("UPDATE users SET password_hash = ?, updated_at = NOW() WHERE user_id = ?");
            $stmt->execute([$newHash, $userId]);
            
            return ['success' => true];
            
        } catch (PDOException $e) {
            error_log("Change Password Error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Password change failed'];
        }
    }
    
    /**
     * Check permission
     */
    public function hasPermission($userId, $permission) {
        // This can be extended with a proper permission system
        $user = $this->getUserById($userId);
        
        if (!$user) return false;
        
        // Simple role-based permission check
        $rolePermissions = [
            'super_admin' => ['*'],
            'admin' => ['manage_users', 'manage_projects', 'manage_services', 'manage_blog', 'manage_jobs'],
            'team_leader' => ['manage_team', 'manage_tasks', 'view_reports'],
            'developer' => ['view_tasks', 'update_tasks'],
            'content_manager' => ['manage_blog', 'manage_content']
        ];
        
        $userRole = $user['role'];
        
        if (!isset($rolePermissions[$userRole])) {
            return false;
        }
        
        return in_array('*', $rolePermissions[$userRole]) || 
               in_array($permission, $rolePermissions[$userRole]);
    }
}
?>