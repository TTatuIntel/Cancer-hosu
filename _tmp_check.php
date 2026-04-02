<?php
$pdo = new PDO('mysql:host=localhost;dbname=hosu_blog;charset=utf8', 'root', '');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$q = $pdo->query("SELECT COUNT(*) FROM event_registrants r INNER JOIN events e ON e.id=r.event_id WHERE e.is_free=0 AND r.amount>0");
echo 'paid_event_rows=' . $q->fetchColumn() . PHP_EOL;

$q2 = $pdo->query("SELECT COUNT(*) FROM event_registrants r INNER JOIN events e ON e.id=r.event_id WHERE (e.is_free=1 OR r.amount<=0)");
echo 'free_or_zero_rows=' . $q2->fetchColumn() . PHP_EOL;
