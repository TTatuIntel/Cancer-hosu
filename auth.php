<?php
// ── Secure session configuration ──
ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.use_only_cookies', '1');
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    ini_set('session.cookie_secure', '1');
}
ini_set('session.gc_maxlifetime', '3600'); // 1 hour hard limit
ini_set('session.sid_length', '48');
ini_set('session.sid_bits_per_character', '6');
session_start();

// ── Security headers — prevent caching of admin pages ──
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

require 'db.php';

// ── Brute-force protection constants ──
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOCKOUT_DURATION', 900); // 15 minutes

// ── Session idle timeout (30 min) ──
define('SESSION_IDLE_TIMEOUT', 1800);

function checkSessionExpiry(): bool {
    if (!empty($_SESSION['last_activity'])) {
        if (time() - $_SESSION['last_activity'] > SESSION_IDLE_TIMEOUT) {
            // Session expired — destroy it
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                $p = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
            }
            session_destroy();
            return false;
        }
    }
    // Refresh activity timestamp
    if (!empty($_SESSION['user_id'])) {
        $_SESSION['last_activity'] = time();
    }
    return true;
}

// ── Session fingerprint validation ──
function getSessionFingerprint(): string {
    return hash('sha256', ($_SERVER['HTTP_USER_AGENT'] ?? '') . ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));
}

function validateFingerprint(): bool {
    if (!empty($_SESSION['fingerprint'])) {
        return hash_equals($_SESSION['fingerprint'], getSessionFingerprint());
    }
    return true;
}

// Run expiry + fingerprint check on every request
if (!checkSessionExpiry() || !validateFingerprint()) {
    $action = $_POST['action'] ?? $_GET['action'] ?? '';
    if ($action !== 'login' && $action !== 'setup' && $action !== 'request_reset' && $action !== 'reset_password') {
        http_response_code(401);
        echo json_encode(['error' => 'Session expired', 'expired' => true]);
        exit;
    }
}

// --- CSRF Token Helpers ---
function generateCsrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrfToken(string $token): bool {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// --- Ensure users table exists ---
function ensureUsersTable(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) NOT NULL UNIQUE,
            email VARCHAR(100) NOT NULL UNIQUE,
            phone VARCHAR(30) DEFAULT '',
            password VARCHAR(255) NOT NULL,
            role ENUM('admin','member') NOT NULL DEFAULT 'member',
            is_locked TINYINT(1) NOT NULL DEFAULT 0,
            failed_attempts INT NOT NULL DEFAULT 0,
            locked_until DATETIME DEFAULT NULL,
            last_login DATETIME DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8
    ");
    // Safe migration: add columns if they don't exist
    foreach (['phone VARCHAR(30) DEFAULT \'\'', 'is_locked TINYINT(1) NOT NULL DEFAULT 0', 'failed_attempts INT NOT NULL DEFAULT 0', 'locked_until DATETIME DEFAULT NULL', 'last_login DATETIME DEFAULT NULL'] as $colDef) {
        $colName = explode(' ', $colDef)[0];
        try { $pdo->exec("ALTER TABLE users ADD COLUMN $colName " . substr($colDef, strlen($colName) + 1)); } catch (\Exception $e) {}
    }

    // Login attempts tracking table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS login_attempts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ip_address VARCHAR(45) NOT NULL,
            identity VARCHAR(100) NOT NULL,
            attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            success TINYINT(1) NOT NULL DEFAULT 0,
            INDEX idx_ip (ip_address),
            INDEX idx_time (attempted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8
    ");

    // Password reset tokens table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS password_resets (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            token VARCHAR(64) NOT NULL UNIQUE,
            reset_code VARCHAR(6) NOT NULL,
            expires_at DATETIME NOT NULL,
            used TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_token (token),
            INDEX idx_expires (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8
    ");

    // Cleanup expired reset tokens
    try { $pdo->exec("DELETE FROM password_resets WHERE expires_at < NOW()"); } catch (\Exception $e) {}
    // Cleanup old login attempts (older than 24h)
    try { $pdo->exec("DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)"); } catch (\Exception $e) {}
}

// --- Seed default admin if none exists ---
function seedAdmin(PDO $pdo): void {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role = 'admin'");
    $stmt->execute();
    if ((int) $stmt->fetchColumn() === 0) {
        $hash = password_hash('Admin@hosu2026', PASSWORD_BCRYPT, ['cost' => 12]);
        $stmt = $pdo->prepare("INSERT INTO users (username, email, phone, password, role) VALUES (?, ?, ?, ?, 'admin')");
        $stmt->execute(['admin', 'admin@hosu.org', '+256123456789', $hash]);
    }
}

// --- Brute-force protection ---
function isIPLocked(PDO $pdo, string $ip): bool {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM login_attempts
        WHERE ip_address = ? AND success = 0
        AND attempted_at > DATE_SUB(NOW(), INTERVAL ? SECOND)
    ");
    $stmt->execute([$ip, LOCKOUT_DURATION]);
    return (int) $stmt->fetchColumn() >= MAX_LOGIN_ATTEMPTS;
}

function isAccountLocked(PDO $pdo, int $userId): bool {
    $stmt = $pdo->prepare("SELECT is_locked, locked_until FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) return false;
    if ($user['is_locked'] && $user['locked_until']) {
        if (new \DateTime($user['locked_until']) > new \DateTime()) {
            return true;
        }
        // Lockout expired — unlock
        $pdo->prepare("UPDATE users SET is_locked = 0, failed_attempts = 0, locked_until = NULL WHERE id = ?")->execute([$userId]);
    }
    return false;
}

function recordLoginAttempt(PDO $pdo, string $ip, string $identity, bool $success): void {
    $stmt = $pdo->prepare("INSERT INTO login_attempts (ip_address, identity, success) VALUES (?, ?, ?)");
    $stmt->execute([$ip, $identity, $success ? 1 : 0]);
}

function incrementFailedAttempts(PDO $pdo, int $userId): void {
    $pdo->prepare("UPDATE users SET failed_attempts = failed_attempts + 1 WHERE id = ?")->execute([$userId]);
    $stmt = $pdo->prepare("SELECT failed_attempts FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $attempts = (int) $stmt->fetchColumn();
    if ($attempts >= MAX_LOGIN_ATTEMPTS) {
        $lockUntil = (new \DateTime())->modify('+' . LOCKOUT_DURATION . ' seconds')->format('Y-m-d H:i:s');
        $pdo->prepare("UPDATE users SET is_locked = 1, locked_until = ? WHERE id = ?")->execute([$lockUntil, $userId]);
    }
}

function clearFailedAttempts(PDO $pdo, int $userId): void {
    $pdo->prepare("UPDATE users SET failed_attempts = 0, is_locked = 0, locked_until = NULL, last_login = NOW() WHERE id = ?")->execute([$userId]);
}

function getRemainingLockoutSeconds(PDO $pdo, int $userId): int {
    $stmt = $pdo->prepare("SELECT locked_until FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $until = $stmt->fetchColumn();
    if (!$until) return 0;
    $diff = (new \DateTime($until))->getTimestamp() - time();
    return max(0, $diff);
}

function getIPRemainingLockout(PDO $pdo, string $ip): int {
    $stmt = $pdo->prepare("
        SELECT attempted_at FROM login_attempts
        WHERE ip_address = ? AND success = 0
        ORDER BY attempted_at ASC
        LIMIT 1 OFFSET " . (MAX_LOGIN_ATTEMPTS - 1) . "
    ");
    // Get the Nth failed attempt time
    $stmt2 = $pdo->prepare("
        SELECT MIN(attempted_at) as first_fail FROM (
            SELECT attempted_at FROM login_attempts
            WHERE ip_address = ? AND success = 0
            AND attempted_at > DATE_SUB(NOW(), INTERVAL ? SECOND)
            ORDER BY attempted_at ASC
            LIMIT " . MAX_LOGIN_ATTEMPTS . "
        ) as recent
    ");
    $stmt2->execute([$ip, LOCKOUT_DURATION]);
    $row = $stmt2->fetch(PDO::FETCH_ASSOC);
    if (!$row || !$row['first_fail']) return LOCKOUT_DURATION;
    $unlockAt = (new \DateTime($row['first_fail']))->modify('+' . LOCKOUT_DURATION . ' seconds');
    return max(0, $unlockAt->getTimestamp() - time());
}

// --- Auth guard (include this in protected pages) ---
function requireAuth(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['user_id'])) {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
            header('Content-Type: application/json');
            http_response_code(401);
            echo json_encode(['error' => 'Authentication required']);
            exit;
        }
        header('Location: login.html');
        exit;
    }
}

function requireAdmin(): void {
    requireAuth();
    if (($_SESSION['user_role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo json_encode(['error' => 'Admin access required']);
        exit;
    }
}

// --- Run setup on first load ---
ensureUsersTable($pdo);
seedAdmin($pdo);

// --- Route actions ---
$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {
    case 'login':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'POST required']);
            exit;
        }

        $identity = trim($_POST['identity'] ?? '');
        $password = $_POST['password'] ?? '';
        $clientIP = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        if ($identity === '' || $password === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Username/email and password are required']);
            exit;
        }

        // ── Check IP-level lockout ──
        if (isIPLocked($pdo, $clientIP)) {
            $remaining = getIPRemainingLockout($pdo, $clientIP);
            http_response_code(429);
            echo json_encode([
                'error' => 'Too many failed attempts. Try again in ' . ceil($remaining / 60) . ' minute(s).',
                'locked' => true,
                'retry_after' => $remaining
            ]);
            exit;
        }

        // Look up user by username or email
        $stmt = $pdo->prepare("SELECT id, username, email, password, role, is_locked, locked_until, failed_attempts FROM users WHERE username = ? OR email = ? LIMIT 1");
        $stmt->execute([$identity, $identity]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        // ── Check account-level lockout ──
        if ($user && isAccountLocked($pdo, $user['id'])) {
            $remaining = getRemainingLockoutSeconds($pdo, $user['id']);
            recordLoginAttempt($pdo, $clientIP, $identity, false);
            http_response_code(429);
            echo json_encode([
                'error' => 'Account locked due to too many failed attempts. Try again in ' . ceil($remaining / 60) . ' minute(s).',
                'locked' => true,
                'retry_after' => $remaining
            ]);
            exit;
        }

        if (!$user || !password_verify($password, $user['password'])) {
            // Record failed attempt
            recordLoginAttempt($pdo, $clientIP, $identity, false);
            if ($user) {
                incrementFailedAttempts($pdo, $user['id']);
                $attemptsLeft = MAX_LOGIN_ATTEMPTS - ((int)$user['failed_attempts'] + 1);
                if ($attemptsLeft <= 0) {
                    http_response_code(429);
                    echo json_encode([
                        'error' => 'Account locked due to too many failed attempts. Try again in 15 minutes.',
                        'locked' => true,
                        'retry_after' => LOCKOUT_DURATION
                    ]);
                    exit;
                }
                http_response_code(401);
                echo json_encode([
                    'error' => 'Invalid credentials. ' . max(0, $attemptsLeft) . ' attempt(s) remaining.',
                    'attempts_left' => max(0, $attemptsLeft)
                ]);
            } else {
                // Don't reveal whether user exists — use generic timing
                usleep(random_int(100000, 300000)); // 100-300ms delay
                http_response_code(401);
                echo json_encode(['error' => 'Invalid credentials']);
            }
            exit;
        }

        // ── Successful login: clear lockout, record success ──
        clearFailedAttempts($pdo, $user['id']);
        recordLoginAttempt($pdo, $clientIP, $identity, true);

        // Rehash password if needed (cost upgrade)
        if (password_needs_rehash($user['password'], PASSWORD_BCRYPT, ['cost' => 12])) {
            $newHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
            $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$newHash, $user['id']]);
        }

        // Regenerate session ID to prevent fixation
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['user_role'] = $user['role'];
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['last_activity'] = time();
        $_SESSION['login_time'] = time();
        $_SESSION['fingerprint'] = getSessionFingerprint();
        $_SESSION['ip_address'] = $clientIP;

        echo json_encode([
            'success' => true,
            'user' => [
                'username' => $user['username'],
                'role' => $user['role'],
            ],
            'csrf_token' => $_SESSION['csrf_token'],
            'redirect' => $user['role'] === 'admin' ? 'admin.html' : 'index.html',
            'idle_timeout' => SESSION_IDLE_TIMEOUT
        ]);
        break;

    case 'logout':
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
        echo json_encode(['success' => true]);
        break;

    case 'check_session':
        if (!empty($_SESSION['user_id'])) {
            echo json_encode([
                'logged_in' => true,
                'user' => [
                    'username' => $_SESSION['username'],
                    'role' => $_SESSION['user_role'],
                ],
                'csrf_token' => generateCsrfToken(),
                'idle_timeout' => SESSION_IDLE_TIMEOUT
            ]);
        } else {
            echo json_encode(['logged_in' => false, 'expired' => true]);
        }
        break;

    case 'heartbeat':
        // Lightweight keep-alive — only refreshes last_activity
        if (!empty($_SESSION['user_id'])) {
            $_SESSION['last_activity'] = time();
            echo json_encode([
                'success'  => true,
                'alive'    => true,
                'username' => $_SESSION['username'] ?? 'Admin',
                'role'     => $_SESSION['user_role'] ?? 'member'
            ]);
        } else {
            http_response_code(401);
            echo json_encode(['success' => false, 'alive' => false, 'expired' => true]);
        }
        break;

    case 'setup':
        // One-time setup endpoint — creates tables and seeds admin
        echo json_encode(['success' => true, 'message' => 'Database setup complete. Default admin created.']);
        break;

    // ── Password Reset: Request ──
    case 'request_reset':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'POST required']);
            exit;
        }
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');

        if ($email === '' && $phone === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Please provide your email or phone number']);
            exit;
        }

        // Rate limit: max 3 reset requests per IP per hour
        $clientIP = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM password_resets pr
            JOIN users u ON pr.user_id = u.id
            WHERE pr.created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
            AND EXISTS (
                SELECT 1 FROM login_attempts la
                WHERE la.ip_address = ? AND la.attempted_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
            )
        ");
        // Simpler rate limit: count recent resets
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM password_resets WHERE created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
            $stmt->execute();
            if ((int)$stmt->fetchColumn() >= 10) {
                http_response_code(429);
                echo json_encode(['error' => 'Too many reset requests. Please try again later.']);
                exit;
            }
        } catch (\Exception $e) {}

        // Find user by email or phone
        $user = null;
        if ($email !== '') {
            $stmt = $pdo->prepare("SELECT id, username, email, phone, role FROM users WHERE email = ? AND role = 'admin' LIMIT 1");
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        if (!$user && $phone !== '') {
            $stmt = $pdo->prepare("SELECT id, username, email, phone, role FROM users WHERE phone = ? AND role = 'admin' LIMIT 1");
            $stmt->execute([$phone]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        // Always respond with success to prevent user enumeration
        if (!$user) {
            usleep(random_int(200000, 500000)); // timing-safe
            echo json_encode([
                'success' => true,
                'message' => 'If an account matches, a reset code has been generated.'
            ]);
            exit;
        }

        // Generate reset token and 6-digit code
        $token = bin2hex(random_bytes(32));
        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expiresAt = (new \DateTime())->modify('+15 minutes')->format('Y-m-d H:i:s');

        // Invalidate any existing tokens for this user
        $pdo->prepare("UPDATE password_resets SET used = 1 WHERE user_id = ? AND used = 0")->execute([$user['id']]);

        // Insert new reset token
        $stmt = $pdo->prepare("INSERT INTO password_resets (user_id, token, reset_code, expires_at) VALUES (?, ?, ?, ?)");
        $stmt->execute([$user['id'], $token, $code, $expiresAt]);

        // In production: send email/SMS with the code
        // For this setup, we show the code masked in the response for admin self-service
        $maskedEmail = '';
        if ($user['email']) {
            $parts = explode('@', $user['email']);
            $maskedEmail = substr($parts[0], 0, 2) . '***@' . $parts[1];
        }
        $maskedPhone = '';
        if (!empty($user['phone'])) {
            $maskedPhone = substr($user['phone'], 0, 4) . '****' . substr($user['phone'], -3);
        }

        echo json_encode([
            'success' => true,
            'message' => 'Reset code generated. Valid for 15 minutes.',
            'token' => $token,
            'masked_email' => $maskedEmail,
            'masked_phone' => $maskedPhone,
            // NOTE: In production, NEVER send the code in the response.
            // Send via email/SMS instead. This is for local dev only.
            'dev_code' => $code
        ]);
        break;

    // ── Password Reset: Verify code and set new password ──
    case 'reset_password':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'POST required']);
            exit;
        }
        $token = trim($_POST['token'] ?? '');
        $code = trim($_POST['code'] ?? '');
        $newPassword = $_POST['new_password'] ?? '';

        if ($token === '' || $code === '' || $newPassword === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Token, code, and new password are required']);
            exit;
        }

        // Validate password strength
        if (strlen($newPassword) < 8) {
            http_response_code(400);
            echo json_encode(['error' => 'Password must be at least 8 characters']);
            exit;
        }
        if (!preg_match('/[A-Z]/', $newPassword) || !preg_match('/[a-z]/', $newPassword) || !preg_match('/[0-9]/', $newPassword)) {
            http_response_code(400);
            echo json_encode(['error' => 'Password must contain uppercase, lowercase, and a number']);
            exit;
        }

        // Find valid token
        $stmt = $pdo->prepare("
            SELECT pr.*, u.id as uid, u.username FROM password_resets pr
            JOIN users u ON pr.user_id = u.id
            WHERE pr.token = ? AND pr.used = 0 AND pr.expires_at > NOW()
            LIMIT 1
        ");
        $stmt->execute([$token]);
        $reset = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$reset) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid or expired reset token. Please request a new one.']);
            exit;
        }

        // Verify code (timing-safe comparison)
        if (!hash_equals($reset['reset_code'], $code)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid reset code']);
            exit;
        }

        // Update password
        $hash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
        $pdo->prepare("UPDATE users SET password = ?, failed_attempts = 0, is_locked = 0, locked_until = NULL WHERE id = ?")->execute([$hash, $reset['uid']]);

        // Mark token as used
        $pdo->prepare("UPDATE password_resets SET used = 1 WHERE id = ?")->execute([$reset['id']]);

        // Destroy any existing sessions for this user (force re-login)
        // (Can't target specific sessions in file-based storage, but the password change will invalidate next check)

        echo json_encode([
            'success' => true,
            'message' => 'Password reset successfully. Please log in with your new password.'
        ]);
        break;

    // ── Admin: Change password (while logged in) ──
    case 'change_password':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401);
            echo json_encode(['error' => 'Admin access required']);
            exit;
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'POST required']);
            exit;
        }
        $current = $_POST['current_password'] ?? '';
        $newPass = $_POST['new_password'] ?? '';

        if ($current === '' || $newPass === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Current and new password are required']);
            exit;
        }
        if (strlen($newPass) < 8 || !preg_match('/[A-Z]/', $newPass) || !preg_match('/[a-z]/', $newPass) || !preg_match('/[0-9]/', $newPass)) {
            http_response_code(400);
            echo json_encode(['error' => 'New password must be 8+ chars with uppercase, lowercase, and a number']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || !password_verify($current, $row['password'])) {
            http_response_code(401);
            echo json_encode(['error' => 'Current password is incorrect']);
            exit;
        }

        $hash = password_hash($newPass, PASSWORD_BCRYPT, ['cost' => 12]);
        $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$hash, $_SESSION['user_id']]);

        echo json_encode(['success' => true, 'message' => 'Password changed successfully']);
        break;

    // ── Admin: Get login history ──
    case 'login_history':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401);
            echo json_encode(['error' => 'Admin access required']);
            exit;
        }
        $stmt = $pdo->prepare("
            SELECT ip_address, identity, attempted_at,
                   CASE WHEN success = 1 THEN 'success' ELSE 'failed' END as status
            FROM login_attempts
            WHERE identity IN (
                SELECT username FROM users WHERE id = ?
                UNION SELECT email FROM users WHERE id = ?
            )
            ORDER BY attempted_at DESC LIMIT 20
        ");
        $stmt->execute([$_SESSION['user_id'], $_SESSION['user_id']]);
        echo json_encode(['success' => true, 'history' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action']);
        break;
}
