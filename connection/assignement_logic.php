<?php
/**
 * HYBRID ASSIGNMENT LOGIC
 * 1. If only ONE sales agent is online → assign to that agent
 * 2. If MORE THAN ONE sales agent is online → use Round-Robin among online agents
 * 3. If NO sales agent is online → fallback to Round-Robin among all active sales agents
 */
function assignToSalesAgent($conn) {
    try {
        // Check for online sales agents (active within last 5 minutes)
        $onlineThreshold = date('Y-m-d H:i:s', strtotime('-5 minutes'));
        
        $onlineQuery = "SELECT id, last_assigned_inquiry 
                        FROM account 
                        WHERE role = 'sales' 
                        AND is_online = 1 
                        AND last_activity >= ? 
                        ORDER BY 
                            CASE WHEN last_assigned_inquiry IS NULL THEN 0 ELSE 1 END,
                            last_assigned_inquiry ASC, 
                            id ASC";
        
        $stmt = $conn->prepare($onlineQuery);
        if (!$stmt) {
            return getFallbackSalesAgent($conn);
        }
        
        $stmt->bind_param("s", $onlineThreshold);
        $stmt->execute();
        $onlineResult = $stmt->get_result();
        $onlineAgents = $onlineResult->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        
        // Case 1: Only ONE sales agent is online
        if (count($onlineAgents) === 1) {
            return $onlineAgents[0]['id'];
        }
        
        // Case 2: MORE THAN ONE sales agent is online - Round Robin among online
        if (count($onlineAgents) > 1) {
            return $onlineAgents[0]['id'];
        }
        
        // Case 3: NO sales agents online - Fallback to Round Robin among ALL active sales
        return getFallbackSalesAgent($conn);
        
    } catch (Exception $e) {
        return getFallbackSalesAgent($conn);
    }
}

/**
 * Fallback function to get sales agent when no one is online
 */
function getFallbackSalesAgent($conn) {
    try {
        $allSalesQuery = "SELECT id, last_assigned_inquiry 
                          FROM account 
                          WHERE role = 'sales' 
                          ORDER BY 
                            CASE WHEN last_assigned_inquiry IS NULL THEN 0 ELSE 1 END,
                            last_assigned_inquiry ASC, 
                            id ASC 
                          LIMIT 1";
        
        $result = $conn->query($allSalesQuery);
        
        if ($result && $result->num_rows > 0) {
            $agent = $result->fetch_assoc();
            return (int)$agent['id'];
        }
        
        return null;
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Update the last_assigned_inquiry timestamp for assigned agent
 */
function updateAssignedAgent($conn, $agentId) {
    if ($agentId) {
        $updateStmt = $conn->prepare("UPDATE account SET last_assigned_inquiry = NOW() WHERE id = ?");
        if ($updateStmt) {
            $updateStmt->bind_param("i", $agentId);
            $updateStmt->execute();
            $updateStmt->close();
        }
    }
}
?>