<?php
/**
 * receipt.php — One-time printable receipt with QR code + barcode
 * URL: receipt.php?token=<64-char-hex-token>
 *
 * First access: displays receipt, marks scan flag.
 * Repeated scans of the QR still display the receipt (read-only after first scan).
 */

session_start();

require_once 'db.php';

$token = trim($_GET['token'] ?? '');

if (!$token || strlen($token) !== 64 || !ctype_xdigit($token)) {
    http_response_code(400);
    die(renderError('Invalid receipt link. Please contact HOSU support.'));
}

try {
    // Backward-compatible migration for older event_registrants tables
    try {
        $erCols = array_column($pdo->query("DESCRIBE event_registrants")->fetchAll(PDO::FETCH_ASSOC), 'Field');
        if (!in_array('qr_scanned', $erCols)) {
            $pdo->exec("ALTER TABLE event_registrants ADD COLUMN qr_scanned TINYINT(1) NOT NULL DEFAULT 0 AFTER receipt_token");
        }
        if (!in_array('scanned_at', $erCols)) {
            $pdo->exec("ALTER TABLE event_registrants ADD COLUMN scanned_at TIMESTAMP NULL DEFAULT NULL AFTER qr_scanned");
        }
    } catch (Exception $_e) {
        // Non-fatal: receipt lookup can still proceed for payments rows
    }

    // First try payments table (memberships, donations)
    $stmt = $pdo->prepare("
        SELECT p.*, m.full_name, m.email, m.phone, m.membership_type, m.profession, m.institution,
               DATE_FORMAT(p.paid_at, '%d %b %Y %H:%i') AS paid_formatted,
               DATE_FORMAT(p.scanned_at, '%d %b %Y %H:%i') AS scanned_formatted,
               'payments' AS _source
        FROM payments p
        JOIN members m ON m.id = p.member_id
        WHERE p.receipt_token = ?
    ");
    $stmt->execute([$token]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);

    // If not found, try event_registrants table
    if (!$r) {
        $stmt2 = $pdo->prepare("
            SELECT er.*,
                   er.full_name, er.email, er.phone, er.profession, er.institution,
                   er.payment_status AS status,
                   DATE_FORMAT(er.registered_at, '%d %b %Y %H:%i') AS paid_formatted,
                   DATE_FORMAT(er.scanned_at, '%d %b %Y %H:%i') AS scanned_formatted,
                   er.qr_scanned,
                   er.scanned_at,
                   'event_registration' AS payment_type,
                   NULL AS membership_period,
                   NULL AS membership_expires_at,
                   0 AS invoice_sent,
                   'event_registrant' AS membership_type,
                   'event_registrants' AS _source
            FROM event_registrants er
            WHERE er.receipt_token = ?
        ");
        $stmt2->execute([$token]);
        $r = $stmt2->fetch(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    http_response_code(500);
    die(renderError('Database error. Please try again.'));
}

if (!$r) {
    http_response_code(404);
    die(renderError('Receipt not found. The link may be invalid or expired.'));
}

// Mark as scanned on first legitimate load
$alreadyScanned = (bool)$r['qr_scanned'];
if (!$alreadyScanned) {
    try {
        $scanTable = ($r['_source'] ?? '') === 'event_registrants' ? 'event_registrants' : 'payments';
        $pdo->prepare("UPDATE $scanTable SET qr_scanned=1, scanned_at=NOW() WHERE receipt_token=?")
            ->execute([$token]);
    } catch (PDOException $e) { /* non-fatal */ }
}

// ── Build data for display ───────────────────────────────────────────────
$receiptNum   = htmlspecialchars($r['receipt_number'] ?: ('HOSU-MEM-' . date('Y') . '-' . str_pad($r['id'], 5, '0', STR_PAD_LEFT)));
$memberName   = htmlspecialchars($r['full_name']);
$memberEmail  = htmlspecialchars($r['email']);
$memberPhone  = htmlspecialchars($r['phone']);
$memberType   = htmlspecialchars(ucfirst($r['membership_type']));
$profession   = htmlspecialchars($r['profession']);
$institution  = htmlspecialchars($r['institution']);
$amount       = number_format((float)($r['amount'] ?? 100000), 0) . ' ' . htmlspecialchars($r['currency'] ?? 'UGX');
$paidDate     = htmlspecialchars($r['paid_formatted'] ?? $r['paid_at']);
$payMethod    = htmlspecialchars(ucfirst(str_replace(['UGMTNMOMODIR','UGAIRTELMODIR'], ['MTN Mobile Money','Airtel Money'], $r['payment_method'] ?? '')));
$txRef        = htmlspecialchars($r['transaction_ref'] ?? '');
$txId         = htmlspecialchars($r['transaction_id'] ?? $r['transaction_ref'] ?? '');
$status       = htmlspecialchars(ucfirst($r['status'] ?? 'pending'));

// Payment type fields
$paymentType  = $r['payment_type'] ?? 'membership';
$memPeriod    = $r['membership_period'] ?? '1_year';
$eventTitle   = htmlspecialchars($r['event_title'] ?? '');
$eventDate    = htmlspecialchars($r['event_date'] ?? '');

// Membership expiry
$memExpiresAt = $r['membership_expires_at'] ?? null;
$memExpiry    = ($memPeriod === 'lifetime') ? 'Lifetime Membership' : ($memExpiresAt ? date('d M Y', strtotime($memExpiresAt)) : '—');

// Period label
$periodLabels = ['1_year'=>'1 Year','2_years'=>'2 Years','3_years'=>'3 Years','lifetime'=>'Lifetime'];
$periodLabel  = $periodLabels[$memPeriod] ?? ucfirst(str_replace('_',' ',$memPeriod));

// Document type label & sub-heading
$docTypes = [
    'membership'         => ['title'=>'Membership Certificate', 'sub'=>'Official Society Membership'],
    'event_registration' => ['title'=>'Event Registration',     'sub'=>'Event Attendance Confirmation'],
    'donation'           => ['title'=>'Donation Receipt',       'sub'=>'Charitable Contribution'],
];
$docInfo  = $docTypes[$paymentType] ?? $docTypes['membership'];
$docTitle = $docInfo['title'];
$docSub   = $docInfo['sub'];

// QR code points to this same receipt URL (Google Charts API — no library needed)
$receiptUrl   = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
                . '://' . $_SERVER['HTTP_HOST']
                . $_SERVER['REQUEST_URI'];
$qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&format=png&data=' . rawurlencode($receiptUrl);

// Status badge colour
$statusColour = match($status) {
    'Verified' => '#16a34a',
    'Rejected' => '#dc2626',
    default    => '#d97706',
};

function renderError(string $msg): string {
    return '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Receipt Error</title>
    <style>body{font-family:Inter,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;background:#f7fafc;margin:0}
    .box{background:#fff;border-radius:12px;padding:2.5rem 3rem;text-align:center;box-shadow:0 4px 20px rgba(0,0,0,.1);max-width:440px}
    h2{color:#dc2626;margin-bottom:.75rem}p{color:#4a5568}</style></head>
    <body><div class="box"><h2>&#9888; Receipt Error</h2><p>' . htmlspecialchars($msg) . '</p>
    <p style="margin-top:1.5rem"><a href="events.html" style="color:#0d4593;font-weight:600">← Back to HOSU</a></p>
    </div></body></html>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Receipt <?= $receiptNum ?> — HOSU</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root {
    --primary: #e63946;
    --primary-dark: #c81d2a;
    --secondary: #0d4593;
    --secondary-dark: #072a5e;
    --text: #0f172a;
    --text-light: #475569;
    --text-muted: #94a3b8;
    --border: #e2e8f0;
    --border-light: #f1f5f9;
    --bg: #ffffff;
    --bg-page: #f8fafc;
}
*, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }
body {
    font-family: 'Inter', sans-serif;
    background: var(--bg-page);
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 1rem .75rem 2rem;
    color: var(--text);
    -webkit-font-smoothing: antialiased;
}

/* ── Scan banner ── */
.scan-warning {
    max-width: 640px;
    width: 100%;
    background: #fffbeb;
    border: 1px solid #fde68a;
    border-radius: 10px;
    padding: .55rem 1rem;
    font-size: .75rem;
    font-weight: 500;
    color: #92400e;
    margin-bottom: .6rem;
    display: flex;
    align-items: center;
    gap: .45rem;
    line-height: 1.4;
}
.scan-warning svg { flex-shrink: 0; }

/* ── Receipt card ── */
.receipt {
    background: var(--bg);
    border-radius: 16px;
    border: 1px solid var(--border);
    box-shadow: 0 1px 2px rgba(0,0,0,.04), 0 4px 24px rgba(0,0,0,.06);
    max-width: 640px;
    width: 100%;
    overflow: hidden;
    position: relative;
}

/* ── Actions dropdown (top-right) ── */
.actions-wrap {
    position: absolute;
    top: .75rem;
    right: .75rem;
    z-index: 10;
}
.actions-trigger {
    width: 32px; height: 32px;
    border-radius: 8px;
    border: 1px solid var(--border);
    background: var(--bg);
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: background .15s, box-shadow .15s;
    color: var(--text-light);
}
.actions-trigger:hover {
    background: var(--border-light);
    box-shadow: 0 2px 8px rgba(0,0,0,.08);
}
.actions-trigger svg { pointer-events: none; }
.actions-menu {
    display: none;
    position: absolute;
    top: calc(100% + 4px);
    right: 0;
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: 10px;
    box-shadow: 0 8px 24px rgba(0,0,0,.12);
    min-width: 150px;
    padding: .3rem;
    z-index: 20;
}
.actions-menu.open { display: block; }
.actions-menu button,
.actions-menu a {
    display: flex;
    align-items: center;
    gap: .5rem;
    width: 100%;
    padding: .5rem .7rem;
    border: none;
    background: none;
    font-family: inherit;
    font-size: .78rem;
    font-weight: 500;
    color: var(--text);
    cursor: pointer;
    border-radius: 7px;
    text-decoration: none;
    transition: background .12s;
}
.actions-menu button:hover,
.actions-menu a:hover { background: var(--border-light); }
.actions-menu svg { color: var(--text-muted); flex-shrink: 0; }

/* ── Header ── */
.receipt-header {
    padding: 1.15rem 1.5rem;
    padding-right: 3.25rem;
    display: flex;
    align-items: center;
    gap: .85rem;
}
.header-logo {
    height: 52px;
    width: auto;
    object-fit: contain;
    flex-shrink: 0;
}
.header-text .org-name {
    font-size: .88rem;
    font-weight: 700;
    color: var(--secondary);
    line-height: 1.25;
}
.header-text .doc-type {
    font-size: .66rem;
    font-weight: 600;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: .7px;
    margin-top: .15rem;
}

/* ── Accent line ── */
.accent-line {
    height: 3px;
    background: linear-gradient(90deg, var(--primary) 0%, var(--secondary) 100%);
}

/* ── Status bar ── */
.receipt-status {
    padding: .5rem 1.5rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: var(--border-light);
}
.status-pill {
    display: inline-flex;
    align-items: center;
    gap: .25rem;
    font-weight: 700;
    font-size: .65rem;
    text-transform: uppercase;
    letter-spacing: .5px;
    padding: .2rem .6rem;
    border-radius: 6px;
    color: #fff;
    background: <?= $statusColour ?>;
}
.receipt-date {
    font-size: .72rem;
    color: var(--text-light);
    font-weight: 500;
}

/* ── Body ── */
.receipt-body { padding: 1rem 1.5rem .75rem; }

.section-label {
    font-size: .6rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: var(--text-muted);
    margin-bottom: .55rem;
}

.info-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: .6rem 1.25rem;
    margin-bottom: .25rem;
}
.info-cell .lbl {
    font-size: .58rem;
    color: var(--text-muted);
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .4px;
    margin-bottom: .1rem;
}
.info-cell .val {
    font-size: .8rem;
    font-weight: 600;
    color: var(--text);
    word-break: break-word;
    line-height: 1.35;
}
.info-cell .val.highlight { font-weight: 700; }

/* ── QR compact ── */
.qr-section {
    padding: .85rem 1.5rem;
    border-top: 1px solid var(--border);
    display: flex;
    align-items: center;
    gap: .85rem;
}
.qr-box {
    flex-shrink: 0;
}
.qr-box img {
    width: 100px;
    height: 100px;
    aspect-ratio: 1 / 1;
    object-fit: contain;
    border-radius: 8px;
    border: 1px solid var(--border);
    padding: 4px;
    background: #fff;
    image-rendering: pixelated;
}
.qr-meta {
    flex: 1;
    min-width: 0;
}
.qr-meta .receipt-num-display {
    font-size: .78rem;
    font-weight: 700;
    color: var(--secondary);
    margin-bottom: .15rem;
}
.qr-meta .qr-hint {
    font-size: .66rem;
    color: var(--text-muted);
    line-height: 1.5;
}

/* ── Footer ── */
.receipt-footer {
    padding: .6rem 1.5rem;
    border-top: 1px solid var(--border);
    font-size: .62rem;
    color: var(--text-muted);
    text-align: center;
    line-height: 1.6;
}
.receipt-footer a { color: var(--secondary); font-weight: 600; text-decoration: none; }
.receipt-footer a:hover { text-decoration: underline; }

/* ── Print ── */
@media print {
    body { background: #fff; padding: 0; }
    .scan-warning, .actions-wrap { display: none !important; }
    .receipt { box-shadow: none; border: 1px solid #ccc; max-width: 100%; border-radius: 0; }
}

/* ── Mobile ── */
@media (max-width: 520px) {
    body { padding: .5rem .4rem 1.5rem; }
    .receipt-header { padding: .85rem 1rem; padding-right: 2.75rem; }
    .header-logo { height: 42px; }
    .info-grid { grid-template-columns: 1fr; gap: .45rem; }
    .receipt-body { padding: .85rem 1rem .6rem; }
    .qr-section { padding: .7rem 1rem; }
    .receipt-status { padding: .4rem 1rem; flex-wrap: wrap; gap: .3rem; }
    .receipt-footer { padding: .5rem 1rem; }
}
</style>
</head>
<body>

<?php if ($alreadyScanned): ?>
<div class="scan-warning">
    <svg width="16" height="16" fill="none" viewBox="0 0 24 24"><path d="M12 9v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" stroke="#d97706" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
    QR scanned on <?= htmlspecialchars($r['scanned_formatted'] ?? '') ?> &mdash; read-only mode.
</div>
<?php endif; ?>

<div class="receipt">

    <!-- Actions dropdown -->
    <div class="actions-wrap">
        <button class="actions-trigger" id="actionsBtn" aria-label="Actions">
            <svg width="16" height="16" fill="none" viewBox="0 0 24 24"><circle cx="12" cy="5" r="1.5" fill="currentColor"/><circle cx="12" cy="12" r="1.5" fill="currentColor"/><circle cx="12" cy="19" r="1.5" fill="currentColor"/></svg>
        </button>
        <div class="actions-menu" id="actionsMenu">
            <button onclick="window.print()">
                <svg width="15" height="15" fill="none" viewBox="0 0 24 24"><path d="M6 9V2h12v7M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><rect x="6" y="14" width="12" height="8" rx="1" stroke="currentColor" stroke-width="1.5"/></svg>
                Print
            </button>
            <button onclick="downloadReceipt()">
                <svg width="15" height="15" fill="none" viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4M7 10l5 5 5-5M12 15V3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                Download
            </button>
            <button onclick="shareReceipt()">
                <svg width="15" height="15" fill="none" viewBox="0 0 24 24"><circle cx="18" cy="5" r="3" stroke="currentColor" stroke-width="1.5"/><circle cx="6" cy="12" r="3" stroke="currentColor" stroke-width="1.5"/><circle cx="18" cy="19" r="3" stroke="currentColor" stroke-width="1.5"/><path d="M8.59 13.51l6.83 3.98M15.41 6.51l-6.82 3.98" stroke="currentColor" stroke-width="1.5"/></svg>
                Share
            </button>
            <a href="membership.html">
                <svg width="15" height="15" fill="none" viewBox="0 0 24 24"><path d="M19 12H5M12 19l-7-7 7-7" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                Back
            </a>
        </div>
    </div>

    <!-- Header -->
    <div class="receipt-header">
        <img class="header-logo" src="img/logo2.png" alt="HOSU Logo">
        <div class="header-text">
            <div class="org-name">Hematology &amp; Oncology Society of Uganda</div>
            <div class="doc-type"><?= htmlspecialchars($docTitle) ?></div>
        </div>
    </div>

    <div class="accent-line"></div>

    <!-- Status -->
    <div class="receipt-status">
        <span class="status-pill">
            <?= $status === 'Verified' ? '&#10003;' : ($status === 'Rejected' ? '&#10007;' : '&#9711;') ?>
            <?= $status ?>
        </span>
        <span class="receipt-date"><?= $paidDate ?></span>
    </div>

    <!-- Body -->
    <div class="receipt-body">
        <div class="section-label"><?= $paymentType === 'event_registration' ? 'Registrant Details' : 'Member Details' ?></div>
        <div class="info-grid">
            <div class="info-cell">
                <div class="lbl">Full Name</div>
                <div class="val"><?= $memberName ?></div>
            </div>
            <div class="info-cell">
                <div class="lbl">Email</div>
                <div class="val"><?= $memberEmail ?></div>
            </div>
            <div class="info-cell">
                <div class="lbl">Phone</div>
                <div class="val"><?= $memberPhone ?: '—' ?></div>
            </div>
            <div class="info-cell">
                <div class="lbl">Profession</div>
                <div class="val"><?= $profession ?: '—' ?></div>
            </div>
            <?php if ($institution): ?>
            <div class="info-cell">
                <div class="lbl">Institution</div>
                <div class="val"><?= $institution ?></div>
            </div>
            <?php endif; ?>
            <?php if ($paymentType === 'membership'): ?>
            <div class="info-cell">
                <div class="lbl">Membership Plan</div>
                <div class="val"><?= $periodLabel ?> Membership</div>
            </div>
            <div class="info-cell">
                <div class="lbl">Valid Until</div>
                <div class="val highlight" style="color:<?= $memPeriod === 'lifetime' ? '#0d4593' : '#16a34a' ?>"><?= $memExpiry ?></div>
            </div>
            <?php elseif ($paymentType === 'event_registration' && $eventTitle): ?>
            <div class="info-cell" style="grid-column:1/-1;">
                <div class="lbl">Event</div>
                <div class="val"><?= $eventTitle ?><?= $eventDate ? ' &middot; ' . $eventDate : '' ?></div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- QR -->
    <div class="qr-section">
        <div class="qr-box">
            <img src="<?= $qrUrl ?>" alt="QR Code — Receipt <?= $receiptNum ?>">
        </div>
        <div class="qr-meta">
            <div class="receipt-num-display"><?= $receiptNum ?></div>
            <div class="qr-hint">Scan QR to verify this receipt online</div>
        </div>
    </div>

    <!-- Footer -->
    <div class="receipt-footer">
        Official receipt &mdash; Hematology &amp; Oncology Society of Uganda
        &middot; <a href="mailto:info@hosu.org">info@hosu.org</a>
    </div>

</div>

<script>
// Dropdown toggle
const btn = document.getElementById('actionsBtn');
const menu = document.getElementById('actionsMenu');
btn.addEventListener('click', function(e) {
    e.stopPropagation();
    menu.classList.toggle('open');
});
document.addEventListener('click', function() { menu.classList.remove('open'); });

// Download as image (uses print as fallback)
function downloadReceipt() {
    menu.classList.remove('open');
    window.print();
}

// Share via Web Share API or fallback to clipboard
function shareReceipt() {
    menu.classList.remove('open');
    const url = window.location.href;
    if (navigator.share) {
        navigator.share({ title: 'HOSU Receipt <?= addslashes($receiptNum) ?>', url: url });
    } else if (navigator.clipboard) {
        navigator.clipboard.writeText(url).then(function() {
            const t = btn.cloneNode(false);
            btn.style.background = '#dcfce7';
            setTimeout(function() { btn.style.background = ''; }, 1200);
        });
    }
}
</script>
</body>
</html>
