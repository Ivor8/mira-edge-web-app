<?php
/**
 * Session Management
 */

class Session {
    
    public function __construct() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        // Regenerate session ID periodically for security
        if (!isset($_SESSION['last_regeneration'])) {
            $_SESSION['last_regeneration'] = time();
        } elseif (time() - $_SESSION['last_regeneration'] > 1800) { // 30 minutes
            session_regenerate_id(true);
            $_SESSION['last_regeneration'] = time();
        }
    }
    
    public function set($key, $value) {
        $_SESSION[$key] = $value;
    }
    
    public function get($key, $default = null) {
        return $_SESSION[$key] ?? $default;
    }
    
    public function has($key) {
        return isset($_SESSION[$key]);
    }
    
    public function remove($key) {
        if (isset($_SESSION[$key])) {
            unset($_SESSION[$key]);
        }
    }
    
    public function destroy() {
        session_destroy();
        $_SESSION = [];
    }
    
    public function setFlash($type, $message) {
        $_SESSION['flash'][$type] = $message;
    }
    
    public function getFlash($type) {
        if (isset($_SESSION['flash'][$type])) {
            $message = $_SESSION['flash'][$type];
            unset($_SESSION['flash'][$type]);
            return $message;
        }
        return null;
    }
    
    public function hasFlash($type = null) {
        if ($type) {
            return isset($_SESSION['flash'][$type]);
        }
        return !empty($_SESSION['flash']);
    }
    
    public function setUser($user) {
        $_SESSION['user'] = $user;
        $_SESSION['user']['last_activity'] = time();
    }
    
    public function getUser() {
        if (isset($_SESSION['user'])) {
            // Check session timeout
            if (time() - $_SESSION['user']['last_activity'] > DatabaseConfig::SESSION_TIMEOUT) {
                $this->destroy();
                return null;
            }
            
            // Update last activity
            $_SESSION['user']['last_activity'] = time();
            return $_SESSION['user'];
        }
        return null;
    }
    
    public function isLoggedIn() {
        return $this->getUser() !== null;
    }
    
    public function getUserRole() {
        $user = $this->getUser();
        return $user['role'] ?? null;
    }
    
    public function isAdmin() {
        $role = $this->getUserRole();
        return in_array($role, ['admin', 'super_admin', 'team_leader']);
    }
    
    public function isSuperAdmin() {
        return $this->getUserRole() === 'super_admin';
    }
    
    public function isDeveloper() {
        $role = $this->getUserRole();
        return in_array($role, ['developer', 'team_leader']);
    }
}
?>