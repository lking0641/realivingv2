<?php
//test.php
include $includes ['connection'];
include $includes ['checkrole'];

require_role(['superadmin', 'general_manager', 'sales', 'designer']);

$result = $conn->query("SELECT COUNT(*) AS count FROM account");
$row = $result->fetch_assoc();
if ($row['count'] == 0) {
    $conn->query("ALTER TABLE account AUTO_INCREMENT = 1");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim($_POST['full_name']);
    $email = strtolower(trim($_POST['email']));
    $password = $_POST['password'];
    $role = $_POST['role'];

    if (!empty($full_name) && !empty($email) && !empty($password) && !empty($role)) {
        // Check if email already exists
        $check_stmt = $conn->prepare("SELECT id FROM account WHERE email = ?");
        $check_stmt->bind_param("s", $email);
        $check_stmt->execute();
        $check_stmt->store_result();

        if ($check_stmt->num_rows > 0) {
            echo "⚠️ Email already exists. Try another.";
        } else {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO account (full_name, email, password, role) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("ssss", $full_name, $email, $hashed_password, $role);

            if ($stmt->execute()) {
                echo "✅ Account for '$role' inserted successfully.";
            } else {
                echo "❌ Error: " . $stmt->error;
            }

            $stmt->close();
        }

        $check_stmt->close();
    } else {
        echo "❗ Please fill in all fields.";
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Insert Account | Realiving</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600&display=swap" rel="stylesheet">
  <style>
    body {
      font-family: 'Poppins', sans-serif;
    }
  </style>
</head>
<body class="bg-[#f9f9f9] flex items-center justify-center min-h-screen">

  <div class="bg-white p-10 rounded-2xl shadow-xl w-full max-w-md border border-gray-200">
    <h2 class="text-2xl font-semibold text-center text-gray-700 mb-6">Add Admin Account</h2>

    <form method="post" class="space-y-5">
      <div>
        <label class="block text-sm text-gray-600 mb-1">Full Name</label>
        <input type="text" name="full_name" required
               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-transparent shadow-sm"/>
      </div>

      <div>
        <label class="block text-sm text-gray-600 mb-1">Email</label>
        <input type="email" name="email" required
               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-transparent shadow-sm"/>
      </div>

      <div>
        <label class="block text-sm text-gray-600 mb-1">Password</label>
        <input type="password" name="password" required
               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-transparent shadow-sm"/>
      </div>

      <div>
        <label class="block text-sm text-gray-600 mb-1">Role</label>
        <select name="role" required
                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-transparent shadow-sm bg-white">
          <option value="">Select Role</option>
          <option value="general_manager">General Manager</option>
          <option value="operational_manager">Operational Manager</option>
          <option value="sales">Sales</option>
          <option value="designer">Designer</option>
          <option value="technical_designer">Technical Designer</option>
          <option value="accounting">Accounting</option>
          <option value="project_coordinator">Project Coordinator</option>
          <option value="super_admin">Super Admin</option>
          <option value="human_resource">Human Resource</option>
        </select>
      </div>

      <button type="submit"
              class="w-full bg-teal-600 text-white py-2 px-4 rounded-lg hover:bg-teal-700 transition duration-200 shadow-md">
        Insert Account
      </button>
    </form>
  </div>

</body>
</html>