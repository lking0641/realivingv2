<?php
// attachment_upload_ui.php
// Variables expected: $typeKey, $area, $client_id, $uploadId, $existingFiles
// Optional: $roomUnitNumber (int), $roomUnitName (string)
$hasRoom = isset($roomUnitNumber);
$maxFiles = 10;
$currentCount = count($existingFiles);
$canUpload = $currentCount < $maxFiles;
?>

<!-- Existing Files -->
<?php if (!empty($existingFiles)): ?>
<div style="margin-bottom:14px;">
    <p style="font-size:11px; font-weight:700; color:#6b7280; text-transform:uppercase; letter-spacing:0.4px; margin-bottom:8px;">
        Uploaded Files (<?= $currentCount ?>/<?= $maxFiles ?>)
    </p>
    <?php foreach ($existingFiles as $file): ?>
    <?php
        $isImage = strpos($file['file_type'], 'image/') === 0;
        $filePath = BASE_URL . 'uploads/layout_attachments/' . $file['file_path'];
        $ext = strtolower(pathinfo($file['file_name'], PATHINFO_EXTENSION));
        $iconMap = ['pdf'=>'fa-file-pdf','doc'=>'fa-file-word','docx'=>'fa-file-word','xls'=>'fa-file-excel','xlsx'=>'fa-file-excel','ppt'=>'fa-file-powerpoint','pptx'=>'fa-file-powerpoint','zip'=>'fa-file-archive','txt'=>'fa-file-alt'];
        $fileIcon = $iconMap[$ext] ?? 'fa-file';
    ?>
    <div class="file-card" id="file-<?= $file['id'] ?>">
        <?php if ($isImage): ?>
        <img src="<?= htmlspecialchars($filePath) ?>" class="file-thumb" alt="" onerror="this.style.display='none'">
        <?php else: ?>
        <div class="file-icon"><i class="fas <?= $fileIcon ?>" style="color:#6366f1; font-size:18px;"></i></div>
        <?php endif; ?>
        <div class="file-info">
            <div class="file-name"><?= htmlspecialchars($file['file_name']) ?></div>
            <div class="file-meta">
                <?= round($file['file_size'] / 1024, 1) ?> KB &nbsp;•&nbsp;
                <?= htmlspecialchars($file['uploader_name'] ?? '') ?> &nbsp;•&nbsp;
                <?= date('M d, Y g:i A', strtotime($file['created_at'])) ?>
            </div>
            <?php if (!empty($file['note'])): ?>
            <span class="file-note"><i class="fas fa-sticky-note"></i> <?= htmlspecialchars($file['note']) ?></span>
            <?php endif; ?>
        </div>
        <a href="<?= htmlspecialchars($filePath) ?>" target="_blank" class="btn-view">
            <i class="fas fa-eye"></i> View
        </a>
        <button type="button" class="btn-delete" onclick="confirmDelete(<?= $file['id'] ?>, '<?= htmlspecialchars($file['file_name'], ENT_QUOTES) ?>')">
            <i class="fas fa-trash"></i>
        </button>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Upload Form -->
<?php if ($canUpload): ?>
<form method="POST" action="attachment_upload.php" enctype="multipart/form-data">
    <input type="hidden" name="client_id"         value="<?= $client_id ?>">
    <input type="hidden" name="attachment_type"   value="<?= htmlspecialchars($typeKey) ?>">
    <input type="hidden" name="area"              value="<?= htmlspecialchars($area) ?>">
    <?php if ($hasRoom): ?>
    <input type="hidden" name="room_unit_number"  value="<?= (int)$roomUnitNumber ?>">
    <input type="hidden" name="room_unit_name"    value="<?= htmlspecialchars($roomUnitName) ?>">
    <?php endif; ?>

    <div class="upload-zone">
        <i class="fas fa-cloud-upload-alt"></i>
        <p>Click or drag files here to upload</p>
        <p class="hint">Images and documents only — no videos. Max <?= $maxFiles - $currentCount ?> more file(s). Max 10MB each.</p>
        <p class="file-count" style="margin-top:6px; font-weight:600;"></p>
        <input type="file" name="files[]" multiple accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.zip"
               style="display:none;" onclick="event.stopPropagation()">
    </div>

    <div style="margin-bottom:12px;">
        <label style="font-size:12px; font-weight:700; color:#374151; text-transform:uppercase; letter-spacing:0.4px; display:block; margin-bottom:6px;">
            Note (applies to all uploaded files)
        </label>
        <textarea name="note" class="note-input" rows="2" placeholder="Optional note about these files..."></textarea>
    </div>

    <button type="submit" class="btn-upload">
        <i class="fas fa-upload"></i> Upload Files
    </button>
</form>
<?php else: ?>
<div style="background:#fef3c7; border:1px solid #fcd34d; border-radius:8px; padding:12px 16px; font-size:13px; color:#92400e;">
    <i class="fas fa-exclamation-triangle"></i> Maximum of <?= $maxFiles ?> files reached. Delete some files to upload more.
</div>
<?php endif; ?>