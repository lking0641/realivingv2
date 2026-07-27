<?php
// view_client.php — unified profile for clients converted from
// concept_inquiries, contact, project_inquiries, OR appointments
include $includes ['mainbody'];

require_role(['sales', 'superadmin']);


$admin_id = $_SESSION['admin_id'];
$admin_role = $_SESSION['role'] ?? '';

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: concept_inquiries_clients.php");
    exit();
}

$client_id = intval($_GET['id']);

// ── Fetch client + assigned sales ────────────────────────────────────────────
$stmt = $conn->prepare(
    "SELECT ui.*, acc.full_name AS assigned_sales
     FROM user_info ui
     LEFT JOIN account acc ON ui.accountaid_fk = acc.id
     WHERE ui.id = ?"
);
$stmt->bind_param("i", $client_id);
$stmt->execute();
$client = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$client) {
    header("Location: concept_inquiries_clients.php");
    exit();
}

// ── Try concept_inquiries ────────────────────────────────────────────────────
$concept_inq = null;
$ci_stmt = $conn->prepare(
    "SELECT ci.*, cs.title AS style_title, cs.id AS style_id
     FROM concept_inquiries ci
     LEFT JOIN concept_styles cs ON ci.concept_id = cs.id
     WHERE ci.client_id = ?
     ORDER BY ci.updated_at DESC LIMIT 1"
);
$ci_stmt->bind_param("i", $client_id);
$ci_stmt->execute();
$concept_inq = $ci_stmt->get_result()->fetch_assoc();
$ci_stmt->close();

// ── Try contact inquiry ──────────────────────────────────────────────────────
$contact_inq = null;
$ct_stmt = $conn->prepare(
    "SELECT c.*,
            (SELECT COUNT(*) FROM contact_inquiry_items WHERE contact_id = c.id) AS items_count
     FROM contact c
     WHERE c.client_id = ?
     ORDER BY c.updated_at DESC LIMIT 1"
);
$ct_stmt->bind_param("i", $client_id);
$ct_stmt->execute();
$contact_inq = $ct_stmt->get_result()->fetch_assoc();
$ct_stmt->close();

// ── Try project inquiry ──────────────────────────────────────────────────────
$project_inq = null;
$pi_stmt = $conn->prepare(
    "SELECT pi.*, p.title AS project_title, p.address AS project_address, p.id AS project_page_id
     FROM project_inquiries pi
     LEFT JOIN project p ON pi.project_id = p.id
     WHERE pi.client_id = ?
     ORDER BY pi.updated_at DESC LIMIT 1"
);
$pi_stmt->bind_param("i", $client_id);
$pi_stmt->execute();
$project_inq = $pi_stmt->get_result()->fetch_assoc();
$pi_stmt->close();

// ── Try appointment (NEW) ─────────────────────────────────────────────────────
$appointment_inq = null;
if (!empty($client['appointment_id_fk'])) {
    $apt_stmt = $conn->prepare(
        "SELECT a.*, acc.full_name AS sales_name
         FROM appointments a
         LEFT JOIN account acc ON a.assigned_to = acc.id
         WHERE a.appointment_id = ?"
    );
    $apt_stmt->bind_param("i", $client['appointment_id_fk']);
    $apt_stmt->execute();
    $appointment_inq = $apt_stmt->get_result()->fetch_assoc();
    $apt_stmt->close();
}

// ── Contact inquiry items ────────────────────────────────────────────────────
$contact_items = [];
if ($contact_inq && $contact_inq['inquiry_type'] === 'contact_with_items' && $contact_inq['items_count'] > 0) {
    $items_stmt = $conn->prepare(
        "SELECT cii.*, i.item_name, i.item_code, i.item_image_path, cii.item_id
         FROM contact_inquiry_items cii
         LEFT JOIN items i ON cii.item_id = i.id
         WHERE cii.contact_id = ?"
    );
    $items_stmt->bind_param("i", $contact_inq['id']);
    $items_stmt->execute();
    $r = $items_stmt->get_result();
    while ($row = $r->fetch_assoc())
        $contact_items[] = $row;
    $items_stmt->close();
}

// ── Determine source type — appointment takes priority if it exists ───────────
$source_type = 'none';
$source_inq = null;
if ($appointment_inq) {
    $source_type = 'appointment';
    $source_inq = $appointment_inq;
} elseif ($concept_inq) {
    $source_type = 'concept';
    $source_inq = $concept_inq;
} elseif ($contact_inq) {
    $source_type = 'contact';
    $source_inq = $contact_inq;
} elseif ($project_inq) {
    $source_type = 'project';
    $source_inq = $project_inq;
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function val($v, $fallback = '—')
{
    return (!empty($v)) ? htmlspecialchars($v) : $fallback;
}
function initials($name)
{
    $parts = explode(' ', trim($name));
    $i = strtoupper(substr($parts[0], 0, 1));
    if (count($parts) > 1)
        $i .= strtoupper(substr(end($parts), 0, 1));
    return $i;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Client Profile — <?php echo val($client['clientname']); ?></title>
    <link
        href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=Syne:wght@600;700;800&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --bg: #f4f1ee;
            --surface: #ffffff;
            --surface2: #faf8f6;
            --border: #e8e2db;
            --text: #1a1208;
            --text-muted: #7a6f65;
            --brand: #3b1f0f;
            --brand-mid: #7a4030;
            --brand-light: #c9956a;
            --accent: #e8c49a;
            --success: #2d6a4f;
            --success-bg: #d8f3dc;
            --warning: #7d5a00;
            --warning-bg: #fff3cd;
            --danger: #9b1c1c;
            --danger-bg: #fee2e2;
            --info: #1e3a8a;
            --info-bg: #dbeafe;
            --purple: #4f46e5;
            --purple-bg: #ede9fe;
            --teal: #0f766e;
            --teal-bg: #ccfbf1;
            --orange: #c2410c;
            --orange-bg: #fff7ed;
            --radius: 14px;
            --radius-sm: 8px;
            --shadow: 0 2px 12px rgba(59, 31, 15, .08);
            --shadow-md: 0 6px 24px rgba(59, 31, 15, .12);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'DM Sans', sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
        }

        .app-wrap {
            max-width: 1100px;
            margin: 0 auto;
            padding: 28px 24px;
        }

        /* TOP BAR */
        .top-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: var(--brand);
            border-radius: var(--radius);
            padding: 18px 28px;
            margin-bottom: 28px;
            box-shadow: var(--shadow-md);
        }

        .top-bar-title {
            color: rgba(255, 255, 255, .9);
            font-size: 15px;
            font-weight: 500;
        }

        .top-bar-sub {
            color: rgba(255, 255, 255, .5);
            font-size: 13px;
            margin-top: 2px;
        }

        .nav-btn {
            padding: 8px 18px;
            border-radius: 30px;
            font-size: 13.5px;
            font-weight: 500;
            color: rgba(255, 255, 255, .75);
            border: none;
            background: rgba(255, 255, 255, .12);
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 7px;
            transition: all .2s;
        }

        .nav-btn:hover {
            background: rgba(255, 255, 255, .22);
            color: #fff;
        }

        /* SOURCE BANNER */
        .source-banner {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 18px;
            border-radius: var(--radius-sm);
            margin-bottom: 20px;
            font-size: 13px;
            font-weight: 600;
            border: 1.5px solid;
        }

        .source-banner.from-concept {
            background: var(--purple-bg);
            color: var(--purple);
            border-color: #c4b5fd;
        }

        .source-banner.from-contact {
            background: var(--teal-bg);
            color: var(--teal);
            border-color: #5eead4;
        }

        .source-banner.from-project {
            background: var(--info-bg);
            color: var(--info);
            border-color: #93c5fd;
        }

        .source-banner.from-appointment {
            background: var(--orange-bg);
            color: var(--orange);
            border-color: #fdba74;
        }

        /* PROFILE HERO */
        .profile-hero {
            background: var(--surface);
            border-radius: var(--radius);
            border: 1.5px solid var(--border);
            box-shadow: var(--shadow);
            padding: 28px 32px;
            margin-bottom: 22px;
            display: flex;
            align-items: flex-start;
            gap: 24px;
        }

        .avatar {
            width: 72px;
            height: 72px;
            border-radius: 50%;
            background: var(--brand);
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Syne', sans-serif;
            font-size: 22px;
            font-weight: 700;
            color: var(--accent);
            flex-shrink: 0;
            border: 3px solid var(--accent);
        }

        .hero-info {
            flex: 1;
        }

        .hero-name {
            font-family: 'Syne', sans-serif;
            font-size: 24px;
            font-weight: 700;
        }

        .hero-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 8px;
            font-size: 13px;
            color: var(--text-muted);
        }

        .hero-meta span {
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .hero-badges {
            display: flex;
            flex-wrap: wrap;
            gap: 7px;
            margin-top: 12px;
        }

        /* BADGES */
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 11.5px;
            font-weight: 600;
            letter-spacing: .3px;
            text-transform: uppercase;
        }

        .badge-brand {
            background: #fdf2e9;
            color: var(--brand-mid);
            border: 1px solid var(--accent);
        }

        .badge-success {
            background: var(--success-bg);
            color: var(--success);
        }

        .badge-info {
            background: var(--info-bg);
            color: var(--info);
        }

        .badge-purple {
            background: var(--purple-bg);
            color: var(--purple);
        }

        .badge-warning {
            background: var(--warning-bg);
            color: var(--warning);
        }

        .badge-teal {
            background: var(--teal-bg);
            color: var(--teal);
        }

        .badge-orange {
            background: var(--orange-bg);
            color: var(--orange);
            border: 1px solid #fdba74;
        }

        /* REF TAG */
        .ref-tag {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 7px 14px;
            background: #fdf9f5;
            border: 1.5px solid var(--accent);
            border-radius: var(--radius-sm);
            font-family: 'Courier New', monospace;
            font-size: 14px;
            color: var(--brand);
            font-weight: 700;
            letter-spacing: .5px;
        }

        .ref-tag i {
            font-size: 13px;
            color: var(--brand-light);
        }

        /* LAYOUT */
        .profile-grid {
            display: grid;
            grid-template-columns: 1fr 340px;
            gap: 20px;
        }

        /* CARDS */
        .section-card {
            background: var(--surface);
            border-radius: var(--radius);
            border: 1.5px solid var(--border);
            box-shadow: var(--shadow);
            overflow: hidden;
            margin-bottom: 18px;
        }

        .section-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 15px 22px;
            border-bottom: 1.5px solid var(--border);
            background: var(--surface2);
        }

        .section-head h3 {
            font-family: 'Syne', sans-serif;
            font-size: 13.5px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 8px;
            text-transform: uppercase;
            letter-spacing: .4px;
            color: var(--text-muted);
        }

        .section-body {
            padding: 20px 22px;
        }

        /* INFO GRID */
        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px;
        }

        .info-item label {
            display: block;
            font-size: 11px;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: .5px;
            margin-bottom: 4px;
        }

        .info-item p {
            font-size: 14px;
            font-weight: 500;
            color: var(--text);
            line-height: 1.5;
        }

        .info-item.full {
            grid-column: span 2;
        }

        .divider {
            height: 1px;
            background: var(--border);
            margin: 18px 0;
        }

        /* TIMELINE */
        .timeline-item {
            display: flex;
            gap: 14px;
            padding: 12px 0;
            border-bottom: 1px solid var(--border);
        }

        .timeline-item:last-child {
            border-bottom: none;
        }

        .tl-dot {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background: var(--success-bg);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            margin-top: 2px;
            font-size: 13px;
            color: var(--success);
        }

        .tl-title {
            font-size: 13.5px;
            font-weight: 600;
        }

        .tl-sub {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 3px;
        }

        /* ORIGIN CARD */
        .origin-card {
            border-radius: var(--radius-sm);
            padding: 16px 18px;
        }

        .origin-card.concept {
            background: var(--purple-bg);
            border: 1.5px solid #c4b5fd;
        }

        .origin-card.contact {
            background: var(--teal-bg);
            border: 1.5px solid #5eead4;
        }

        .origin-card.project {
            background: var(--info-bg);
            border: 1.5px solid #93c5fd;
        }

        .origin-card.appointment {
            background: var(--orange-bg);
            border: 1.5px solid #fdba74;
        }

        .origin-card label {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .4px;
            display: block;
            margin-bottom: 8px;
        }

        .origin-card.concept label {
            color: var(--purple);
        }

        .origin-card.contact label {
            color: var(--teal);
        }

        .origin-card.project label {
            color: var(--info);
        }

        .origin-card.appointment label {
            color: var(--orange);
        }

        /* APPOINTMENT DETAIL ROWS */
        .apt-detail-row {
            display: flex;
            align-items: flex-start;
            gap: 8px;
            padding: 5px 0;
            font-size: 12.5px;
            color: var(--orange);
            border-bottom: 1px solid rgba(253, 186, 116, .35);
        }

        .apt-detail-row:last-child {
            border-bottom: none;
        }

        .apt-detail-row strong {
            min-width: 80px;
            font-weight: 700;
            color: var(--orange);
            flex-shrink: 0;
        }

        /* PROJECT INQUIRY DETAIL ROWS */
        .proj-detail-row {
            display: flex;
            align-items: flex-start;
            gap: 8px;
            padding: 5px 0;
            font-size: 12.5px;
            color: #1e3a8a;
            border-bottom: 1px solid rgba(147, 197, 253, .35);
        }

        .proj-detail-row:last-child {
            border-bottom: none;
        }

        .proj-detail-row strong {
            min-width: 80px;
            font-weight: 700;
            color: #1e40af;
            flex-shrink: 0;
        }

        /* PRODUCT ITEMS */
        .product-item {
            display: flex;
            gap: 12px;
            padding: 10px;
            background: rgba(255, 255, 255, .6);
            border-radius: var(--radius-sm);
            border: 1px solid #5eead4;
            margin-bottom: 8px;
        }

        .product-item:last-child {
            margin-bottom: 0;
        }

        .product-item img {
            width: 52px;
            height: 52px;
            object-fit: cover;
            border-radius: 6px;
            background: #fff;
            flex-shrink: 0;
        }

        /* QUICK ACTIONS */
        .action-link {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 11px 14px;
            background: var(--surface2);
            border: 1.5px solid var(--border);
            border-radius: var(--radius-sm);
            text-decoration: none;
            color: var(--text);
            font-size: 13.5px;
            font-weight: 500;
            transition: all .18s;
            margin-bottom: 8px;
        }

        .action-link:last-child {
            margin-bottom: 0;
        }

        .action-link i {
            width: 18px;
            text-align: center;
            color: var(--text-muted);
        }

        .action-link:hover {
            border-color: var(--brand-light);
            background: #fdf9f5;
            color: var(--brand);
        }

        .action-link:hover i {
            color: var(--brand-light);
        }

        /* APPOINTMENT STAT PILLS */
        .apt-stat-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border-radius: var(--radius-sm);
            font-size: 12.5px;
            font-weight: 600;
            background: rgba(255, 255, 255, .6);
            border: 1px solid rgba(253, 186, 116, .5);
            color: var(--orange);
            margin-bottom: 6px;
        }

        @media print {

            .top-bar,
            .action-link {
                display: none;
            }

            body {
                background: #fff;
            }

            .app-wrap {
                padding: 0;
            }

            .profile-grid {
                grid-template-columns: 1fr;
            }
        }

        @media(max-width:900px) {
            .profile-grid {
                grid-template-columns: 1fr;
            }

            .info-grid {
                grid-template-columns: 1fr;
            }

            .info-item.full {
                grid-column: span 1;
            }
        }

        @media(max-width:600px) {
            .profile-hero {
                flex-direction: column;
                gap: 16px;
            }
        }
    </style>
</head>

<body>
    <div class="app-wrap">

        <!-- TOP BAR -->
        <div class="top-bar">
            <div style="display:flex;align-items:center;gap:14px;">
                <div>
                    <div class="top-bar-title"><?php echo val($client['clientname']); ?></div>
                    <div class="top-bar-sub">
                        Client Profile &nbsp;·&nbsp; <?php echo val($client['reference_number']); ?>
                        &nbsp;·&nbsp;
                        <?php if ($source_type === 'appointment'): ?>
                            <i class="fas fa-calendar-check"></i> From Appointment
                        <?php elseif ($source_type === 'concept'): ?>
                            <i class="fas fa-palette"></i> From Concept Inquiry
                        <?php elseif ($source_type === 'contact'): ?>
                            <i class="fas fa-envelope"></i> From Contact Inquiry
                        <?php elseif ($source_type === 'project'): ?>
                            <i class="fas fa-building"></i> From Project Inquiry
                        <?php else: ?>
                            Direct Entry
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div style="display:flex;gap:10px;">
                <?php if ($source_type === 'appointment'): ?>
                    <a href="appointment-clients" class="nav-btn"><i class="fas fa-arrow-left"></i> Back</a>
                    <a href="appointment-dashboard" class="nav-btn"><i class="fas fa-chart-pie"></i> Dashboard</a>
                <?php elseif ($source_type === 'concept'): ?>
                    <a href="concept_inquiries_clients.php" class="nav-btn"><i class="fas fa-arrow-left"></i> Back</a>
                    <a href="concept_inquiries_dashboard.php" class="nav-btn"><i class="fas fa-chart-pie"></i> Dashboard</a>
                <?php elseif ($source_type === 'contact'): ?>
                    <a href="contact_clients.php" class="nav-btn"><i class="fas fa-arrow-left"></i> Back</a>
                    <a href="contact_dashboard.php" class="nav-btn"><i class="fas fa-chart-pie"></i> Dashboard</a>
                <?php elseif ($source_type === 'project'): ?>
                    <a href="project_inquiries_clients.php" class="nav-btn"><i class="fas fa-arrow-left"></i> Back</a>
                    <a href="project_inquiries_dashboard.php" class="nav-btn"><i class="fas fa-chart-pie"></i> Dashboard</a>
                <?php else: ?>
                    <a href="javascript:history.back()" class="nav-btn"><i class="fas fa-arrow-left"></i> Back</a>
                <?php endif; ?>
                <button onclick="window.print()" class="nav-btn"><i class="fas fa-print"></i> Print</button>
            </div>
        </div>

        <!-- SOURCE BANNER -->
        <?php if ($source_type === 'appointment' && $appointment_inq): ?>
            <div class="source-banner from-appointment">
                <i class="fas fa-calendar-check"></i>
                Converted from an <strong>Appointment</strong>
                &nbsp;·&nbsp; <i class="fas fa-briefcase"></i>
                <strong><?php echo htmlspecialchars($appointment_inq['service_type']); ?></strong>
                &nbsp;·&nbsp; <i class="fas fa-calendar"></i>
                <?php echo date('M d, Y', strtotime($appointment_inq['preferred_date'])); ?>
                <?php echo date('g:i A', strtotime($appointment_inq['preferred_time'])); ?>
            </div>
        <?php elseif ($source_type === 'concept'): ?>
            <div class="source-banner from-concept">
                <i class="fas fa-palette"></i>
                Converted from a <strong>Concept Inquiry</strong>
                <?php if (!empty($concept_inq['style_title'])): ?>
                    &nbsp;·&nbsp; Style: <strong><?php echo htmlspecialchars($concept_inq['style_title']); ?></strong>
                <?php endif; ?>
            </div>
        <?php elseif ($source_type === 'contact'): ?>
            <div class="source-banner from-contact">
                <i class="fas fa-envelope"></i>
                Converted from a <strong>Contact Inquiry</strong>
                <?php if ($contact_inq['inquiry_type'] === 'contact_with_items'): ?>
                    &nbsp;·&nbsp; <i class="fas fa-box"></i> Product Inquiry
                    (<?php echo $contact_inq['items_count']; ?> item<?php echo $contact_inq['items_count'] != 1 ? 's' : ''; ?>)
                <?php endif; ?>
            </div>
        <?php elseif ($source_type === 'project'): ?>
            <div class="source-banner from-project">
                <i class="fas fa-building"></i>
                Converted from a <strong>Project Inquiry</strong>
                <?php if (!empty($project_inq['project_title'])): ?>
                    &nbsp;·&nbsp; Project: <strong><?php echo htmlspecialchars($project_inq['project_title']); ?></strong>
                <?php endif; ?>
                <?php if (!empty($project_inq['inquiry_type'])): ?>
                    &nbsp;·&nbsp; <?php echo htmlspecialchars($project_inq['inquiry_type']); ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- PROFILE HERO -->
        <div class="profile-hero">
            <div class="avatar"><?php echo initials($client['clientname']); ?></div>
            <div class="hero-info">
                <div class="hero-name"><?php echo val($client['clientname']); ?></div>
                <div class="hero-meta">
                    <?php if (!empty($client['email'])): ?>
                        <span><i class="fas fa-envelope"></i> <?php echo val($client['email']); ?></span>
                    <?php endif; ?>
                    <?php if (!empty($client['contact'])): ?>
                        <span><i class="fas fa-phone"></i> <?php echo val($client['contact']); ?></span>
                    <?php endif; ?>
                    <?php if (!empty($client['country'])): ?>
                        <span><i class="fas fa-globe"></i> <?php echo val($client['country']); ?></span>
                    <?php endif; ?>
                    <?php if (!empty($client['gender'])): ?>
                        <span><i class="fas fa-user"></i> <?php echo val($client['gender']); ?></span>
                    <?php endif; ?>
                </div>
                <div class="hero-badges">
                    <span class="badge badge-brand"><i class="fas fa-building"></i>
                        <?php echo val($client['client_type']); ?></span>
                    <span class="badge badge-info"><?php echo val($client['client_class']); ?></span>
                    <?php if (!empty($client['status'])): ?>
                        <span class="badge badge-success"><i class="fas fa-check-circle"></i>
                            <?php echo val($client['status']); ?></span>
                    <?php endif; ?>
                    <?php if (!empty($client['business_type'])): ?>
                        <span class="badge badge-purple"><i class="fas fa-briefcase"></i>
                            <?php echo val($client['business_type']); ?></span>
                    <?php endif; ?>
                    <?php if ($source_type === 'appointment'): ?>
                        <span class="badge badge-orange"><i class="fas fa-calendar-check"></i> Appointment</span>
                        <?php if (!empty($appointment_inq['service_type'])): ?>
                            <span class="badge badge-orange"><i class="fas fa-briefcase"></i>
                                <?php echo htmlspecialchars($appointment_inq['service_type']); ?></span>
                        <?php endif; ?>
                    <?php elseif ($source_type === 'concept'): ?>
                        <span class="badge badge-purple"><i class="fas fa-palette"></i> Concept</span>
                    <?php elseif ($source_type === 'contact'): ?>
                        <span class="badge badge-teal">
                            <i
                                class="fas fa-<?php echo ($contact_inq['inquiry_type'] === 'contact_with_items') ? 'box' : 'envelope'; ?>"></i>
                            <?php echo ($contact_inq['inquiry_type'] === 'contact_with_items') ? 'Product Inquiry' : 'Contact'; ?>
                        </span>
                    <?php elseif ($source_type === 'project'): ?>
                        <span class="badge badge-info"><i class="fas fa-building"></i> Project Inquiry</span>
                        <?php if (!empty($project_inq['inquiry_type'])): ?>
                            <span
                                class="badge badge-purple"><?php echo htmlspecialchars($project_inq['inquiry_type']); ?></span>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
            <?php if (!empty($client['reference_number'])): ?>
                <div style="flex-shrink:0;">
                    <div class="ref-tag"><i class="fas fa-barcode"></i><?php echo val($client['reference_number']); ?></div>
                </div>
            <?php endif; ?>
        </div>

        <!-- MAIN GRID -->
        <div class="profile-grid">

            <!-- LEFT COLUMN -->
            <div>

                <!-- Contact Information -->
                <div class="section-card">
                    <div class="section-head">
                        <h3><i class="fas fa-user" style="color:var(--brand-light);"></i> Contact Information</h3>
                    </div>
                    <div class="section-body">
                        <div class="info-grid">
                            <div class="info-item"><label>Full Name</label>
                                <p><?php echo val($client['clientname']); ?></p>
                            </div>
                            <div class="info-item"><label>Gender</label>
                                <p><?php echo val($client['gender']); ?></p>
                            </div>
                            <div class="info-item"><label>Email Address</label>
                                <p><?php echo val($client['email']); ?></p>
                            </div>
                            <div class="info-item"><label>Phone / Contact</label>
                                <p><?php echo val($client['contact']); ?></p>
                            </div>
                            <div class="info-item"><label>Country</label>
                                <p><?php echo val($client['country']); ?></p>
                            </div>
                            <?php if (!empty($client['assigned_sales'])): ?>
                                <div class="info-item"><label>Assigned Sales</label>
                                    <p><?php echo val($client['assigned_sales']); ?></p>
                                </div>
                            <?php endif; ?>
                            <div class="info-item full"><label>Address</label>
                                <p><?php echo val($client['address']); ?></p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Project Details -->
                <div class="section-card">
                    <div class="section-head">
                        <h3><i class="fas fa-drafting-compass" style="color:var(--brand-light);"></i> Project Details
                        </h3>
                    </div>
                    <div class="section-body">
                        <div class="info-grid">
                            <div class="info-item"><label>Project Name</label>
                                <p><?php echo val($client['nameproject']); ?></p>
                            </div>
                            <div class="info-item"><label>Business Type</label>
                                <p><?php echo val($client['business_type']); ?></p>
                            </div>
                            <div class="info-item full"><label>Project Scope</label>
                                <p><?php echo val($client['project_scope']); ?></p>
                            </div>
                            <div class="info-item full"><label>Scope of Work</label>
                                <p><?php echo nl2br(val($client['scope_of_work'])); ?></p>
                            </div>
                        </div>
                        <div class="divider"></div>
                        <div class="info-grid">
                            <div class="info-item"><label>State of House</label>
                                <p><?php echo val($client['house_state']); ?></p>
                            </div>
                            <div class="info-item">
                                <label>Permit Required</label>
                                <p>
                                    <?php
                                    $permit = $client['permit_required'] ?? '';
                                    if ($permit === 'Yes')
                                        echo '<span class="badge badge-warning"><i class="fas fa-exclamation-triangle"></i> Yes — Required</span>';
                                    elseif ($permit === 'No')
                                        echo '<span class="badge badge-success"><i class="fas fa-check"></i> Not Required</span>';
                                    elseif ($permit === 'Unsure')
                                        echo '<span class="badge badge-info"><i class="fas fa-question-circle"></i> Needs Assessment</span>';
                                    else
                                        echo '—';
                                    ?>
                                </p>
                            </div>
                            <div class="info-item">
                                <label>Target Move-in Date</label>
                                <p><?php echo (!empty($client['target_movein_date'])) ? date('F j, Y', strtotime($client['target_movein_date'])) : '<span style="color:var(--text-muted);font-style:italic;">Not determined</span>'; ?>
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Classification -->
                <div class="section-card">
                    <div class="section-head">
                        <h3><i class="fas fa-tags" style="color:var(--brand-light);"></i> Classification</h3>
                    </div>
                    <div class="section-body">
                        <div class="info-grid">
                            <div class="info-item"><label>Client Type</label>
                                <p><?php echo val($client['client_type']); ?></p>
                            </div>
                            <div class="info-item"><label>Client Class</label>
                                <p><?php echo val($client['client_class']); ?></p>
                            </div>
                            <div class="info-item"><label>Status</label>
                                <p><span class="badge badge-success"><?php echo val($client['status']); ?></span></p>
                            </div>
                            <div class="info-item"><label>Update Status</label>
                                <p><?php echo val($client['updatestatus'] ?? null); ?></p>
                            </div>
                        </div>
                    </div>
                </div>

            </div><!-- /left -->

            <!-- RIGHT COLUMN -->
            <div>

                <!-- Timeline -->
                <div class="section-card" style="margin-bottom:18px;">
                    <div class="section-head">
                        <h3><i class="fas fa-history" style="color:var(--brand-light);"></i> Timeline</h3>
                    </div>
                    <div class="section-body" style="padding:14px 20px;">
                        <?php if ($source_inq && !empty($source_inq['created_at'])): ?>
                            <div class="timeline-item">
                                <div class="tl-dot" style="background:<?php
                                if ($source_type === 'appointment')
                                    echo 'var(--orange-bg);color:var(--orange)';
                                elseif ($source_type === 'concept')
                                    echo 'var(--purple-bg);color:var(--purple)';
                                elseif ($source_type === 'contact')
                                    echo 'var(--teal-bg);color:var(--teal)';
                                else
                                    echo 'var(--info-bg);color:var(--info)';
                                ?>;">
                                    <i class="fas fa-<?php
                                    if ($source_type === 'appointment')
                                        echo 'calendar-check';
                                    elseif ($source_type === 'concept')
                                        echo 'palette';
                                    elseif ($source_type === 'contact')
                                        echo 'envelope';
                                    else
                                        echo 'building';
                                    ?>"></i>
                                </div>
                                <div>
                                    <div class="tl-title">
                                        <?php
                                        if ($source_type === 'appointment')
                                            echo 'Appointment Booked';
                                        elseif ($source_type === 'concept')
                                            echo 'Inquiry Submitted';
                                        elseif ($source_type === 'contact')
                                            echo 'Contact Submitted';
                                        else
                                            echo 'Project Inquiry Submitted';
                                        ?>
                                    </div>
                                    <div class="tl-sub">
                                        <?php echo date('M d, Y g:i A', strtotime($source_inq['created_at'])); ?></div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if ($source_type === 'appointment' && !empty($appointment_inq['preferred_date'])): ?>
                            <div class="timeline-item">
                                <div class="tl-dot" style="background:var(--orange-bg);color:var(--orange);">
                                    <i class="fas fa-calendar-day"></i>
                                </div>
                                <div>
                                    <div class="tl-title">Appointment Scheduled</div>
                                    <div class="tl-sub">
                                        <?php echo date('M d, Y', strtotime($appointment_inq['preferred_date'])); ?>
                                        at <?php echo date('g:i A', strtotime($appointment_inq['preferred_time'])); ?>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if ($source_inq && !empty($source_inq['updated_at'])): ?>
                            <div class="timeline-item">
                                <div class="tl-dot" style="background:var(--info-bg);color:var(--info);">
                                    <i class="fas fa-check-double"></i>
                                </div>
                                <div>
                                    <div class="tl-title">Converted to Client</div>
                                    <div class="tl-sub">
                                        <?php echo date('M d, Y g:i A', strtotime($source_inq['updated_at'])); ?></div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($client['update_time'])): ?>
                            <div class="timeline-item">
                                <div class="tl-dot"><i class="fas fa-user-plus"></i></div>
                                <div>
                                    <div class="tl-title">Client Record Created</div>
                                    <div class="tl-sub">
                                        <?php echo date('M d, Y g:i A', strtotime($client['update_time'])); ?></div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Origin Card — changes based on source type -->

                <?php if ($source_type === 'appointment' && $appointment_inq): ?>
                    <!-- ── APPOINTMENT ORIGIN ─────────────────────────────────────── -->
                    <div class="section-card" style="margin-bottom:18px;">
                        <div class="section-head">
                            <h3><i class="fas fa-calendar-check" style="color:var(--orange);"></i> Appointment Origin</h3>
                        </div>
                        <div class="section-body" style="padding:14px 20px;">
                            <div class="origin-card appointment">
                                <label><i class="fas fa-info-circle"></i> Source Appointment</label>

                                <!-- Service & status pills -->
                                <div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:10px;">
                                    <div class="apt-stat-pill">
                                        <i class="fas fa-briefcase"></i>
                                        <?php echo htmlspecialchars($appointment_inq['service_type']); ?>
                                        <?php if ($appointment_inq['service_type'] === 'Other' && !empty($appointment_inq['other_service'])): ?>
                                            — <?php echo htmlspecialchars($appointment_inq['other_service']); ?>
                                        <?php endif; ?>
                                    </div>
                                    <div class="apt-stat-pill">
                                        <i class="fas fa-calendar"></i>
                                        <?php echo date('M d, Y', strtotime($appointment_inq['preferred_date'])); ?>
                                    </div>
                                    <div class="apt-stat-pill">
                                        <i class="fas fa-clock"></i>
                                        <?php echo date('g:i A', strtotime($appointment_inq['preferred_time'])); ?>
                                    </div>
                                </div>

                                <?php if (!empty($appointment_inq['inquiry_type'])): ?>
                                    <div class="apt-detail-row">
                                        <strong><i class="fas fa-tag"></i> Type</strong>
                                        <span><?php echo htmlspecialchars($appointment_inq['inquiry_type']); ?></span>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($appointment_inq['email'])): ?>
                                    <div class="apt-detail-row">
                                        <strong><i class="fas fa-envelope"></i> Email</strong>
                                        <span><?php echo htmlspecialchars($appointment_inq['email']); ?></span>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($appointment_inq['phone'])): ?>
                                    <div class="apt-detail-row">
                                        <strong><i class="fas fa-phone"></i> Phone</strong>
                                        <span><?php echo htmlspecialchars(($appointment_inq['country_code'] ?? '') . ' ' . $appointment_inq['phone']); ?></span>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($appointment_inq['sales_name'])): ?>
                                    <div class="apt-detail-row">
                                        <strong><i class="fas fa-user"></i> Sales Rep</strong>
                                        <span><?php echo htmlspecialchars($appointment_inq['sales_name']); ?></span>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($appointment_inq['notes'])): ?>
                                    <div
                                        style="margin-top:10px;font-size:12.5px;color:var(--orange);line-height:1.5;padding:8px;background:rgba(255,255,255,.55);border-radius:6px;">
                                        <strong style="display:block;margin-bottom:4px;"><i class="fas fa-sticky-note"></i>
                                            Notes</strong>
                                        <?php echo htmlspecialchars(substr($appointment_inq['notes'], 0, 200)) . (strlen($appointment_inq['notes'] ?? '') > 200 ? '…' : ''); ?>
                                    </div>
                                <?php endif; ?>

                                <!-- Status pill -->
                                <div style="margin-top:10px;">
                                    <?php
                                    $apt_status = $appointment_inq['status'] ?? '';
                                    $status_colors = [
                                        'completed' => ['bg' => '#dbeafe', 'color' => '#1e3a8a'],
                                        'confirmed' => ['bg' => '#d8f3dc', 'color' => '#2d6a4f'],
                                        'pending' => ['bg' => '#fff3cd', 'color' => '#7d5a00'],
                                        'cancelled' => ['bg' => '#fee2e2', 'color' => '#9b1c1c'],
                                    ];
                                    $sc = $status_colors[$apt_status] ?? ['bg' => '#f3f4f6', 'color' => '#374151'];
                                    ?>
                                    <span
                                        style="display:inline-flex;align-items:center;gap:5px;padding:4px 12px;border-radius:20px;font-size:11.5px;font-weight:700;text-transform:uppercase;background:<?php echo $sc['bg']; ?>;color:<?php echo $sc['color']; ?>;">
                                        <i class="fas fa-circle" style="font-size:6px;"></i>
                                        Appointment <?php echo ucfirst($apt_status); ?>
                                    </span>
                                </div>
                            </div>

                            <!-- Link back -->
                            <div style="margin-top:12px;">
                                <a href="appointment-dashboard?view=list" class="action-link" style="margin-bottom:0;">
                                    <i class="fas fa-external-link-alt"></i> View in Appointments Dashboard
                                </a>
                            </div>
                        </div>
                    </div>

                <?php elseif ($source_type === 'concept' && $concept_inq): ?>
                    <!-- ── CONCEPT ORIGIN ─────────────────────────────────────────── -->
                    <div class="section-card" style="margin-bottom:18px;">
                        <div class="section-head">
                            <h3><i class="fas fa-palette" style="color:var(--purple);"></i> Concept Inquiry Origin</h3>
                        </div>
                        <div class="section-body" style="padding:14px 20px;">
                            <div class="origin-card concept">
                                <label><i class="fas fa-info-circle"></i> Source Inquiry</label>
                                <?php if ($concept_inq['style_title']): ?>
                                    <div style="font-weight:600;font-size:14px;color:var(--purple);margin-bottom:6px;">
                                        <i class="fas fa-palette"></i>
                                        <?php echo htmlspecialchars($concept_inq['style_title']); ?>
                                    </div>
                                <?php endif; ?>
                                <div style="font-size:13px;color:#3730a3;margin-bottom:4px;"><strong>Project:</strong>
                                    <?php echo val($concept_inq['project_type']); ?></div>
                                <?php if (!empty($concept_inq['know_more_about'])): ?>
                                    <div style="font-size:12.5px;color:#5650a2;margin-bottom:10px;line-height:1.5;">
                                        <?php echo htmlspecialchars(substr($concept_inq['know_more_about'], 0, 140)) . (strlen($concept_inq['know_more_about']) > 140 ? '…' : ''); ?>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($concept_inq['style_id'])): ?>
                                    <a href="../../concepts?style=<?php echo $concept_inq['style_id']; ?>" target="_blank"
                                        style="display:inline-flex;align-items:center;gap:6px;padding:6px 13px;background:#fff;border:1.5px solid #c4b5fd;border-radius:var(--radius-sm);font-size:12.5px;font-weight:600;color:var(--purple);text-decoration:none;transition:all .18s;"
                                        onmouseover="this.style.background='var(--purple)';this.style.color='#fff';"
                                        onmouseout="this.style.background='#fff';this.style.color='var(--purple)';">
                                        <i class="fas fa-external-link-alt"></i> View Concept Style
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                <?php elseif ($source_type === 'contact' && $contact_inq): ?>
                    <!-- ── CONTACT ORIGIN ─────────────────────────────────────────── -->
                    <div class="section-card" style="margin-bottom:18px;">
                        <div class="section-head">
                            <h3><i class="fas fa-envelope" style="color:var(--teal);"></i> Contact Inquiry Origin</h3>
                        </div>
                        <div class="section-body" style="padding:14px 20px;">
                            <div class="origin-card contact">
                                <label><i class="fas fa-info-circle"></i> Source Inquiry</label>
                                <div style="font-weight:600;font-size:14px;color:var(--teal);margin-bottom:6px;">
                                    <i class="fas fa-tag"></i> <?php echo val($contact_inq['subject']); ?>
                                </div>
                                <div style="font-size:13px;color:#134e4a;margin-bottom:4px;">
                                    <strong>Type:</strong>
                                    <?php echo $contact_inq['inquiry_type'] === 'contact_with_items' ? '<span class="badge badge-teal" style="font-size:11px;"><i class="fas fa-box"></i> Product Inquiry</span>' : 'General Contact'; ?>
                                </div>
                                <?php if (!empty($contact_inq['location'])): ?>
                                    <div style="font-size:12.5px;color:#0f766e;margin-bottom:4px;"><i
                                            class="fas fa-map-marker-alt"></i>
                                        <?php echo htmlspecialchars($contact_inq['location']); ?></div>
                                <?php endif; ?>
                                <?php if (!empty($contact_inq['message'])): ?>
                                    <div
                                        style="font-size:12.5px;color:#134e4a;margin-top:6px;line-height:1.5;padding:8px;background:rgba(255,255,255,.5);border-radius:6px;">
                                        <?php echo htmlspecialchars(substr($contact_inq['message'], 0, 160)) . (strlen($contact_inq['message']) > 160 ? '…' : ''); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($contact_items)): ?>
                                <div style="margin-top:14px;">
                                    <div
                                        style="font-size:11px;font-weight:700;color:var(--teal);text-transform:uppercase;letter-spacing:.4px;margin-bottom:10px;">
                                        <i class="fas fa-box"></i> Inquired Products (<?php echo count($contact_items); ?>)
                                    </div>
                                    <?php foreach ($contact_items as $item): ?>
                                        <div class="product-item">
                                            <img src="<?php echo htmlspecialchars($item['item_image_path'] ?? '../../realiving_user/images/placeholder.png'); ?>"
                                                alt="<?php echo val($item['item_name']); ?>"
                                                onerror="this.src='../../realiving_user/images/placeholder.png'">
                                            <div style="flex:1;">
                                                <div style="font-weight:600;font-size:13.5px;">
                                                    <?php echo val($item['item_name']); ?></div>
                                                <div style="font-size:12px;color:var(--text-muted);">Code:
                                                    <?php echo val($item['item_code']); ?></div>
                                                <a href="../../realiving_user/modular/product-details.php?id=<?php echo $item['item_id']; ?>"
                                                    target="_blank"
                                                    style="font-size:12px;color:var(--teal);text-decoration:none;display:inline-flex;align-items:center;gap:4px;margin-top:3px;">
                                                    <i class="fas fa-external-link-alt"></i> View Product
                                                </a>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                <?php elseif ($source_type === 'project' && $project_inq): ?>
                    <!-- ── PROJECT ORIGIN ─────────────────────────────────────────── -->
                    <div class="section-card" style="margin-bottom:18px;">
                        <div class="section-head">
                            <h3><i class="fas fa-building" style="color:var(--info);"></i> Project Inquiry Origin</h3>
                        </div>
                        <div class="section-body" style="padding:14px 20px;">
                            <div class="origin-card project">
                                <label><i class="fas fa-info-circle"></i> Source Inquiry</label>
                                <?php if (!empty($project_inq['project_title'])): ?>
                                    <div style="font-weight:700;font-size:14px;color:var(--info);margin-bottom:8px;">
                                        <i class="fas fa-building"></i>
                                        <?php echo htmlspecialchars($project_inq['project_title']); ?>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($project_inq['project_address'])): ?>
                                    <div class="proj-detail-row"><strong><i class="fas fa-map-marker-alt"></i>
                                            Address</strong><span><?php echo htmlspecialchars($project_inq['project_address']); ?></span>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($project_inq['inquiry_type'])): ?>
                                    <div class="proj-detail-row"><strong><i class="fas fa-tag"></i>
                                            Type</strong><span><?php echo htmlspecialchars($project_inq['inquiry_type']); ?></span>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($project_inq['location'])): ?>
                                    <div class="proj-detail-row"><strong><i class="fas fa-globe"></i>
                                            Location</strong><span><?php echo htmlspecialchars($project_inq['location']); ?></span>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($project_inq['phone'])): ?>
                                    <div class="proj-detail-row"><strong><i class="fas fa-phone"></i>
                                            Phone</strong><span><?php echo htmlspecialchars($project_inq['phone']); ?></span>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($project_inq['email'])): ?>
                                    <div class="proj-detail-row"><strong><i class="fas fa-envelope"></i>
                                            Email</strong><span><?php echo htmlspecialchars($project_inq['email']); ?></span>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($project_inq['message'])): ?>
                                    <div
                                        style="margin-top:10px;font-size:12.5px;color:#1e3a8a;line-height:1.5;padding:8px;background:rgba(255,255,255,.55);border-radius:6px;">
                                        <?php echo htmlspecialchars(substr($project_inq['message'], 0, 180)) . (strlen($project_inq['message'] ?? '') > 180 ? '…' : ''); ?>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($project_inq['project_page_id'])): ?>
                                    <div style="margin-top:12px;">
                                        <a href="../../view-projects?id=<?php echo $project_inq['project_page_id']; ?>"
                                            target="_blank"
                                            style="display:inline-flex;align-items:center;gap:6px;padding:6px 13px;background:#fff;border:1.5px solid #93c5fd;border-radius:var(--radius-sm);font-size:12.5px;font-weight:600;color:var(--info);text-decoration:none;transition:all .18s;"
                                            onmouseover="this.style.background='var(--info)';this.style.color='#fff';"
                                            onmouseout="this.style.background='#fff';this.style.color='var(--info)';">
                                            <i class="fas fa-external-link-alt"></i> View Project Page
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Quick Actions -->
                <div class="section-card">
                    <div class="section-head">
                        <h3><i class="fas fa-bolt" style="color:var(--brand-light);"></i> Quick Actions</h3>
                    </div>
                    <div class="section-body" style="padding:14px 20px;">
                        <?php if ($source_type === 'appointment'): ?>
                            <a href="appointment-clients" class="action-link"><i class="fas fa-arrow-left"></i> Back to
                                Client Conversions</a>
                            <a href="appointment-dashboard" class="action-link"><i class="fas fa-chart-pie"></i>
                                Appointments Dashboard</a>
                            <a href="appointment-dashboard?view=list" class="action-link"><i class="fas fa-list"></i>
                                All Appointments</a>
                        <?php elseif ($source_type === 'concept'): ?>
                            <a href="concept_inquiries_clients.php" class="action-link"><i class="fas fa-arrow-left"></i>
                                Back to Concept Conversions</a>
                            <a href="concept_inquiries_dashboard.php" class="action-link"><i class="fas fa-chart-pie"></i>
                                Concept Inquiries Dashboard</a>
                            <?php if (!empty($concept_inq['style_id'])): ?>
                                <a href="../../concepts?style=<?php echo $concept_inq['style_id']; ?>" target="_blank"
                                    class="action-link"><i class="fas fa-palette"></i> View Original Concept Style</a>
                            <?php endif; ?>
                        <?php elseif ($source_type === 'contact'): ?>
                            <a href="contact_clients.php" class="action-link"><i class="fas fa-arrow-left"></i> Back to
                                Contact Conversions</a>
                            <a href="contact_dashboard.php" class="action-link"><i class="fas fa-chart-pie"></i> Contact
                                Inquiries Dashboard</a>
                        <?php elseif ($source_type === 'project'): ?>
                            <a href="project_inquiries_clients.php" class="action-link"><i class="fas fa-arrow-left"></i>
                                Back to Project Conversions</a>
                            <a href="project_inquiries_dashboard.php" class="action-link"><i class="fas fa-chart-pie"></i>
                                Project Inquiries Dashboard</a>
                            <?php if (!empty($project_inq['project_page_id'])): ?>
                                <a href="../../view-projects?id=<?php echo $project_inq['project_page_id']; ?>" target="_blank"
                                    class="action-link"><i class="fas fa-building"></i> View Original Project</a>
                            <?php endif; ?>
                        <?php else: ?>
                            <a href="javascript:history.back()" class="action-link"><i class="fas fa-arrow-left"></i> Go
                                Back</a>
                        <?php endif; ?>
                        <a href="javascript:window.print()" class="action-link"><i class="fas fa-print"></i> Print
                            Profile</a>
                    </div>
                </div>

            </div><!-- /right -->
        </div><!-- /profile-grid -->

    </div><!-- /app-wrap -->
</body>

</html>
<?php $conn->close(); ?>