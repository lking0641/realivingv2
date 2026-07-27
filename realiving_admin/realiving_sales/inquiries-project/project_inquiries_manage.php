<?php
//project_inquiries_manage.php
session_start();
include "../../connection/connection.php";
include '../design/mainbody.php';
include '../checkrole/checkrole.php';

require_role(['sales', 'superadmin']);

if (!isset($_SESSION['admin_id'])) {
    header("Location: ../../loginpage/index.php");
    exit();
}

$admin_id = $_SESSION['admin_id'];
$admin_role = $_SESSION['role'] ?? '';

// Handle status update to "responded"
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_responded'])) {
    $inquiry_id = intval($_POST['inquiry_id']);
    
    $stmt = $conn->prepare("UPDATE project_inquiries SET status = 'responded', updated_at = NOW() WHERE id = ?");
    $stmt->bind_param("i", $inquiry_id);
    
    if ($stmt->execute()) {
        $_SESSION['success_message'] = "Inquiry marked as responded!";
    } else {
        $_SESSION['error_message'] = "Error updating status: " . $stmt->error;
    }
    $stmt->close();
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// Handle general status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $inquiry_id = intval($_POST['inquiry_id']);
    $new_status = $_POST['status'];
    
    $stmt = $conn->prepare("UPDATE project_inquiries SET status = ?, updated_at = NOW() WHERE id = ?");
    $stmt->bind_param("si", $new_status, $inquiry_id);
    
    if ($stmt->execute()) {
        $_SESSION['success_message'] = "Inquiry status updated successfully!";
    } else {
        $_SESSION['error_message'] = "Error updating status: " . $stmt->error;
    }
    $stmt->close();
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// Search and filter
$search = isset($_GET['search']) ? $_GET['search'] : '';
$filter_status = isset($_GET['filter_status']) ? $_GET['filter_status'] : '';
$filter_active = isset($_GET['filter_active']) ? true : false;

$sql = "SELECT pi.*, acc.full_name as sales_name, p.title as project_title, p.id as project_id
        FROM project_inquiries pi 
        LEFT JOIN account acc ON pi.assigned_to = acc.id 
        LEFT JOIN project p ON pi.project_id = p.id
        WHERE 1=1";

if ($admin_role !== 'superadmin') {
    $sql .= " AND pi.assigned_to = $admin_id";
}

if (!empty($search)) {
    $search_term = "%$search%";
    $sql .= " AND (pi.name LIKE '$search_term' OR pi.email LIKE '$search_term' OR pi.phone LIKE '$search_term' OR pi.location LIKE '$search_term')";
}

if (!empty($filter_status)) {
    $sql .= " AND pi.status = '$filter_status'";
}

if ($filter_active) {
    $sql .= " AND pi.status NOT IN ('completed', 'cancelled')";
}

$sql .= " ORDER BY pi.created_at DESC";
$result = $conn->query($sql);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Project Inquiries</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0,0,0,0.5);
        }
        .modal.active {
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .modal-content {
            background-color: #fefefe;
            padding: 30px;
            border-radius: 12px;
            max-width: 600px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
        }
    </style>
</head>
<body class="bg-gradient-to-br from-gray-100 to-gray-200 min-h-screen">
    <div class="container mx-auto p-4 md:p-6">
        <!-- Header -->
        <div class="mb-8">
            <div class="flex justify-between items-center">
                <div>
                    <h1 class="text-4xl font-bold text-gray-800 mb-2">
                        <i class="fas fa-building text-indigo-600"></i> Manage Project Inquiries
                    </h1>
                    <p class="text-gray-600">View and manage all project page inquiries
    <?php if ($filter_active): ?>
        <span class="ml-2 bg-green-100 text-green-800 text-xs font-semibold px-2 py-1 rounded-full">Showing Active Only</span>
    <?php elseif ($filter_status === 'completed'): ?>
        <span class="ml-2 bg-blue-100 text-blue-800 text-xs font-semibold px-2 py-1 rounded-full">Showing Completed Only</span>
    <?php endif; ?>
</p>
                </div>
                <a href="project_inquiries_dashboard.php" class="bg-gray-600 hover:bg-gray-700 text-white px-6 py-3 rounded-lg font-medium transition">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
            </div>
        </div>

        <!-- Flash Messages -->
        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6 rounded shadow">
                <div class="flex items-center">
                    <i class="fas fa-check-circle mr-3"></i>
                    <p><?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?></p>
                </div>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded shadow">
                <div class="flex items-center">
                    <i class="fas fa-exclamation-circle mr-3"></i>
                    <p><?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?></p>
                </div>
            </div>
        <?php endif; ?>

        <!-- Filters -->
        <div class="bg-white rounded-xl shadow-lg p-6 mb-6">
            <form method="GET" class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Search</label>
                    <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" 
                        placeholder="Name, email, phone, location..." 
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Status</label>
                    <select name="filter_status" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                        <option value="">All Statuses</option>
                        <option value="pending" <?php echo $filter_status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="responded" <?php echo $filter_status === 'responded' ? 'selected' : ''; ?>>Responded</option>
                        <option value="completed" <?php echo $filter_status === 'completed' ? 'selected' : ''; ?>>Completed</option>
                    </select>
                </div>
                <div class="flex items-end gap-2">
    <button type="submit" class="flex-1 bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg font-medium transition">
        <i class="fas fa-search"></i> Filter
    </button>
    <a href="<?php echo $_SERVER['PHP_SELF']; ?>" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg font-medium transition">
        <i class="fas fa-redo"></i>
    </a>
</div>
<div class="md:col-span-3 flex flex-wrap gap-3 pt-2 border-t border-gray-200">
    <span class="text-sm font-medium text-gray-500 self-center">Quick Filters:</span>
    <a href="?filter_active=1" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg font-medium transition text-sm">
        ✅ Active Only (Not Completed)
    </a>
    <a href="?filter_status=completed" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg font-medium transition text-sm">
        🎉 Completed Only
    </a>
    <a href="project_inquiries_manage.php" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg font-medium transition text-sm">
        🔄 Clear All Filters
    </a>
</div>
            </form>
        </div>

        <!-- Inquiries Table -->
        <div class="bg-white rounded-xl shadow-lg overflow-hidden">
            <?php if ($result->num_rows > 0): ?>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ID</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Client Info</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Contact</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Project Details</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                <?php if ($admin_role === 'superadmin'): ?>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Assigned To</th>
                                <?php endif; ?>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php while ($inq = $result->fetch_assoc()): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                    #<?php echo $inq['id']; ?>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="text-sm font-medium text-gray-900">
                                        <?php echo htmlspecialchars($inq['name']); ?>
                                    </div>
                                    <?php if ($inq['location']): ?>
                                    <div class="text-xs text-gray-500">
                                        <i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($inq['location']); ?>
                                    </div>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 text-sm">
                                    <div><i class="fas fa-envelope text-gray-400"></i> <?php echo htmlspecialchars($inq['email']); ?></div>
                                    <div><i class="fas fa-phone text-gray-400"></i> <?php echo htmlspecialchars($inq['phone']); ?></div>
                                </td>
                                <td class="px-6 py-4 text-sm">
                                    <?php if ($inq['project_title']): ?>
                                    <div class="font-medium text-indigo-600">
                                        <i class="fas fa-building"></i> <?php echo htmlspecialchars($inq['project_title']); ?>
                                    </div>
                                    <?php endif; ?>
                                    <div class="text-xs text-gray-500 mt-1">
                                        Inquiry Type: <?php echo htmlspecialchars($inq['inquiry_type']); ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="px-3 py-1 text-xs font-semibold rounded-full 
                                        <?php 
                                        echo $inq['status'] === 'completed' ? 'bg-green-100 text-green-800' : 
                                            ($inq['status'] === 'responded' ? 'bg-blue-100 text-blue-800' : 'bg-yellow-100 text-yellow-800');
                                        ?>">
                                        <?php echo ucfirst($inq['status']); ?>
                                    </span>
                                </td>
                                <?php if ($admin_role === 'superadmin'): ?>
                                <td class="px-6 py-4 text-sm">
                                    <?php echo htmlspecialchars($inq['sales_name'] ?? 'Unassigned'); ?>
                                </td>
                                <?php endif; ?>
                                <td class="px-6 py-4 whitespace-nowrap text-sm space-x-2">
                                    <button onclick="viewDetails(<?php echo htmlspecialchars(json_encode($inq)); ?>)" 
                                        class="text-blue-600 hover:text-blue-900">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    
                                    <?php if ($inq['status'] === 'pending'): ?>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="inquiry_id" value="<?php echo $inq['id']; ?>">
                                        <input type="hidden" name="mark_responded" value="1">
                                        <button type="submit" class="text-green-600 hover:text-green-900" title="Mark as Responded">
                                            <i class="fas fa-check-circle"></i>
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                    
                                    <button onclick="updateStatus(<?php echo $inq['id']; ?>, '<?php echo $inq['status']; ?>')" 
                                        class="text-purple-600 hover:text-purple-900">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    
                                    <?php if ($inq['project_id']): ?>
                                    <a href="../../realiving_user/projects/project-template-example.php?id=<?php echo $inq['project_id']; ?>" 
                                        target="_blank"
                                        class="text-indigo-600 hover:text-indigo-900" 
                                        title="View Project Page">
                                        <i class="fas fa-external-link-alt"></i>
                                    </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="text-center py-12">
                    <i class="fas fa-inbox text-6xl text-gray-300 mb-4"></i>
                    <p class="text-xl text-gray-500">No inquiries found</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- View Details Modal -->
    <div id="detailsModal" class="modal">
        <div class="modal-content">
            <div class="flex justify-between items-center mb-6">
                <h2 class="text-2xl font-bold text-gray-800">
                    <i class="fas fa-info-circle text-indigo-600"></i> Inquiry Details
                </h2>
                <button onclick="closeDetailsModal()" class="text-gray-500 hover:text-gray-700">
                    <i class="fas fa-times text-2xl"></i>
                </button>
            </div>
            <div id="detailsContent"></div>
        </div>
    </div>

    <!-- Update Status Modal -->
    <div id="statusModal" class="modal">
        <div class="modal-content">
            <div class="flex justify-between items-center mb-6">
                <h2 class="text-2xl font-bold text-gray-800">
                    <i class="fas fa-edit text-purple-600"></i> Update Status
                </h2>
                <button onclick="closeStatusModal()" class="text-gray-500 hover:text-gray-700">
                    <i class="fas fa-times text-2xl"></i>
                </button>
            </div>
            <form method="POST">
                <input type="hidden" name="update_status" value="1">
                <input type="hidden" name="inquiry_id" id="status_inquiry_id">
                <div class="mb-6">
                    <label class="block text-sm font-medium text-gray-700 mb-2">New Status</label>
                    <select name="status" id="status_select" required
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500">
                        <option value="pending">Pending</option>
                        <option value="responded">Responded</option>
                        <option value="completed">Completed</option>
                    </select>
                </div>
                <div class="flex justify-end space-x-3">
                    <button type="button" onclick="closeStatusModal()" 
                        class="px-6 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">
                        Cancel
                    </button>
                    <button type="submit" 
                        class="px-6 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700">
                        <i class="fas fa-check"></i> Update Status
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function viewDetails(inquiry) {
            const projectLink = inquiry.project_id 
                ? `<a href="../../realiving_user/projects/project-template-example.php?id=${inquiry.project_id}" target="_blank" class="text-blue-600 hover:underline">
                     View Project Page <i class="fas fa-external-link-alt text-xs"></i>
                   </a>` 
                : 'N/A';
            
            const content = `
                <div class="space-y-4">
                    ${inquiry.project_title ? `
                    <div class="bg-indigo-50 p-4 rounded-lg">
                        <p class="text-sm text-indigo-700 font-semibold mb-1">Related Project</p>
                        <p class="text-lg font-bold text-indigo-900">${inquiry.project_title}</p>
                        ${projectLink}
                    </div>
                    ` : ''}
                    
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <p class="text-sm text-gray-500">Name</p>
                            <p class="font-medium">${inquiry.name}</p>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500">Email</p>
                            <p class="font-medium">${inquiry.email}</p>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500">Phone</p>
                            <p class="font-medium">${inquiry.phone}</p>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500">Location</p>
                            <p class="font-medium">${inquiry.location || 'N/A'}</p>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500">Status</p>
                            <p class="font-medium capitalize">${inquiry.status}</p>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500">Inquiry Type</p>
                            <p class="font-medium">${inquiry.inquiry_type}</p>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500">Created At</p>
                            <p class="font-medium">${new Date(inquiry.created_at).toLocaleString()}</p>
                        </div>
                    </div>
                </div>
            `;
            
            document.getElementById('detailsContent').innerHTML = content;
            document.getElementById('detailsModal').classList.add('active');
        }

        function closeDetailsModal() {
            document.getElementById('detailsModal').classList.remove('active');
        }

        function updateStatus(id, currentStatus) {
            document.getElementById('status_inquiry_id').value = id;
            document.getElementById('status_select').value = currentStatus;
            document.getElementById('statusModal').classList.add('active');
        }

        function closeStatusModal() {
            document.getElementById('statusModal').classList.remove('active');
        }

        window.onclick = function(event) {
            const detailsModal = document.getElementById('detailsModal');
            const statusModal = document.getElementById('statusModal');
            if (event.target === detailsModal) {
                closeDetailsModal();
            }
            if (event.target === statusModal) {
                closeStatusModal();
            }
        }
    </script>
</body>
</html>

<?php $conn->close(); ?>