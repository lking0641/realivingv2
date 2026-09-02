<?php
// stage_files_helpers.php
// Pure display/formatting helpers for the Stage Files page.
// No DB calls, no session/permission logic — safe to reuse anywhere.

/**
 * Converts an internal role slug into a human-readable label.
 */
function getRoleDisplayName($role)
{
    $names = [
        'general_manager' => 'General Manager',
        'operational_manager' => 'Operational Manager',
        'project_coordinator' => 'Project Coordinator',
        'designer' => 'Designer (Head)',
        'technical_designer' => 'Technical Designer (Head)',
        'accounting' => 'Accounting',
    ];
    return $names[$role] ?? ucwords(str_replace('_', ' ', $role));
}

/**
 * Returns [fontAwesomeIconClass, hexColor] for a given file extension.
 */
function fileIcon($ext)
{
    $map = [
        'pdf' => ['fa-file-pdf', '#ef4444'],
        'doc' => ['fa-file-word', '#3b82f6'],
        'docx' => ['fa-file-word', '#3b82f6'],
        'xls' => ['fa-file-excel', '#10b981'],
        'xlsx' => ['fa-file-excel', '#10b981'],
        'ppt' => ['fa-file-powerpoint', '#f59e0b'],
        'pptx' => ['fa-file-powerpoint', '#f59e0b'],
        'png' => ['fa-file-image', '#8b5cf6'],
        'jpg' => ['fa-file-image', '#8b5cf6'],
        'jpeg' => ['fa-file-image', '#8b5cf6'],
        'gif' => ['fa-file-image', '#8b5cf6'],
        'txt' => ['fa-file-alt', '#6b7280'],
        'csv' => ['fa-file-csv', '#6b7280'],
    ];
    return $map[$ext] ?? ['fa-file', '#6b7280'];
}