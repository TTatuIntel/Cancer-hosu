<?php
// Secure session config (matches auth.php)
ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.use_only_cookies', '1');
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    ini_set('session.cookie_secure', '1');
}
session_start();
header("Content-Type: application/json");
header("X-Content-Type-Options: nosniff");
header("Access-Control-Allow-Origin: " . ($_SERVER['HTTP_ORIGIN'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost'));
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Credentials: true");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require 'db.php';

// Server-side idle timeout check for API calls
define('API_SESSION_IDLE_TIMEOUT', 900);
if (!empty($_SESSION['user_id']) && !empty($_SESSION['last_activity'])) {
    if (time() - $_SESSION['last_activity'] > API_SESSION_IDLE_TIMEOUT) {
        $_SESSION = [];
        session_destroy();
        http_response_code(401);
        echo json_encode(['error' => 'Session expired', 'expired' => true]);
        exit;
    }
    $_SESSION['last_activity'] = time();
}

// Get action from either GET or POST
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// ── Email receipt helper ──────────────────────────────────────────────
function sendReceiptEmail($pdo, $paymentId, $receiptToken) {
    try {
        $stmt = $pdo->prepare("
            SELECT p.*, m.full_name, m.email, m.phone
            FROM payments p
            JOIN members m ON m.id = p.member_id
            WHERE p.id = ? AND p.receipt_token = ?
        ");
        $stmt->execute([$paymentId, $receiptToken]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || empty($row['email']) || !filter_var($row['email'], FILTER_VALIDATE_EMAIL)) return false;

        $name    = htmlspecialchars($row['full_name'], ENT_QUOTES, 'UTF-8');
        $email   = $row['email'];
        $amount  = number_format((float)$row['amount'], 0, '.', ',');
        $receipt = htmlspecialchars($row['receipt_number'] ?? '', ENT_QUOTES, 'UTF-8');
        $method  = htmlspecialchars($row['payment_method'] ?? '', ENT_QUOTES, 'UTF-8');
        $paidAt  = $row['paid_at'] ?? date('Y-m-d H:i:s');
        $type    = $row['payment_type'] ?? 'payment';

        $typeLabels = [
            'membership'         => 'Membership Payment',
            'event_registration' => 'Event Registration',
            'donation'           => 'Donation',
        ];
        $typeLabel = $typeLabels[$type] ?? 'Payment';

        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $receiptUrl = $protocol . '://' . $host . dirname($_SERVER['SCRIPT_NAME']) . '/receipt.php?token=' . urlencode($receiptToken);

        $subject = "HOSU Receipt — $receipt";
        $htmlBody = <<<HTML
<!DOCTYPE html>
<html><head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;font-family:Arial,Helvetica,sans-serif;background:#f4f6f9;">
<table width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;margin:24px auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,0.08);">
  <tr><td style="background:#0d4593;padding:24px 28px;">
    <h1 style="margin:0;color:#fff;font-size:20px;">HOSU — $typeLabel Receipt</h1>
  </td></tr>
  <tr><td style="padding:28px;">
    <p style="margin:0 0 12px;color:#333;">Dear <strong>$name</strong>,</p>
    <p style="margin:0 0 20px;color:#555;">Thank you for your $typeLabel. Here is your payment receipt.</p>
    <table width="100%" cellpadding="8" cellspacing="0" style="border:1px solid #e2e8f0;border-radius:8px;font-size:14px;color:#333;">
      <tr style="background:#f8fafc;"><td><strong>Receipt #</strong></td><td>$receipt</td></tr>
      <tr><td><strong>Amount</strong></td><td>UGX $amount</td></tr>
      <tr style="background:#f8fafc;"><td><strong>Method</strong></td><td>$method</td></tr>
      <tr><td><strong>Date</strong></td><td>$paidAt</td></tr>
      <tr style="background:#f8fafc;"><td><strong>Status</strong></td><td style="color:#27ae60;font-weight:700;">Verified</td></tr>
    </table>
    <p style="margin:24px 0 0;text-align:center;">
      <a href="$receiptUrl" style="display:inline-block;background:#e63946;color:#fff;padding:12px 28px;border-radius:8px;text-decoration:none;font-weight:700;">View Full Receipt</a>
    </p>
  </td></tr>
  <tr><td style="background:#f8fafc;padding:16px 28px;font-size:12px;color:#888;text-align:center;">
    Haematology &amp; Oncology Society of Uganda (HOSU)<br>
    This is an auto-generated receipt. Please do not reply to this email.
  </td></tr>
</table>
</body></html>
HTML;

        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= "From: HOSU <noreply@" . preg_replace('/^www\./i', '', $host) . ">\r\n";

        return mail($email, $subject, $htmlBody, $headers);
    } catch (Exception $e) {
        return false;
    }
}

switch ($action) {
    case 'check_login':
        if (!empty($_SESSION['user_id'])) {
            echo json_encode([
                'logged_in' => true,
                'user' => [
                    'username' => $_SESSION['username'] ?? '',
                    'role' => $_SESSION['user_role'] ?? 'member',
                ]
            ]);
        } else {
            echo json_encode(['logged_in' => false]);
        }
        break;
        
    case 'get_posts':
    try {
        // Modified query to format the date
        $stmt = $pdo->query("
            SELECT 
                p.*, 
                DATE_FORMAT(p.created_at, '%M %e, %Y') as formatted_date,
                COUNT(c.id) as comment_count 
            FROM posts p 
            LEFT JOIN comments c ON p.id = c.post_id 
            GROUP BY p.id 
            ORDER BY p.created_at DESC
        ");
        $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get 2 most recent comments for each post
        foreach ($posts as &$post) {
            $stmt = $pdo->prepare("
                SELECT 
                    *,
                    DATE_FORMAT(created_at, '%M %e, %Y') as formatted_date
                FROM comments 
                WHERE post_id = ? 
                ORDER BY created_at DESC 
                LIMIT 2
            ");
            $stmt->execute([$post['id']]);
            $post['comments'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        echo json_encode($posts);
    } catch (PDOException $e) {
        echo json_encode(['error' => 'Failed to fetch posts: ' . $e->getMessage()]);
    }
    break;
        
    case 'get_comments':
    $postId = $_GET['post_id'] ?? 0;
    try {
        $stmt = $pdo->prepare("
            SELECT 
                *,
                DATE_FORMAT(created_at, '%M %e, %Y') as formatted_date
            FROM comments 
            WHERE post_id = ? 
            ORDER BY created_at DESC
        ");
        $stmt->execute([$postId]);
        $comments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($comments);
    } catch (PDOException $e) {
        echo json_encode(['error' => 'Failed to fetch comments: ' . $e->getMessage()]);
    }
    break;
        
case 'create_post':
    // Require admin authentication
    if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
        http_response_code(401);
        echo json_encode(['error' => 'Authentication required']);
        break;
    }
    try {
        // Handle post image upload
        $imagePath = 'uploads/default-blog.jpg';
        if (isset($_FILES['image'])) {
            $uploadDir = 'uploads/posts/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            $imageName = uniqid() . '_' . basename($_FILES['image']['name']);
            $targetPath = $uploadDir . $imageName;

            if (move_uploaded_file($_FILES['image']['tmp_name'], $targetPath)) {
                $imagePath = $targetPath;
            }
        }

        // Handle avatar upload
        $avatarPath = 'uploads/default-avatar.jpg';
        if (isset($_FILES['avatar'])) {
            $uploadDir = 'uploads/avatars/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            $avatarName = uniqid() . '_' . basename($_FILES['avatar']['name']);
            $targetPath = $uploadDir . $avatarName;

            if (move_uploaded_file($_FILES['avatar']['tmp_name'], $targetPath)) {
                $avatarPath = $targetPath;
            }
        }

        // Basic validation
        if (empty($_POST['title'])) {
            throw new Exception('Title is required');
        }
        if (empty($_POST['content'])) {
            throw new Exception('Content is required');
        }

        // Insert into posts table
        $stmt = $pdo->prepare("
            INSERT INTO posts (title, content, category, author, image, avatar, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");

        $result = $stmt->execute([
            $_POST['title'],
            $_POST['content'],
            $_POST['category'] ?? 'General',
            $_POST['author'] ?? 'Anonymous',
            $imagePath,
            $avatarPath
        ]);

        if ($result) {
            echo json_encode([
                'success' => true,
                'post_id' => $pdo->lastInsertId()
            ]);
        } else {
            throw new Exception('Failed to insert post');
        }

    } catch (Exception | PDOException $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    break;

    case 'delete_post':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401);
            echo json_encode(['error' => 'Admin access required']);
            break;
        }
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) { http_response_code(400); echo json_encode(['error' => 'ID required']); break; }
        try {
            $stmt = $pdo->prepare("SELECT image, avatar FROM posts WHERE id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                foreach (['image', 'avatar'] as $col) {
                    if (!empty($row[$col]) && strpos($row[$col], 'uploads/') === 0
                        && strpos($row[$col], 'default') === false && file_exists($row[$col])) {
                        @unlink($row[$col]);
                    }
                }
            }
            $pdo->prepare("DELETE FROM posts WHERE id = ?")->execute([$id]);
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    case 'update_post':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401);
            echo json_encode(['error' => 'Admin access required']);
            break;
        }
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) { http_response_code(400); echo json_encode(['error' => 'Post ID required']); break; }
        try {
            if (empty($_POST['title']) || empty($_POST['content'])) {
                throw new Exception('Title and content are required');
            }
            $sets = ['title = ?', 'content = ?', 'category = ?', 'author = ?'];
            $params = [$_POST['title'], $_POST['content'], $_POST['category'] ?? 'General', $_POST['author'] ?? 'Anonymous'];

            if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                $uploadDir = 'uploads/posts/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                $imageName = uniqid() . '_' . basename($_FILES['image']['name']);
                $targetPath = $uploadDir . $imageName;
                if (move_uploaded_file($_FILES['image']['tmp_name'], $targetPath)) {
                    $sets[] = 'image = ?';
                    $params[] = $targetPath;
                }
            }
            if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
                $uploadDir = 'uploads/avatars/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                $avatarName = uniqid() . '_' . basename($_FILES['avatar']['name']);
                $targetPath = $uploadDir . $avatarName;
                if (move_uploaded_file($_FILES['avatar']['tmp_name'], $targetPath)) {
                    $sets[] = 'avatar = ?';
                    $params[] = $targetPath;
                }
            }
            $params[] = $id;
            $pdo->prepare("UPDATE posts SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);
            echo json_encode(['success' => true]);
        } catch (Exception | PDOException $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    case 'get_events':
        try {
            // Ensure date_start / date_end / is_free / event_fee columns exist (safe migration)
            foreach (['date_start DATE NULL', 'date_end DATE NULL', 'is_free TINYINT(1) NOT NULL DEFAULT 1', 'event_fee DECIMAL(12,2) NOT NULL DEFAULT 0'] as $colDef) {
                $colName = explode(' ', $colDef)[0];
                try { $pdo->exec("ALTER TABLE events ADD COLUMN $colName " . substr($colDef, strlen($colName) + 1)); } catch (Exception $_e) {}
            }

            // ── Auto-expire: move past events to status='past', category='past' ──
            $pdo->exec("
                UPDATE events
                SET status   = 'past',
                    category = 'past'
                WHERE status != 'past'
                  AND (
                      (date_end   IS NOT NULL AND date_end   < CURDATE())
                   OR (date_end   IS NULL     AND date_start IS NOT NULL AND date_start < CURDATE())
                  )
            ");

            $stmt = $pdo->query("SELECT * FROM events ORDER BY created_at DESC");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $eventsData = [
                'featured' => [], 'current' => [], 'upcoming' => [],
                'conferences' => [], 'workshops' => [], 'webinars' => [], 'past' => []
            ];
            $today = new DateTimeImmutable('today');
            foreach ($rows as $ev) {
                $cat = $ev['category'] ?? 'upcoming';
                unset($ev['category']);
                $ev['featured'] = (bool)$ev['featured'];
                $ev['is_free'] = (bool)($ev['is_free'] ?? 1);
                $ev['event_fee'] = (float)($ev['event_fee'] ?? 0);
                // Normalize image path for browser use
                $ev['image'] = str_replace('\\', '/', $ev['image']);

                // ── Auto-compute countdown from date_start / date_end ──
                if (!empty($ev['date_start'])) {
                    $start = new DateTimeImmutable($ev['date_start']);
                    $end   = !empty($ev['date_end']) ? new DateTimeImmutable($ev['date_end']) : $start;

                    if ($today > $end) {
                        $ev['countdown'] = 'Event Ended';
                    } elseif ($today >= $start && $today <= $end) {
                        $ev['countdown'] = 'Happening Now';
                    } else {
                        $diff = $today->diff($start);
                        if ($diff->y > 0) {
                            $ev['countdown'] = 'In ' . $diff->y . ' year' . ($diff->y > 1 ? 's' : '') . ($diff->m > 0 ? ', ' . $diff->m . ' month' . ($diff->m > 1 ? 's' : '') : '');
                        } elseif ($diff->m > 0) {
                            $ev['countdown'] = 'In ' . $diff->m . ' month' . ($diff->m > 1 ? 's' : '') . ($diff->d > 0 ? ', ' . $diff->d . ' day' . ($diff->d > 1 ? 's' : '') : '');
                        } elseif ($diff->d > 1) {
                            $ev['countdown'] = 'In ' . $diff->d . ' days';
                        } elseif ($diff->d === 1) {
                            $ev['countdown'] = 'Tomorrow';
                        } else {
                            $ev['countdown'] = 'Today';
                        }
                    }
                }

                if (array_key_exists($cat, $eventsData)) {
                    $eventsData[$cat][] = $ev;
                } else {
                    $eventsData['upcoming'][] = $ev;
                }
            }
            echo json_encode(['success' => true, 'eventsData' => $eventsData]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to fetch events: ' . $e->getMessage()]);
        }
        break;

    case 'delete_event':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401);
            echo json_encode(['error' => 'Admin access required']);
            break;
        }
        $id = $_POST['id'] ?? '';
        if (!$id) { http_response_code(400); echo json_encode(['error' => 'ID required']); break; }
        try {
            // Remove image file if it exists in uploads
            $stmt = $pdo->prepare("SELECT image FROM events WHERE id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && !empty($row['image']) && strpos($row['image'], 'uploads/') === 0 && file_exists($row['image'])) {
                @unlink($row['image']);
            }
            $stmt = $pdo->prepare("DELETE FROM events WHERE id = ?");
            $stmt->execute([$id]);
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    case 'list_events_admin':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            // Ensure date_start / date_end / is_free / event_fee columns exist (safe migration)
            foreach (['date_start DATE NULL', 'date_end DATE NULL', 'is_free TINYINT(1) NOT NULL DEFAULT 1', 'event_fee DECIMAL(12,2) NOT NULL DEFAULT 0'] as $colDef) {
                $colName = explode(' ', $colDef)[0];
                try { $pdo->exec("ALTER TABLE events ADD COLUMN $colName " . substr($colDef, strlen($colName) + 1)); } catch (Exception $_e) {}
            }

            // Auto-expire past events
            $pdo->exec("
                UPDATE events
                SET status   = 'past',
                    category = 'past'
                WHERE status != 'past'
                  AND (
                      (date_end   IS NOT NULL AND date_end   < CURDATE())
                   OR (date_end   IS NULL     AND date_start IS NOT NULL AND date_start < CURDATE())
                  )
            ");
            $stmt = $pdo->query("SELECT id, title, type, status, category, date, date_start, date_end, location, image, imageAlt, description, countdown, featured, is_free, event_fee, created_at FROM events ORDER BY created_at DESC");
            echo json_encode(['success' => true, 'events' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        } catch (PDOException $e) {
            http_response_code(500); echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    case 'get_event_registrants':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            $eventId = trim($_GET['event_id'] ?? $_POST['event_id'] ?? '');
            if (!$eventId) { http_response_code(400); echo json_encode(['error' => 'event_id required']); break; }
            $stmt = $pdo->prepare("
                SELECT id, event_id, full_name, email, phone, profession, institution,
                       amount, currency, payment_method, transaction_ref,
                       status, payment_status,
                       receipt_number, receipt_token,
                       DATE_FORMAT(registered_at,'%d %b %Y') as registered_date
                FROM event_registrants
                WHERE event_id = ?
                ORDER BY registered_at DESC
            ");
            $stmt->execute([$eventId]);
            $registrants = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Summary stats
            $countStmt = $pdo->prepare("
                SELECT COUNT(*) as total,
                       SUM(CASE WHEN payment_status='verified' THEN 1 ELSE 0 END) as verified,
                       SUM(amount) as revenue
                FROM event_registrants WHERE event_id=?
            ");
            $countStmt->execute([$eventId]);
            $stats = $countStmt->fetch(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'registrants' => $registrants,
                'stats' => [
                    'total' => (int)($stats['total'] ?? 0),
                    'verified' => (int)($stats['verified'] ?? 0),
                    'revenue' => (float)($stats['revenue'] ?? 0)
                ]
            ]);
        } catch (PDOException $e) {
            http_response_code(500); echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    case 'get_event_reg_counts':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            $stmt = $pdo->query("
                SELECT event_id, COUNT(*) as reg_count
                FROM event_registrants
                WHERE event_id != ''
                GROUP BY event_id
            ");
            $counts = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $counts[$row['event_id']] = (int)$row['reg_count'];
            }
            echo json_encode(['success' => true, 'counts' => $counts]);
        } catch (PDOException $e) {
            http_response_code(500); echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    case 'verify_registrant':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            $allowed = ['pending','verified','rejected'];
            $status = $_POST['status'] ?? '';
            $id = (int)($_POST['registrant_id'] ?? 0);
            if (!in_array($status, $allowed) || !$id) {
                http_response_code(400); echo json_encode(['error' => 'Invalid input']); break;
            }
            $pdo->prepare("UPDATE event_registrants SET payment_status=? WHERE id=?")->execute([$status, $id]);
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            http_response_code(500); echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    case 'delete_registrant':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            $id = (int)($_POST['registrant_id'] ?? 0);
            if (!$id) { http_response_code(400); echo json_encode(['error' => 'Invalid input']); break; }
            $pdo->prepare("DELETE FROM event_registrants WHERE id=?")->execute([$id]);
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            http_response_code(500); echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    case 'add_comment':
        try {
            $postId = $_POST['post_id'] ?? 0;
            $author = $_POST['author'] ?? '';
            $content = $_POST['content'] ?? '';
            
            // Insert comment
            $stmt = $pdo->prepare("
                INSERT INTO comments (post_id, author, content, created_at) 
                VALUES (?, ?, ?, NOW())
            ");
            $stmt->execute([$postId, $author, $content]);
            
            // Update comment count in posts table
            $stmt = $pdo->prepare("
                UPDATE posts 
                SET comment_count = (SELECT COUNT(*) FROM comments WHERE post_id = ?) 
                WHERE id = ?
            ");
            $stmt->execute([$postId, $postId]);
            
            echo json_encode([
                'success' => true,
                'comment_id' => $pdo->lastInsertId()
            ]);
        } catch (PDOException $e) {
            echo json_encode(['error' => 'Failed to add comment: ' . $e->getMessage()]);
        }
        break;

    case 'delete_comment':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401);
            echo json_encode(['error' => 'Admin access required']);
            break;
        }
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) { http_response_code(400); echo json_encode(['error' => 'Comment ID required']); break; }
        try {
            // Get post_id before deleting so we can update the count
            $stmt = $pdo->prepare("SELECT post_id FROM comments WHERE id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                http_response_code(404);
                echo json_encode(['error' => 'Comment not found']);
                break;
            }
            $postId = $row['post_id'];
            $pdo->prepare("DELETE FROM comments WHERE id = ?")->execute([$id]);
            // Update comment count
            $pdo->prepare("UPDATE posts SET comment_count = (SELECT COUNT(*) FROM comments WHERE post_id = ?) WHERE id = ?")
                ->execute([$postId, $postId]);
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    case 'get_all_comments':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401);
            echo json_encode(['error' => 'Admin access required']);
            break;
        }
        try {
            $stmt = $pdo->query("
                SELECT c.*, p.title as post_title,
                    DATE_FORMAT(c.created_at, '%M %e, %Y') as formatted_date
                FROM comments c
                LEFT JOIN posts p ON c.post_id = p.id
                ORDER BY c.created_at DESC
            ");
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    // ── Members ──────────────────────────────────────────────────────────
    case 'register_member':
        try {
            // Ensure base tables exist
            $pdo->exec("CREATE TABLE IF NOT EXISTS members (
                id INT AUTO_INCREMENT PRIMARY KEY,
                full_name VARCHAR(150) NOT NULL,
                email VARCHAR(150) NOT NULL,
                phone VARCHAR(30) DEFAULT '',
                profession VARCHAR(100) DEFAULT '',
                institution VARCHAR(200) DEFAULT '',
                membership_type VARCHAR(50) NOT NULL DEFAULT 'annual',
                status ENUM('pending','active','expired','rejected') NOT NULL DEFAULT 'pending',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
            $pdo->exec("CREATE TABLE IF NOT EXISTS payments (
                id INT AUTO_INCREMENT PRIMARY KEY,
                member_id INT NOT NULL,
                amount DECIMAL(12,2) NOT NULL DEFAULT 0,
                currency VARCHAR(10) NOT NULL DEFAULT 'UGX',
                payment_method VARCHAR(50) DEFAULT 'unknown',
                transaction_ref VARCHAR(100) DEFAULT '',
                transaction_id VARCHAR(100) DEFAULT '',
                proof_file VARCHAR(255) DEFAULT '',
                status ENUM('pending','verified','rejected') NOT NULL DEFAULT 'pending',
                invoice_sent TINYINT(1) NOT NULL DEFAULT 0,
                receipt_number VARCHAR(30) DEFAULT '',
                receipt_token VARCHAR(64) DEFAULT '',
                qr_scanned TINYINT(1) NOT NULL DEFAULT 0,
                scanned_at TIMESTAMP NULL DEFAULT NULL,
                notes TEXT DEFAULT '',
                payment_type ENUM('membership','event_registration','donation') NOT NULL DEFAULT 'membership',
                membership_period VARCHAR(20) DEFAULT '1_year',
                membership_expires_at DATE NULL DEFAULT NULL,
                event_id VARCHAR(100) DEFAULT '',
                event_title VARCHAR(255) DEFAULT '',
                event_date VARCHAR(100) DEFAULT '',
                paid_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8");

            // Live schema migration — add new columns to existing tables if missing
            $existingCols = array_column($pdo->query("DESCRIBE payments")->fetchAll(PDO::FETCH_ASSOC), 'Field');
            $newCols = [
                'transaction_id'       => "ALTER TABLE payments ADD COLUMN transaction_id VARCHAR(100) DEFAULT '' AFTER transaction_ref",
                'payment_type'         => "ALTER TABLE payments ADD COLUMN payment_type ENUM('membership','event_registration','donation') NOT NULL DEFAULT 'membership' AFTER notes",
                'membership_period'    => "ALTER TABLE payments ADD COLUMN membership_period VARCHAR(20) DEFAULT '1_year' AFTER payment_type",
                'membership_expires_at'=> "ALTER TABLE payments ADD COLUMN membership_expires_at DATE NULL DEFAULT NULL AFTER membership_period",
                'event_id'             => "ALTER TABLE payments ADD COLUMN event_id VARCHAR(100) DEFAULT '' AFTER membership_expires_at",
                'event_title'          => "ALTER TABLE payments ADD COLUMN event_title VARCHAR(255) DEFAULT '' AFTER event_id",
                'event_date'           => "ALTER TABLE payments ADD COLUMN event_date VARCHAR(100) DEFAULT '' AFTER event_title",
            ];
            foreach ($newCols as $col => $sql) {
                if (!in_array($col, $existingCols)) { try { $pdo->exec($sql); } catch (Exception $_e) {} }
            }

            // ── Input validation ──────────────────────────────────────────
            $name         = trim($_POST['fullName']        ?? '');
            $email        = trim($_POST['email']           ?? '');
            $paymentType  = trim($_POST['paymentType']     ?? 'membership');
            $memPeriod    = trim($_POST['membershipPeriod']?? '1_year');
            $txId         = trim($_POST['transactionId']   ?? '');
            $txRef        = trim($_POST['transactionRef']  ?? '');
            $eventId      = trim($_POST['eventId']         ?? '');
            $eventTitle   = trim($_POST['eventTitle']      ?? '');
            $eventDate    = trim($_POST['eventDate']       ?? '');

            if (!in_array($paymentType, ['membership','event_registration','donation'])) $paymentType = 'membership';
            if (!in_array($memPeriod, ['1_year','2_years','3_years','lifetime'])) $memPeriod = '1_year';

            // Amount: prefer submitted value; fall back to plan price for membership
            $planPrices = ['1_year'=>100000,'2_years'=>180000,'3_years'=>250000,'lifetime'=>500000];
            $amount = (float)($_POST['amount'] ?? 0);
            if ($amount <= 0 && $paymentType === 'membership') {
                $amount = $planPrices[$memPeriod] ?? 100000;
            }

            // Membership expiry
            $expiresAt = null;
            if ($paymentType === 'membership') {
                $add = ['1_year'=>'+1 year','2_years'=>'+2 years','3_years'=>'+3 years'];
                if (isset($add[$memPeriod])) $expiresAt = date('Y-m-d', strtotime($add[$memPeriod]));
            }

            if (!$name || !$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                http_response_code(400);
                echo json_encode(['error' => 'Name and valid email are required.']);
                break;
            }

            // Prevent duplicate event registrations (same email + same event)
            if ($paymentType === 'event_registration' && $eventId) {
                // Ensure event_registrants table exists
                $pdo->exec("CREATE TABLE IF NOT EXISTS event_registrants (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    event_id VARCHAR(100) NOT NULL,
                    event_title VARCHAR(255) NOT NULL DEFAULT '',
                    event_date VARCHAR(100) DEFAULT '',
                    full_name VARCHAR(150) NOT NULL,
                    email VARCHAR(150) NOT NULL,
                    phone VARCHAR(30) DEFAULT '',
                    profession VARCHAR(100) DEFAULT '',
                    institution VARCHAR(200) DEFAULT '',
                    amount DECIMAL(12,2) NOT NULL DEFAULT 0,
                    currency VARCHAR(10) NOT NULL DEFAULT 'UGX',
                    payment_method VARCHAR(50) DEFAULT 'free',
                    transaction_ref VARCHAR(100) DEFAULT '',
                    transaction_id VARCHAR(100) DEFAULT '',
                    proof_file VARCHAR(255) DEFAULT '',
                    status ENUM('pending','confirmed','cancelled') NOT NULL DEFAULT 'confirmed',
                    payment_status ENUM('pending','verified','rejected') NOT NULL DEFAULT 'verified',
                    receipt_number VARCHAR(30) DEFAULT '',
                    receipt_token VARCHAR(64) DEFAULT '',
                    qr_scanned TINYINT(1) NOT NULL DEFAULT 0,
                    scanned_at TIMESTAMP NULL DEFAULT NULL,
                    notes TEXT DEFAULT '',
                    registered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8");

                // Live schema migration for older event_registrants tables
                $erCols = array_column($pdo->query("DESCRIBE event_registrants")->fetchAll(PDO::FETCH_ASSOC), 'Field');
                if (!in_array('qr_scanned', $erCols)) {
                    try { $pdo->exec("ALTER TABLE event_registrants ADD COLUMN qr_scanned TINYINT(1) NOT NULL DEFAULT 0 AFTER receipt_token"); } catch (Exception $_e) {}
                }
                if (!in_array('scanned_at', $erCols)) {
                    try { $pdo->exec("ALTER TABLE event_registrants ADD COLUMN scanned_at TIMESTAMP NULL DEFAULT NULL AFTER qr_scanned"); } catch (Exception $_e) {}
                }

                $dupStmt = $pdo->prepare("SELECT COUNT(*) FROM event_registrants WHERE email = ? AND event_id = ?");
                $dupStmt->execute([$email, $eventId]);
                if ($dupStmt->fetchColumn() > 0) {
                    http_response_code(409);
                    echo json_encode(['error' => 'You are already registered for this event with this email address.']);
                    break;
                }

                // Handle payment proof upload for event registration
                $proofPath = '';
                if (isset($_FILES['paymentProof']) && $_FILES['paymentProof']['error'] === UPLOAD_ERR_OK) {
                    $allowed = ['image/jpeg','image/png','image/gif','application/pdf'];
                    $fi = finfo_open(FILEINFO_MIME_TYPE); $ft = finfo_file($fi, $_FILES['paymentProof']['tmp_name']); finfo_close($fi);
                    if (in_array($ft, $allowed) && $_FILES['paymentProof']['size'] <= 5000000) {
                        $dir = 'uploads/payments/';
                        if (!is_dir($dir)) mkdir($dir, 0755, true);
                        $ext = pathinfo($_FILES['paymentProof']['name'], PATHINFO_EXTENSION);
                        $fname = 'evproof_' . uniqid() . '.' . $ext;
                        if (move_uploaded_file($_FILES['paymentProof']['tmp_name'], $dir . $fname)) { $proofPath = $dir . $fname; }
                    }
                }

                // Determine if this is a free or paid event
                $isFreeEvent = ($amount <= 0);
                $payStatus = $isFreeEvent ? 'verified' : 'pending';

                // Insert into event_registrants (NOT members table)
                $payMethod = trim($_POST['paymentMethod'] ?? ($isFreeEvent ? 'free' : 'unknown'));
                $stmt = $pdo->prepare("INSERT INTO event_registrants
                    (event_id, event_title, event_date, full_name, email, phone, profession, institution,
                     amount, currency, payment_method, transaction_ref, transaction_id, proof_file,
                     status, payment_status)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,'confirmed',?)");
                $stmt->execute([
                    $eventId, $eventTitle, $eventDate,
                    $name, $email,
                    trim($_POST['phone'] ?? ''),
                    trim($_POST['profession'] ?? ''),
                    trim($_POST['institution'] ?? ''),
                    $amount, 'UGX', $payMethod,
                    $txRef, $txId, $proofPath,
                    $payStatus
                ]);
                $regId = (int)$pdo->lastInsertId();

                // Generate receipt
                $receiptNum   = 'HOSU-EVT-' . date('Y') . '-' . str_pad($regId, 5, '0', STR_PAD_LEFT);
                $receiptToken = bin2hex(random_bytes(32));
                $pdo->prepare("UPDATE event_registrants SET receipt_number=?, receipt_token=? WHERE id=?")->execute([$receiptNum, $receiptToken, $regId]);

                echo json_encode(['success' => true, 'registrant_id' => $regId, 'receipt_token' => $receiptToken, 'receipt_number' => $receiptNum]);
                break;
            }

            // membership_type for members table: period label for members, type for others
            $membershipTypeLabel = $paymentType === 'membership' ? $memPeriod : $paymentType;

            $pdo->beginTransaction();
            $stmt = $pdo->prepare("INSERT INTO members (full_name, email, phone, profession, institution, membership_type) VALUES (?,?,?,?,?,?)");
            $stmt->execute([
                $name, $email,
                trim($_POST['phone']       ?? ''),
                trim($_POST['profession']  ?? ''),
                trim($_POST['institution'] ?? ''),
                $membershipTypeLabel
            ]);
            $memberId = (int)$pdo->lastInsertId();

            // Handle payment proof upload
            $proofPath = '';
            if (isset($_FILES['paymentProof']) && $_FILES['paymentProof']['error'] === UPLOAD_ERR_OK) {
                $allowed = ['image/jpeg','image/png','image/gif','application/pdf'];
                $fi = finfo_open(FILEINFO_MIME_TYPE); $ft = finfo_file($fi, $_FILES['paymentProof']['tmp_name']); finfo_close($fi);
                if (in_array($ft, $allowed) && $_FILES['paymentProof']['size'] <= 5000000) {
                    $dir = 'uploads/payments/';
                    if (!is_dir($dir)) mkdir($dir, 0755, true);
                    $ext = pathinfo($_FILES['paymentProof']['name'], PATHINFO_EXTENSION);
                    $fname = 'proof_' . $memberId . '_' . uniqid() . '.' . $ext;
                    if (move_uploaded_file($_FILES['paymentProof']['tmp_name'], $dir . $fname)) { $proofPath = $dir . $fname; }
                }
            }

            $stmt = $pdo->prepare("INSERT INTO payments
                (member_id, amount, currency, payment_method, transaction_ref, transaction_id, proof_file,
                 payment_type, membership_period, membership_expires_at, event_id, event_title, event_date)
                VALUES (?,?,'UGX',?,?,?,?,?,?,?,?,?,?)");
            $stmt->execute([
                $memberId, $amount,
                trim($_POST['paymentMethod'] ?? 'unknown'),
                $txRef, $txId, $proofPath,
                $paymentType, $memPeriod, $expiresAt,
                $eventId, $eventTitle, $eventDate
            ]);
            $paymentId = (int)$pdo->lastInsertId();

            // Generate unique receipt number and one-time token
            $typePrefix = ['membership'=>'MEM','event_registration'=>'EVT','donation'=>'DON'];
            $receiptNum   = 'HOSU-' . ($typePrefix[$paymentType] ?? 'PAY') . '-' . date('Y') . '-' . str_pad($paymentId, 5, '0', STR_PAD_LEFT);
            $receiptToken = bin2hex(random_bytes(32));
            $pdo->prepare("UPDATE payments SET receipt_number=?, receipt_token=? WHERE id=?")->execute([$receiptNum, $receiptToken, $paymentId]);

            // Auto-verify event registrations and donations — no gateway used, confirmation is instant
            if (in_array($paymentType, ['event_registration', 'donation'])) {
                $pdo->prepare("UPDATE payments SET status='verified', paid_at=NOW() WHERE id=?")->execute([$paymentId]);
                $pdo->prepare("UPDATE members  SET status='active'             WHERE id=?")->execute([$memberId]);
            }

            $pdo->commit();

            // Send receipt email for auto-verified payments
            if (in_array($paymentType, ['event_registration', 'donation'])) {
                sendReceiptEmail($pdo, $paymentId, $receiptToken);
            }

            echo json_encode(['success' => true, 'member_id' => $memberId, 'receipt_token' => $receiptToken, 'receipt_number' => $receiptNum]);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    // ── Pre-register: save member+payment as PENDING before gateway call ─
    case 'pre_register':
        try {
            // Ensure tables + columns exist (same migration as register_member)
            $pdo->exec("CREATE TABLE IF NOT EXISTS members (
                id INT AUTO_INCREMENT PRIMARY KEY,
                full_name VARCHAR(150) NOT NULL,
                email VARCHAR(150) NOT NULL,
                phone VARCHAR(30) DEFAULT '',
                profession VARCHAR(100) DEFAULT '',
                institution VARCHAR(200) DEFAULT '',
                membership_type VARCHAR(50) NOT NULL DEFAULT 'annual',
                status ENUM('pending','active','expired','rejected') NOT NULL DEFAULT 'pending',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $pdo->exec("CREATE TABLE IF NOT EXISTS payments (
                id INT AUTO_INCREMENT PRIMARY KEY,
                member_id INT NOT NULL,
                amount DECIMAL(12,2) NOT NULL DEFAULT 0,
                currency VARCHAR(10) NOT NULL DEFAULT 'UGX',
                payment_method VARCHAR(50) DEFAULT 'unknown',
                transaction_ref VARCHAR(100) DEFAULT '',
                transaction_id VARCHAR(100) DEFAULT '',
                proof_file VARCHAR(255) DEFAULT '',
                status ENUM('pending','verified','rejected') NOT NULL DEFAULT 'pending',
                invoice_sent TINYINT(1) NOT NULL DEFAULT 0,
                receipt_number VARCHAR(30) DEFAULT '',
                receipt_token VARCHAR(64) DEFAULT '',
                qr_scanned TINYINT(1) NOT NULL DEFAULT 0,
                scanned_at TIMESTAMP NULL DEFAULT NULL,
                notes TEXT DEFAULT '',
                payment_type ENUM('membership','event_registration','donation') NOT NULL DEFAULT 'membership',
                membership_period VARCHAR(20) DEFAULT '1_year',
                membership_expires_at DATE NULL DEFAULT NULL,
                event_id VARCHAR(100) DEFAULT '',
                event_title VARCHAR(255) DEFAULT '',
                event_date VARCHAR(100) DEFAULT '',
                paid_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $name        = trim($_POST['fullName']         ?? '');
            $email       = trim($_POST['email']            ?? '');
            $paymentType = trim($_POST['paymentType']      ?? 'membership');
            $memPeriod   = trim($_POST['membershipPeriod'] ?? '1_year');
            $txId        = trim($_POST['transactionId']    ?? '');
            $txRef       = trim($_POST['transactionRef']   ?? $txId);
            $eventId     = trim($_POST['eventId']          ?? '');
            $eventTitle  = trim($_POST['eventTitle']       ?? '');
            $eventDate   = trim($_POST['eventDate']        ?? '');

            if (!in_array($paymentType, ['membership','event_registration','donation'])) $paymentType = 'membership';
            if (!in_array($memPeriod, ['1_year','2_years','3_years','lifetime']))        $memPeriod   = '1_year';

            if (!$name || !$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                http_response_code(400);
                echo json_encode(['error' => 'Name and valid email are required.']);
                break;
            }

            $planPrices = ['1_year'=>100000,'2_years'=>180000,'3_years'=>250000,'lifetime'=>500000];
            $amount     = (float)($_POST['amount'] ?? 0);
            if ($amount <= 0) $amount = $paymentType === 'membership' ? ($planPrices[$memPeriod] ?? 100000) : 50000;

            $expiresAt = null;
            if ($paymentType === 'membership') {
                $add = ['1_year'=>'+1 year','2_years'=>'+2 years','3_years'=>'+3 years'];
                if (isset($add[$memPeriod])) $expiresAt = date('Y-m-d', strtotime($add[$memPeriod]));
            }

            $membershipTypeLabel = $paymentType === 'membership' ? $memPeriod : $paymentType;

            $pdo->beginTransaction();
            $stmt = $pdo->prepare("INSERT INTO members (full_name, email, phone, profession, institution, membership_type, status) VALUES (?,?,?,?,?,?,'pending')");
            $stmt->execute([
                $name, $email,
                trim($_POST['phone']       ?? ''),
                trim($_POST['profession']  ?? ''),
                trim($_POST['institution'] ?? ''),
                $membershipTypeLabel
            ]);
            $memberId = (int)$pdo->lastInsertId();

            $stmt = $pdo->prepare("INSERT INTO payments
                (member_id, amount, currency, payment_method, transaction_ref, transaction_id,
                 payment_type, membership_period, membership_expires_at, event_id, event_title, event_date, status)
                VALUES (?,?,'UGX',?,?,?,?,?,?,?,?,?,'pending')");
            $stmt->execute([
                $memberId, $amount,
                trim($_POST['paymentMethod'] ?? 'unknown'),
                $txRef, $txId,
                $paymentType, $memPeriod, $expiresAt,
                $eventId, $eventTitle, $eventDate
            ]);
            $paymentId = (int)$pdo->lastInsertId();

            $typePrefix  = ['membership'=>'MEM','event_registration'=>'EVT','donation'=>'DON'];
            $receiptNum  = 'HOSU-' . ($typePrefix[$paymentType] ?? 'PAY') . '-' . date('Y') . '-' . str_pad($paymentId, 5, '0', STR_PAD_LEFT);
            $receiptToken = bin2hex(random_bytes(32));
            $pdo->prepare("UPDATE payments SET receipt_number=?, receipt_token=? WHERE id=?")->execute([$receiptNum, $receiptToken, $paymentId]);

            $pdo->commit();
            echo json_encode(['success' => true, 'payment_id' => $paymentId, 'member_id' => $memberId, 'receipt_token' => $receiptToken, 'receipt_number' => $receiptNum]);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    // ── Confirm payment: called immediately after gateway success ─────────
    case 'confirm_payment':
        try {
            $paymentId    = (int)(($_POST['payment_id']    ?? $_GET['payment_id']    ?? 0));
            $receiptToken = trim($_POST['receipt_token']   ?? $_GET['receipt_token'] ?? '');

            if (!$paymentId || strlen($receiptToken) !== 64) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid payment reference.']);
                break;
            }

            // Verify token matches payment_id to prevent tampering
            $row = $pdo->prepare("SELECT id, member_id FROM payments WHERE id=? AND receipt_token=? AND status='pending'");
            $row->execute([$paymentId, $receiptToken]);
            $pay = $row->fetch(PDO::FETCH_ASSOC);

            if (!$pay) {
                // Already confirmed or mismatch — check if already verified (idempotent)
                $chk = $pdo->prepare("SELECT id FROM payments WHERE id=? AND receipt_token=? AND status='verified'");
                $chk->execute([$paymentId, $receiptToken]);
                if ($chk->fetch()) {
                    echo json_encode(['success' => true, 'already_confirmed' => true]);
                } else {
                    http_response_code(404);
                    echo json_encode(['error' => 'Payment not found or already processed.']);
                }
                break;
            }

            $pdo->beginTransaction();
            $pdo->prepare("UPDATE payments SET status='verified', paid_at=NOW() WHERE id=?")->execute([$pay['id']]);
            $pdo->prepare("UPDATE members  SET status='active'             WHERE id=?")->execute([$pay['member_id']]);
            $pdo->commit();

            // Send receipt email
            sendReceiptEmail($pdo, $pay['id'], $receiptToken);

            echo json_encode(['success' => true, 'receipt_token' => $receiptToken]);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    case 'list_members':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            $stmt = $pdo->query("
                SELECT m.*, 
                    p.id as payment_id, p.amount, p.currency, p.payment_method,
                    p.transaction_ref, p.transaction_id, p.proof_file, p.status as payment_status,
                    p.invoice_sent, p.receipt_number, p.receipt_token, p.qr_scanned, p.paid_at,
                    p.payment_type, p.membership_period, p.membership_expires_at,
                    DATE_FORMAT(m.created_at,'%d %b %Y') as joined_date
                FROM members m
                LEFT JOIN payments p ON p.member_id = m.id
                WHERE m.membership_type != 'event_registration'
                ORDER BY m.created_at DESC
            ");
            echo json_encode(['success' => true, 'members' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        } catch (PDOException $e) {
            http_response_code(500); echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    case 'update_member_status':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            $allowed = ['pending','active','expired','rejected'];
            $status = $_POST['status'] ?? '';
            $id = (int)($_POST['id'] ?? 0);
            if (!in_array($status, $allowed) || !$id) { http_response_code(400); echo json_encode(['error' => 'Invalid input']); break; }
            $pdo->prepare("UPDATE members SET status=? WHERE id=?")->execute([$status, $id]);
            echo json_encode(['success' => true]);
        } catch (PDOException $e) { http_response_code(500); echo json_encode(['error' => $e->getMessage()]); }
        break;

    case 'verify_payment':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            $allowed = ['pending','verified','rejected'];
            $status = $_POST['status'] ?? '';
            $id = (int)($_POST['payment_id'] ?? 0);
            if (!in_array($status, $allowed) || !$id) { http_response_code(400); echo json_encode(['error' => 'Invalid input']); break; }
            $pdo->prepare("UPDATE payments SET status=? WHERE id=?")->execute([$status, $id]);
            // If verified, also set member status to active
            if ($status === 'verified') {
                $pdo->prepare("UPDATE members m JOIN payments p ON p.member_id=m.id SET m.status='active' WHERE p.id=?")->execute([$id]);
            }
            echo json_encode(['success' => true]);
        } catch (PDOException $e) { http_response_code(500); echo json_encode(['error' => $e->getMessage()]); }
        break;

    case 'mark_invoice_sent':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            $id = (int)($_POST['payment_id'] ?? 0);
            if (!$id) { http_response_code(400); echo json_encode(['error' => 'payment_id required']); break; }
            $pdo->prepare("UPDATE payments SET invoice_sent=1 WHERE id=?")->execute([$id]);
            echo json_encode(['success' => true]);
        } catch (PDOException $e) { http_response_code(500); echo json_encode(['error' => $e->getMessage()]); }
        break;

    case 'export_csv':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            $stmt = $pdo->query("
                SELECT m.id, m.full_name, m.email, m.phone, m.profession, m.institution,
                    m.membership_type, m.status as member_status,
                    DATE_FORMAT(m.created_at,'%Y-%m-%d') as joined_date,
                    p.payment_type, p.membership_period,
                    DATE_FORMAT(p.membership_expires_at,'%Y-%m-%d') as membership_expires_at,
                    p.event_title, p.event_date,
                    p.amount, p.currency, p.payment_method, p.transaction_ref, p.transaction_id,
                    p.status as payment_status, p.invoice_sent, p.receipt_number,
                    DATE_FORMAT(p.paid_at,'%Y-%m-%d') as paid_date
                FROM members m
                LEFT JOIN payments p ON p.member_id = m.id
                ORDER BY m.created_at DESC
            ");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            // Remove JSON header and output CSV
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="hosu_members_' . date('Y-m-d') . '.csv"');
            $out = fopen('php://output', 'w');
            if ($rows) fputcsv($out, array_keys($rows[0]));
            foreach ($rows as $row) fputcsv($out, $row);
            fclose($out);
            exit;
        } catch (PDOException $e) { http_response_code(500); echo json_encode(['error' => $e->getMessage()]); }
        break;

    // ── Receipt validation (one-time QR scan) ────────────────────────────
    case 'receipt_validate':
        $token = trim($_GET['token'] ?? '');
        if (!$token || strlen($token) !== 64) { http_response_code(400); echo json_encode(['error' => 'Invalid token']); break; }
        try {
            $stmt = $pdo->prepare("
                SELECT p.*, m.full_name, m.email, m.phone, m.membership_type, m.profession, m.institution
                FROM payments p
                JOIN members m ON m.id = p.member_id
                WHERE p.receipt_token = ?
            ");
            $stmt->execute([$token]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) { http_response_code(404); echo json_encode(['error' => 'Receipt not found']); break; }
            echo json_encode(['success' => true, 'receipt' => $row]);
        } catch (PDOException $e) { http_response_code(500); echo json_encode(['error' => $e->getMessage()]); }
        break;

    case 'receipt_scan':
        // Mark QR as scanned (one-time) — called by receipt.php server-side, not exposed to public JS
        $token = trim($_POST['token'] ?? '');
        if (!$token || strlen($token) !== 64) { http_response_code(400); echo json_encode(['error' => 'Invalid token']); break; }
        try {
            $stmt = $pdo->prepare("SELECT id, qr_scanned FROM payments WHERE receipt_token=?");
            $stmt->execute([$token]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) { http_response_code(404); echo json_encode(['error' => 'Not found']); break; }
            if (!$row['qr_scanned']) {
                $pdo->prepare("UPDATE payments SET qr_scanned=1, scanned_at=NOW() WHERE receipt_token=?")->execute([$token]);
            }
            echo json_encode(['success' => true, 'already_scanned' => (bool)$row['qr_scanned']]);
        } catch (PDOException $e) { http_response_code(500); echo json_encode(['error' => $e->getMessage()]); }
        break;

    // ── Events (update) ──────────────────────────────────────────────────
    case 'get_event':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        $id = $_GET['id'] ?? '';
        if (!$id) { http_response_code(400); echo json_encode(['error' => 'ID required']); break; }
        try {
            $stmt = $pdo->prepare("SELECT * FROM events WHERE id=?");
            $stmt->execute([$id]);
            $ev = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$ev) { http_response_code(404); echo json_encode(['error' => 'Not found']); break; }
            $ev['image'] = str_replace('\\', '/', $ev['image']);
            echo json_encode(['success' => true, 'event' => $ev]);
        } catch (PDOException $e) { http_response_code(500); echo json_encode(['error' => $e->getMessage()]); }
        break;

    case 'update_event':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            $id = trim($_POST['id'] ?? '');
            if (!$id) { http_response_code(400); echo json_encode(['error' => 'ID required']); break; }

            // Ensure is_free / event_fee / date_start / date_end columns exist (safe migration)
            foreach (['date_start DATE NULL', 'date_end DATE NULL', 'is_free TINYINT(1) NOT NULL DEFAULT 1', 'event_fee DECIMAL(12,2) NOT NULL DEFAULT 0'] as $colDef) {
                $colName = explode(' ', $colDef)[0];
                try { $pdo->exec("ALTER TABLE events ADD COLUMN $colName " . substr($colDef, strlen($colName) + 1)); } catch (Exception $_e) {}
            }

            // Validate date_start / date_end
            $dateStart = !empty($_POST['date_start']) ? trim($_POST['date_start']) : null;
            $dateEnd   = !empty($_POST['date_end'])   ? trim($_POST['date_end'])   : null;

            // Auto-determine status and category from dates
            $status   = $_POST['status'] ?? 'open';
            $category = $_POST['category'] ?? 'upcoming';
            if ($dateStart) {
                $today    = new DateTimeImmutable('today');
                $startDt  = new DateTimeImmutable($dateStart);
                $endDt    = $dateEnd ? new DateTimeImmutable($dateEnd) : $startDt;
                if ($today > $endDt) {
                    $status   = 'past';
                    $category = 'past';
                } elseif ($today >= $startDt && $today <= $endDt) {
                    $status = 'open';
                    if ($category === 'past') $category = 'current';
                } else {
                    if ($status === 'past') $status = 'open';
                    if ($category === 'past') $category = 'upcoming';
                }
            }

            // Handle optional new image upload
            $imagePath = null;
            if (isset($_FILES['imageFile']) && $_FILES['imageFile']['error'] === UPLOAD_ERR_OK) {
                $allowed = ['image/jpeg','image/png','image/gif','image/webp'];
                $fi = finfo_open(FILEINFO_MIME_TYPE);
                $ft = finfo_file($fi, $_FILES['imageFile']['tmp_name']);
                finfo_close($fi);
                if (in_array($ft, $allowed) && $_FILES['imageFile']['size'] <= 5000000) {
                    $dir = 'uploads/events/';
                    if (!is_dir($dir)) mkdir($dir, 0755, true);
                    $fname = uniqid() . '_' . basename($_FILES['imageFile']['name']);
                    if (move_uploaded_file($_FILES['imageFile']['tmp_name'], $dir . $fname)) {
                        $imagePath = $dir . $fname;
                    }
                }
            } elseif (!empty($_POST['image'])) {
                $imagePath = filter_var($_POST['image'], FILTER_SANITIZE_URL);
            }

            $fields = "type=?, status=?, imageAlt=?, countdown=?, date=?, title=?, description=?, location=?, featured=?, category=?, is_free=?, event_fee=?, date_start=?, date_end=?";
            $isFree = !empty($_POST['is_free']) ? 1 : 0;
            $eventFee = $isFree ? 0 : max(0, (float)($_POST['event_fee'] ?? 0));
            $vals = [
                $_POST['type'] ?? '', $status, $_POST['imageAlt'] ?? '',
                $_POST['countdown'] ?? '', $_POST['date'] ?? '', $_POST['title'] ?? '',
                $_POST['description'] ?? '', $_POST['location'] ?? '',
                (!empty($_POST['featured']) && $_POST['featured'] !== '0') ? 1 : 0, $category,
                $isFree, $eventFee, $dateStart, $dateEnd,
            ];
            if ($imagePath !== null) { $fields .= ", image=?"; $vals[] = $imagePath; }
            $vals[] = $id;
            $pdo->prepare("UPDATE events SET $fields WHERE id=?")->execute($vals);
            echo json_encode(['success' => true]);
        } catch (PDOException $e) { http_response_code(500); echo json_encode(['error' => $e->getMessage()]); }
        break;

    // ── Unified payments list (memberships + donations + paid event registrations) ──
    case 'list_payments':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            // 1. Membership & donation payments from payments table
            $stmt1 = $pdo->query("
                SELECT
                    p.id           AS payment_id,
                    'payments'     AS source,
                    NULL           AS registrant_id,
                    m.full_name, m.email, m.phone,
                    p.payment_type,
                    p.amount, p.currency, p.payment_method,
                    p.transaction_ref, p.transaction_id,
                    p.proof_file, p.status AS payment_status,
                    p.invoice_sent, p.receipt_number, p.receipt_token,
                    p.membership_period, p.membership_expires_at,
                    p.event_id, p.event_title, p.event_date,
                    p.paid_at,
                    m.id AS member_id
                FROM payments p
                JOIN members m ON m.id = p.member_id
                WHERE p.payment_type IN ('membership','donation')
                ORDER BY p.paid_at DESC
            ");
            $membPay = $stmt1->fetchAll(PDO::FETCH_ASSOC);

            // 2. Paid event registrations — only for events that are currently NOT free
            $stmt2 = $pdo->query("
                SELECT
                    r.id           AS payment_id,
                    'event_registrants' AS source,
                    r.id           AS registrant_id,
                    r.full_name, r.email, r.phone,
                    'event_registration' AS payment_type,
                    r.amount, r.currency, r.payment_method,
                    r.transaction_ref, r.transaction_id,
                    r.proof_file, r.payment_status,
                    0              AS invoice_sent,
                    r.receipt_number, r.receipt_token,
                    NULL           AS membership_period,
                    NULL           AS membership_expires_at,
                    r.event_id, r.event_title, r.event_date,
                    r.registered_at AS paid_at,
                    NULL           AS member_id
                FROM event_registrants r
                INNER JOIN events e ON e.id = r.event_id
                WHERE e.is_free = 0
                  AND r.amount > 0
                ORDER BY r.registered_at DESC
            ");
            $evPay = $stmt2->fetchAll(PDO::FETCH_ASSOC);

            // 3. Grant application payments (linked via grant_applications.payment_id)
            $grantPay = [];
            try {
                $stmt3 = $pdo->query("
                    SELECT
                        p.id           AS payment_id,
                        'grant_application' AS source,
                        ga.id          AS registrant_id,
                        ga.full_name, ga.email, ga.phone,
                        'grant_application' AS payment_type,
                        p.amount, p.currency, p.payment_method,
                        p.transaction_ref, p.transaction_id,
                        p.proof_file, p.status AS payment_status,
                        0              AS invoice_sent,
                        p.receipt_number, p.receipt_token,
                        NULL           AS membership_period,
                        NULL           AS membership_expires_at,
                        NULL           AS event_id,
                        g.title        AS event_title,
                        g.deadline     AS event_date,
                        p.paid_at,
                        NULL           AS member_id
                    FROM grant_applications ga
                    INNER JOIN payments p ON ga.payment_id = p.id
                    INNER JOIN grants_opportunities g ON g.id = ga.grant_id
                    ORDER BY p.paid_at DESC
                ");
                $grantPay = $stmt3->fetchAll(PDO::FETCH_ASSOC);
            } catch (PDOException $e) { /* table may not exist yet */ }

            // 4. Merge, sort by date descending
            $all = array_merge($membPay, $evPay, $grantPay);
            usort($all, function($a, $b) {
                return strtotime($b['paid_at'] ?? '0') - strtotime($a['paid_at'] ?? '0');
            });

            echo json_encode(['success' => true, 'payments' => $all]);
        } catch (PDOException $e) {
            http_response_code(500); echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    // ── Payments CSV export (includes event registrations) ─────────────
    case 'export_payments_csv':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            // Membership & donation payments
            $stmt1 = $pdo->query("
                SELECT 'membership/donation' AS source,
                    m.full_name, m.email, m.phone,
                    p.payment_type, p.membership_period,
                    DATE_FORMAT(p.membership_expires_at,'%Y-%m-%d') AS membership_expires_at,
                    p.event_title, p.event_date,
                    p.amount, p.currency, p.payment_method,
                    p.transaction_ref, p.transaction_id,
                    p.status AS payment_status, p.invoice_sent, p.receipt_number,
                    DATE_FORMAT(p.paid_at,'%Y-%m-%d') AS paid_date
                FROM payments p
                JOIN members m ON m.id = p.member_id
                WHERE p.payment_type IN ('membership','donation')
            ");
            $rows1 = $stmt1->fetchAll(PDO::FETCH_ASSOC);

            // Paid event registrations
            $stmt2 = $pdo->query("
                SELECT 'event_registration' AS source,
                    r.full_name, r.email, r.phone,
                    'event_registration' AS payment_type, '' AS membership_period,
                    '' AS membership_expires_at,
                    r.event_title, r.event_date,
                    r.amount, r.currency, r.payment_method,
                    r.transaction_ref, r.transaction_id,
                    r.payment_status, 0 AS invoice_sent, r.receipt_number,
                    DATE_FORMAT(r.registered_at,'%Y-%m-%d') AS paid_date
                FROM event_registrants r
                INNER JOIN events e ON e.id = r.event_id
                WHERE e.is_free = 0 AND r.amount > 0
            ");
            $rows2 = $stmt2->fetchAll(PDO::FETCH_ASSOC);

            // Grant application payments
            $rows3 = [];
            try {
                $stmt3 = $pdo->query("
                    SELECT 'grant_application' AS source,
                        ga.full_name, ga.email, ga.phone,
                        'grant_application' AS payment_type, '' AS membership_period,
                        '' AS membership_expires_at,
                        g.title AS event_title, g.deadline AS event_date,
                        p.amount, p.currency, p.payment_method,
                        p.transaction_ref, p.transaction_id,
                        p.status AS payment_status, 0 AS invoice_sent, p.receipt_number,
                        DATE_FORMAT(p.paid_at,'%Y-%m-%d') AS paid_date
                    FROM grant_applications ga
                    INNER JOIN payments p ON ga.payment_id = p.id
                    INNER JOIN grants_opportunities g ON g.id = ga.grant_id
                ");
                $rows3 = $stmt3->fetchAll(PDO::FETCH_ASSOC);
            } catch (PDOException $e) { /* tables may not exist */ }

            $all = array_merge($rows1, $rows2, $rows3);
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="hosu_payments_' . date('Y-m-d') . '.csv"');
            $out = fopen('php://output', 'w');
            if ($all) fputcsv($out, array_keys($all[0]));
            foreach ($all as $row) fputcsv($out, $row);
            fclose($out);
            exit;
        } catch (PDOException $e) { http_response_code(500); echo json_encode(['error' => $e->getMessage()]); }
        break;

    // ── Publications ─────────────────────────────────────────────────
    case 'get_publications':
        try {
            // Auto-create table if missing
            $pdo->exec("CREATE TABLE IF NOT EXISTS publications (
                id INT AUTO_INCREMENT PRIMARY KEY,
                pub_type VARCHAR(50) NOT NULL DEFAULT 'Journal',
                title VARCHAR(255) NOT NULL,
                authors VARCHAR(255) NOT NULL DEFAULT '',
                pub_date VARCHAR(50) NOT NULL DEFAULT '',
                link VARCHAR(500) DEFAULT '',
                link_label VARCHAR(100) DEFAULT 'Read abstract',
                sort_order INT NOT NULL DEFAULT 0,
                show_on_home TINYINT(1) NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
            // Add show_on_home column if missing
            try { $pdo->exec("ALTER TABLE publications ADD COLUMN show_on_home TINYINT(1) NOT NULL DEFAULT 0"); } catch (Exception $_e) {}
            $stmt = $pdo->query("SELECT * FROM publications ORDER BY sort_order ASC, created_at DESC");
            $pubs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($pubs as &$p) { $p['show_on_home'] = (bool)($p['show_on_home'] ?? 0); }
            echo json_encode(['success' => true, 'publications' => $pubs]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    case 'create_publication':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            $title = trim($_POST['title'] ?? '');
            $pub_type = trim($_POST['pub_type'] ?? 'Journal');
            $authors = trim($_POST['authors'] ?? '');
            $pub_date = trim($_POST['pub_date'] ?? '');
            $link = trim($_POST['link'] ?? '');
            $link_label = trim($_POST['link_label'] ?? 'Read abstract');
            $sort_order = (int)($_POST['sort_order'] ?? 0);
            $show_on_home = isset($_POST['show_on_home']) ? 1 : 0;
            if (!$title) { http_response_code(400); echo json_encode(['error' => 'Title is required']); break; }
            $stmt = $pdo->prepare("INSERT INTO publications (pub_type, title, authors, pub_date, link, link_label, sort_order, show_on_home) VALUES (?,?,?,?,?,?,?,?)");
            $stmt->execute([$pub_type, $title, $authors, $pub_date, $link, $link_label, $sort_order, $show_on_home]);
            echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
        } catch (PDOException $e) {
            http_response_code(500); echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    case 'update_publication':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) { http_response_code(400); echo json_encode(['error' => 'ID required']); break; }
            $title = trim($_POST['title'] ?? '');
            $pub_type = trim($_POST['pub_type'] ?? 'Journal');
            $authors = trim($_POST['authors'] ?? '');
            $pub_date = trim($_POST['pub_date'] ?? '');
            $link = trim($_POST['link'] ?? '');
            $link_label = trim($_POST['link_label'] ?? 'Read abstract');
            $sort_order = (int)($_POST['sort_order'] ?? 0);
            $show_on_home = isset($_POST['show_on_home']) ? 1 : 0;
            if (!$title) { http_response_code(400); echo json_encode(['error' => 'Title is required']); break; }
            $stmt = $pdo->prepare("UPDATE publications SET pub_type=?, title=?, authors=?, pub_date=?, link=?, link_label=?, sort_order=?, show_on_home=? WHERE id=?");
            $stmt->execute([$pub_type, $title, $authors, $pub_date, $link, $link_label, $sort_order, $show_on_home, $id]);
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            http_response_code(500); echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    case 'delete_publication':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) { http_response_code(400); echo json_encode(['error' => 'ID required']); break; }
            $pdo->prepare("DELETE FROM publications WHERE id=?")->execute([$id]);
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            http_response_code(500); echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    // ── Grants & Opportunities ────────────────────────────────────────
    case 'get_grants':
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS grants_opportunities (
                id INT AUTO_INCREMENT PRIMARY KEY,
                title VARCHAR(255) NOT NULL,
                amount DECIMAL(15,2) NOT NULL DEFAULT 0,
                currency VARCHAR(10) NOT NULL DEFAULT 'UGX',
                deadline VARCHAR(100) DEFAULT '',
                status VARCHAR(50) NOT NULL DEFAULT 'open',
                description TEXT DEFAULT '',
                apply_link VARCHAR(500) DEFAULT '',
                sort_order INT NOT NULL DEFAULT 0,
                show_on_home TINYINT(1) NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
            // Add show_on_home column if missing
            try { $pdo->exec("ALTER TABLE grants_opportunities ADD COLUMN show_on_home TINYINT(1) NOT NULL DEFAULT 0"); } catch (Exception $_e) {}
            $stmt = $pdo->query("SELECT * FROM grants_opportunities ORDER BY sort_order ASC, created_at DESC");
            $grants = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($grants as &$g) { $g['show_on_home'] = (bool)($g['show_on_home'] ?? 0); }
            echo json_encode(['success' => true, 'grants' => $grants]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    case 'create_grant':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            $title = trim($_POST['title'] ?? '');
            $amount = (float)($_POST['amount'] ?? 0);
            $currency = trim($_POST['currency'] ?? 'UGX');
            $deadline = trim($_POST['deadline'] ?? '');
            $status = trim($_POST['status'] ?? 'open');
            $description = trim($_POST['description'] ?? '');
            $apply_link = trim($_POST['apply_link'] ?? '');
            $sort_order = (int)($_POST['sort_order'] ?? 0);
            $show_on_home = isset($_POST['show_on_home']) ? 1 : 0;
            if (!$title) { http_response_code(400); echo json_encode(['error' => 'Title is required']); break; }
            $stmt = $pdo->prepare("INSERT INTO grants_opportunities (title, amount, currency, deadline, status, description, apply_link, sort_order, show_on_home) VALUES (?,?,?,?,?,?,?,?,?)");
            $stmt->execute([$title, $amount, $currency, $deadline, $status, $description, $apply_link, $sort_order, $show_on_home]);
            echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
        } catch (PDOException $e) {
            http_response_code(500); echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    case 'update_grant':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) { http_response_code(400); echo json_encode(['error' => 'ID required']); break; }
            $title = trim($_POST['title'] ?? '');
            $amount = (float)($_POST['amount'] ?? 0);
            $currency = trim($_POST['currency'] ?? 'UGX');
            $deadline = trim($_POST['deadline'] ?? '');
            $status = trim($_POST['status'] ?? 'open');
            $description = trim($_POST['description'] ?? '');
            $apply_link = trim($_POST['apply_link'] ?? '');
            $sort_order = (int)($_POST['sort_order'] ?? 0);
            $show_on_home = isset($_POST['show_on_home']) ? 1 : 0;
            if (!$title) { http_response_code(400); echo json_encode(['error' => 'Title is required']); break; }
            $stmt = $pdo->prepare("UPDATE grants_opportunities SET title=?, amount=?, currency=?, deadline=?, status=?, description=?, apply_link=?, sort_order=?, show_on_home=? WHERE id=?");
            $stmt->execute([$title, $amount, $currency, $deadline, $status, $description, $apply_link, $sort_order, $show_on_home, $id]);
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            http_response_code(500); echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    case 'delete_grant':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) { http_response_code(400); echo json_encode(['error' => 'ID required']); break; }
            $pdo->prepare("DELETE FROM grants_opportunities WHERE id=?")->execute([$id]);
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            http_response_code(500); echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    case 'get_grant_applications':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            $grantId = (int)($_GET['grant_id'] ?? $_POST['grant_id'] ?? 0);
            if (!$grantId) { http_response_code(400); echo json_encode(['error' => 'grant_id required']); break; }
            $pdo->exec("CREATE TABLE IF NOT EXISTS grant_applications (
                id INT AUTO_INCREMENT PRIMARY KEY,
                grant_id INT NOT NULL,
                full_name VARCHAR(150) NOT NULL,
                email VARCHAR(150) NOT NULL,
                phone VARCHAR(30) DEFAULT '',
                institution VARCHAR(200) DEFAULT '',
                proposal TEXT DEFAULT '',
                status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
                payment_id INT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
            $stmt = $pdo->prepare("
                SELECT ga.*, p.amount AS paid_amount, p.currency AS paid_currency, p.status AS payment_status
                FROM grant_applications ga
                LEFT JOIN payments p ON ga.payment_id = p.id
                WHERE ga.grant_id = ?
                ORDER BY ga.created_at DESC
            ");
            $stmt->execute([$grantId]);
            echo json_encode(['success' => true, 'applications' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        } catch (PDOException $e) {
            http_response_code(500); echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    case 'submit_grant_application':
        try {
            $grant_id    = (int)($_POST['grant_id'] ?? 0);
            $full_name   = trim($_POST['full_name'] ?? '');
            $email       = trim($_POST['email'] ?? '');
            $phone       = trim($_POST['phone'] ?? '');
            $institution = trim($_POST['institution'] ?? '');
            $proposal    = trim($_POST['proposal'] ?? '');

            if (!$grant_id || !$full_name || !$email) {
                http_response_code(400);
                echo json_encode(['error' => 'Grant ID, full name and email are required']);
                break;
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid email address']);
                break;
            }

            // Verify grant exists and is open
            $stmt = $pdo->prepare("SELECT id, title, status FROM grants_opportunities WHERE id = ?");
            $stmt->execute([$grant_id]);
            $grant = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$grant) {
                http_response_code(404);
                echo json_encode(['error' => 'Grant not found']);
                break;
            }
            if ($grant['status'] === 'closed') {
                http_response_code(400);
                echo json_encode(['error' => 'This grant is no longer accepting applications']);
                break;
            }

            // Check for duplicate application
            $stmt = $pdo->prepare("SELECT id FROM grant_applications WHERE grant_id = ? AND email = ?");
            $stmt->execute([$grant_id, $email]);
            if ($stmt->fetch()) {
                http_response_code(409);
                echo json_encode(['error' => 'You have already applied for this grant']);
                break;
            }

            $stmt = $pdo->prepare("INSERT INTO grant_applications (grant_id, full_name, email, phone, institution, proposal) VALUES (?,?,?,?,?,?)");
            $stmt->execute([$grant_id, $full_name, $email, $phone, $institution, $proposal]);
            echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
        } catch (PDOException $e) {
            http_response_code(500); echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    case 'update_grant_application_status':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            $id     = (int)($_POST['id'] ?? 0);
            $status = trim($_POST['status'] ?? '');
            if (!$id || !in_array($status, ['pending', 'approved', 'rejected'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Valid application ID and status required']);
                break;
            }
            $stmt = $pdo->prepare("UPDATE grant_applications SET status = ? WHERE id = ?");
            $stmt->execute([$status, $id]);
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            http_response_code(500); echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    case 'link_grant_payment':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            $application_id = (int)($_POST['application_id'] ?? 0);
            $payment_id     = (int)($_POST['payment_id'] ?? 0);
            if (!$application_id) {
                http_response_code(400);
                echo json_encode(['error' => 'Application ID required']);
                break;
            }
            // Allow unlinking by passing payment_id=0
            $pid = $payment_id > 0 ? $payment_id : null;
            $stmt = $pdo->prepare("UPDATE grant_applications SET payment_id = ? WHERE id = ?");
            $stmt->execute([$pid, $application_id]);
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            http_response_code(500); echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    case 'get_grant_app_counts':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS grant_applications (
                id INT AUTO_INCREMENT PRIMARY KEY,
                grant_id INT NOT NULL,
                full_name VARCHAR(150) NOT NULL,
                email VARCHAR(150) NOT NULL,
                phone VARCHAR(30) DEFAULT '',
                institution VARCHAR(200) DEFAULT '',
                proposal TEXT DEFAULT '',
                status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
                payment_id INT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
            $stmt = $pdo->query("
                SELECT grant_id,
                       COUNT(*) AS total,
                       SUM(status='approved') AS approved,
                       SUM(status='pending') AS pending,
                       SUM(status='rejected') AS rejected
                FROM grant_applications
                GROUP BY grant_id
            ");
            $counts = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $counts[$row['grant_id']] = [
                    'total'    => (int)$row['total'],
                    'approved' => (int)$row['approved'],
                    'pending'  => (int)$row['pending'],
                    'rejected' => (int)$row['rejected']
                ];
            }
            echo json_encode(['success' => true, 'counts' => $counts]);
        } catch (PDOException $e) {
            http_response_code(500); echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    case 'delete_grant_application':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) { http_response_code(400); echo json_encode(['error' => 'Application ID required']); break; }
            $stmt = $pdo->prepare("DELETE FROM grant_applications WHERE id = ?");
            $stmt->execute([$id]);
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            http_response_code(500); echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    case 'export_grants_report_csv':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS grant_applications (
                id INT AUTO_INCREMENT PRIMARY KEY,
                grant_id INT NOT NULL,
                full_name VARCHAR(150) NOT NULL,
                email VARCHAR(150) NOT NULL,
                phone VARCHAR(30) DEFAULT '',
                institution VARCHAR(200) DEFAULT '',
                proposal TEXT DEFAULT '',
                status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
                payment_id INT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
            $grantId = isset($_GET['grant_id']) ? (int)$_GET['grant_id'] : 0;
            $where = $grantId ? "WHERE ga.grant_id = " . $grantId : "";
            $stmt = $pdo->query("
                SELECT g.title AS grant_title, ga.full_name, ga.email, ga.phone, ga.institution,
                       ga.status AS application_status, ga.proposal,
                       IFNULL(p.amount,'') AS paid_amount, IFNULL(p.currency,'') AS paid_currency,
                       IFNULL(p.status,'') AS payment_status,
                       DATE_FORMAT(ga.created_at,'%Y-%m-%d') AS applied_date
                FROM grant_applications ga
                JOIN grants_opportunities g ON g.id = ga.grant_id
                LEFT JOIN payments p ON ga.payment_id = p.id
                $where
                ORDER BY ga.created_at DESC
            ");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="hosu_grant_applications_' . date('Y-m-d') . '.csv"');
            $out = fopen('php://output', 'w');
            if ($rows) fputcsv($out, array_keys($rows[0]));
            foreach ($rows as $row) fputcsv($out, $row);
            fclose($out);
            exit;
        } catch (PDOException $e) { http_response_code(500); echo json_encode(['error' => $e->getMessage()]); }
        break;

    // ── Home Featured Content ─────────────────────────────────────────
    case 'get_home_featured':
        try {
            // Get featured events (using existing featured flag)
            $evStmt = $pdo->query("SELECT id, title, description, date, date_start, date_end, location, image, type, countdown FROM events WHERE featured = 1 ORDER BY date_start ASC LIMIT 3");
            $events = $evStmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($events as &$ev) {
                $ev['image'] = str_replace('\\', '/', $ev['image']);
            }
            
            // Get publications shown on home
            $pubStmt = $pdo->query("SELECT id, title, authors, pub_type, pub_date, link, link_label FROM publications WHERE show_on_home = 1 ORDER BY sort_order ASC, created_at DESC LIMIT 3");
            $publications = $pubStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get grants shown on home
            $grantStmt = $pdo->query("SELECT id, title, amount, currency, deadline, status, description FROM grants_opportunities WHERE show_on_home = 1 AND status != 'closed' ORDER BY sort_order ASC, created_at DESC LIMIT 3");
            $grants = $grantStmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'featured' => [
                    'events' => $events,
                    'publications' => $publications,
                    'grants' => $grants
                ]
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    // ── Membership Stats (Public) ─────────────────────────────────────
    case 'get_membership_stats':
        try {
            // Total members (active and pending, excluding event registrations)
            $memberStmt = $pdo->query("SELECT COUNT(*) as total FROM members WHERE membership_type != 'event_registration' AND status IN ('active', 'pending')");
            $memberCount = (int)$memberStmt->fetch(PDO::FETCH_ASSOC)['total'];
            
            // Unique professions/specialties
            $profStmt = $pdo->query("SELECT COUNT(DISTINCT profession) as total FROM members WHERE membership_type != 'event_registration' AND profession IS NOT NULL AND profession != ''");
            $profCount = (int)$profStmt->fetch(PDO::FETCH_ASSOC)['total'];
            
            // Unique institutions
            $instStmt = $pdo->query("SELECT COUNT(DISTINCT institution) as total FROM members WHERE membership_type != 'event_registration' AND institution IS NOT NULL AND institution != ''");
            $instCount = (int)$instStmt->fetch(PDO::FETCH_ASSOC)['total'];
            
            echo json_encode([
                'success' => true,
                'stats' => [
                    'members' => $memberCount > 0 ? $memberCount . '+' : '500+',
                    'specialties' => $profCount > 0 ? $profCount . '+' : '12+',
                    'institutions' => $instCount > 0 ? $instCount . '+' : '50+'
                ]
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    // ── Leadership / Biography Management ─────────────────────────────
    case 'get_leaders':
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS leaders (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(150) NOT NULL,
                title VARCHAR(150) NOT NULL DEFAULT '',
                qualifications VARCHAR(300) DEFAULT '',
                biography TEXT DEFAULT '',
                photo_url VARCHAR(500) DEFAULT '',
                sort_order INT NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
            $stmt = $pdo->query("SELECT * FROM leaders WHERE is_active = 1 ORDER BY
                CASE
                    WHEN LOWER(title) LIKE '%president' AND LOWER(title) NOT LIKE '%elect%' AND LOWER(title) NOT LIKE '%vice%' THEN 1
                    WHEN LOWER(title) LIKE '%vice president%' THEN 2
                    WHEN LOWER(title) LIKE '%president elect%' THEN 3
                    WHEN LOWER(title) LIKE '%general secretary%' THEN 4
                    WHEN LOWER(title) LIKE '%treasurer%' THEN 5
                    WHEN LOWER(title) LIKE '%secretary%' THEN 6
                    ELSE 100
                END ASC, sort_order ASC, name ASC");
            echo json_encode(['success' => true, 'leaders' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        } catch (PDOException $e) {
            http_response_code(500); echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    case 'get_leader_bio':
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS leaders (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(150) NOT NULL,
                title VARCHAR(150) NOT NULL DEFAULT '',
                qualifications VARCHAR(300) DEFAULT '',
                biography TEXT DEFAULT '',
                photo_url VARCHAR(500) DEFAULT '',
                sort_order INT NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
            $name = trim($_GET['name'] ?? '');
            if (!$name) { echo json_encode(['success' => false, 'error' => 'Name required']); break; }
            $stmt = $pdo->prepare("SELECT * FROM leaders WHERE name = ? AND is_active = 1 LIMIT 1");
            $stmt->execute([$name]);
            $leader = $stmt->fetch(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'leader' => $leader ?: null]);
        } catch (PDOException $e) {
            http_response_code(500); echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    case 'save_leader':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS leaders (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(150) NOT NULL,
                title VARCHAR(150) NOT NULL DEFAULT '',
                qualifications VARCHAR(300) DEFAULT '',
                biography TEXT DEFAULT '',
                photo_url VARCHAR(500) DEFAULT '',
                sort_order INT NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8");
            $id = (int)($_POST['id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $title = trim($_POST['title'] ?? '');
            $qualifications = trim($_POST['qualifications'] ?? '');
            $biography = trim($_POST['biography'] ?? '');
            $sort_order = (int)($_POST['sort_order'] ?? 0);
            $is_active = isset($_POST['is_active']) ? (int)$_POST['is_active'] : 1;
            if (!$name) { http_response_code(400); echo json_encode(['error' => 'Name is required']); break; }

            // Handle photo upload
            $photo_url = trim($_POST['existing_photo'] ?? '');
            if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
                $allowed_types = ['image/jpeg', 'image/png', 'image/webp'];
                $file_type = mime_content_type($_FILES['photo']['tmp_name']);
                if (!in_array($file_type, $allowed_types)) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Only JPG, PNG or WebP images are allowed']);
                    break;
                }
                if ($_FILES['photo']['size'] > 5 * 1024 * 1024) {
                    http_response_code(400);
                    echo json_encode(['error' => 'Photo must be under 5 MB']);
                    break;
                }
                $uploadDir = 'uploads/leaders/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }
                $ext = ['image/jpeg' => '.jpg', 'image/png' => '.png', 'image/webp' => '.webp'];
                $photoName = uniqid('leader_') . ($ext[$file_type] ?? '.jpg');
                $targetPath = $uploadDir . $photoName;
                if (move_uploaded_file($_FILES['photo']['tmp_name'], $targetPath)) {
                    $photo_url = $targetPath;
                }
            } elseif (!empty($_POST['photo_url'])) {
                $photo_url = trim($_POST['photo_url']);
            }

            if ($id) {
                $stmt = $pdo->prepare("UPDATE leaders SET name=?, title=?, qualifications=?, biography=?, photo_url=?, sort_order=?, is_active=? WHERE id=?");
                $stmt->execute([$name, $title, $qualifications, $biography, $photo_url, $sort_order, $is_active, $id]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO leaders (name, title, qualifications, biography, photo_url, sort_order, is_active) VALUES (?,?,?,?,?,?,?)");
                $stmt->execute([$name, $title, $qualifications, $biography, $photo_url, $sort_order, $is_active]);
                $id = $pdo->lastInsertId();
            }
            echo json_encode(['success' => true, 'id' => $id]);
        } catch (PDOException $e) {
            http_response_code(500); echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    case 'delete_leader':
        if (empty($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
            http_response_code(401); echo json_encode(['error' => 'Admin access required']); break;
        }
        try {
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) { http_response_code(400); echo json_encode(['error' => 'ID required']); break; }
            $pdo->prepare("DELETE FROM leaders WHERE id=?")->execute([$id]);
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            http_response_code(500); echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    default:
        echo json_encode(['error' => 'Invalid action']);
}