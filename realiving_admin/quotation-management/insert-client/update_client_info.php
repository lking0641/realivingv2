<?php
// update_client_info.php
session_start();
include $includes ['connection'];
include $includes ['checkrole'];
require_role(['admin1', 'superadmin', 'sales', 'designer', 'technical_designer', 'project_coordinator']);

if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit();
}

header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);

if (!$data || !isset($data['client_id'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid data']);
    exit();
}

$client_id     = intval($data['client_id']);
$clientname    = trim($data['clientname']    ?? '');
$nameproject   = trim($data['nameproject']   ?? '');
$status        = trim($data['status']        ?? '');
$business_type = trim($data['business_type'] ?? '');
$contact       = trim($data['contact']       ?? '');
$email         = trim($data['email']         ?? '');
$address       = trim($data['address']       ?? '');
$country       = trim($data['country']       ?? '');
$gender        = trim($data['gender']        ?? '');
$client_class  = trim($data['client_class']  ?? '');
$project_scope      = trim($data['project_scope']      ?? '');
$scope_of_work      = trim($data['scope_of_work']      ?? '');
$house_state        = trim($data['house_state']        ?? '');
$permit_required    = trim($data['permit_required']    ?? '');
$target_movein_date = trim($data['target_movein_date'] ?? '') ?: null;

if (empty($clientname) || empty($client_id)) {
    echo json_encode(['success' => false, 'error' => 'Client name is required']);
    exit();
}

$stmt = $conn->prepare("
    UPDATE user_info SET
        clientname          = ?,
        nameproject         = ?,
        status              = ?,
        business_type       = ?,
        contact             = ?,
        email               = ?,
        address             = ?,
        country             = ?,
        gender              = ?,
        client_class        = ?,
        project_scope       = ?,
        scope_of_work       = ?,
        house_state         = ?,
        permit_required     = ?,
        target_movein_date  = ?
    WHERE id = ?
");

$stmt->bind_param(
    "sssssssssssssssi",
    $clientname,
    $nameproject,
    $status,
    $business_type,
    $contact,
    $email,
    $address,
    $country,
    $gender,
    $client_class,
    $project_scope,
    $scope_of_work,
    $house_state,
    $permit_required,
    $target_movein_date,
    $client_id
);

if ($stmt->execute()) {
    echo json_encode([
        'success'            => true,
        'clientname'         => $clientname,
        'nameproject'        => $nameproject,
        'status'             => $status,
        'business_type'      => $business_type,
        'contact'            => $contact,
        'email'              => $email,
        'address'            => $address,
        'country'            => $country,
        'gender'             => $gender,
        'client_class'       => $client_class,
        'project_scope'      => $project_scope,
        'scope_of_work'      => $scope_of_work,
        'house_state'        => $house_state,
        'permit_required'    => $permit_required,
        'target_movein_date' => $target_movein_date,
    ]);
} else {
    echo json_encode(['success' => false, 'error' => $stmt->error]);
}

$stmt->close();
$conn->close();
?>