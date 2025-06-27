<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

// Database configuration
$host = 'localhost';
$dbname = 'hosu_blog';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die(json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]));
}

// Get action from either GET or POST
$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {
    case 'check_login':
        // In a real app, you would check session or token
        echo json_encode(['logged_in' => false]);
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
        
    default:
        echo json_encode(['error' => 'Invalid action']);
}