<?php

/**
 * Files attached to a cage, on its page (hc_view.php / bc_view.php).
 *
 * Any signed-in user downloads them; the users assigned to the cage and the
 * administrators add (file_upload.php) and delete them (delete_file.php). A
 * file is stored under uploads/ with a random name, which Apache never
 * serves (.htaccess): it is downloaded through file_download.php, always as
 * an attachment with the content type of its extension. Its content must
 * match its extension (no page or script disguised as a document or image).
 */

const CAGE_FILE_MAX_BYTES = 10 * 1024 * 1024;

// Allowed extensions and the content types served for them.
const CAGE_FILE_TYPES = [
    'pdf' => 'application/pdf',
    'doc' => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'txt' => 'text/plain',
    'xls' => 'application/vnd.ms-excel',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'ppt' => 'application/vnd.ms-powerpoint',
    'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'gif' => 'image/gif',
    'bmp' => 'image/bmp',
    'webp' => 'image/webp',
];

// Detected content types accepted for each extension (finfo).
const CAGE_FILE_DETECTED = [
    'pdf' => ['application/pdf'],
    'doc' => ['application/msword', 'application/vnd.ms-office', 'application/CDFV2', 'application/x-ole-storage'],
    'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
    'txt' => ['text/plain', 'text/csv', 'application/csv', 'application/x-empty', 'inode/x-empty'],
    'xls' => ['application/vnd.ms-excel', 'application/vnd.ms-office', 'application/CDFV2', 'application/x-ole-storage'],
    'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
    'ppt' => ['application/vnd.ms-powerpoint', 'application/vnd.ms-office', 'application/CDFV2', 'application/x-ole-storage'],
    'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip', 'application/octet-stream'],
    'jpg' => ['image/jpeg'],
    'jpeg' => ['image/jpeg'],
    'png' => ['image/png'],
    'gif' => ['image/gif'],
    'bmp' => ['image/bmp', 'image/x-ms-bmp'],
    'webp' => ['image/webp'],
];

// Folder of the stored files, created on first use and closed to Apache.
function cage_files_dir(): string
{
    $dir = __DIR__ . '/uploads';
    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
    }
    if (!is_file($dir . '/.htaccess')) {
        file_put_contents($dir . '/.htaccess', "Require all denied\n");
    }
    return $dir;
}

/**
 * Store an uploaded file ($_FILES entry) for a cage and record it. Returns
 * [file id or null when refused, message to show].
 */
function store_cage_file(mysqli $con, string $cageId, array $file): array
{
    $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE || ($file['size'] ?? 0) > CAGE_FILE_MAX_BYTES) {
        return [null, tr('File size exceeds 10MB limit.')];
    }
    if ($error !== UPLOAD_ERR_OK) {
        return [null, tr('Choose a file to add.')];
    }
    $extension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
    if (!isset(CAGE_FILE_TYPES[$extension])) {
        return [null, tr('Invalid file type. Allowed: {types}', ['types' => implode(', ', array_keys(CAGE_FILE_TYPES))])];
    }
    $detected = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!in_array($detected, CAGE_FILE_DETECTED[$extension], true)) {
        return [null, tr('The content of the file does not match its type ({type}).', ['type' => $extension])];
    }

    // Name shown to the users; the stored name is random.
    $displayName = preg_replace('/[^a-zA-Z0-9._-]/', '_', basename((string) $file['name']));
    $storedPath = 'uploads/' . bin2hex(random_bytes(16)) . '.' . $extension;
    cage_files_dir();
    if (!move_uploaded_file($file['tmp_name'], __DIR__ . '/' . $storedPath)) {
        return [null, tr('Sorry, there was an error uploading your file.')];
    }
    chmod(__DIR__ . '/' . $storedPath, 0640);

    $insert = $con->prepare("INSERT INTO files (file_name, file_path, cage_id) VALUES (?, ?, ?)");
    $insert->bind_param("sss", $displayName, $storedPath, $cageId);
    try {
        $insert->execute();
    } catch (mysqli_sql_exception $e) {
        unlink(__DIR__ . '/' . $storedPath);
        return [null, db_error_message($e->getMessage())];
    }
    $fileId = (int) $con->insert_id;
    $insert->close();
    return [$fileId, tr('File uploaded successfully.')];
}

/**
 * Absolute path of a recorded file, or null when it is missing or outside
 * uploads/ (a recorded path is never trusted to point elsewhere).
 */
function cage_file_disk_path(string $recordedPath): ?string
{
    $uploads = realpath(__DIR__ . '/uploads');
    $path = realpath(__DIR__ . '/' . $recordedPath);
    if ($uploads === false || $path === false || !is_file($path) || !str_starts_with($path, $uploads . DIRECTORY_SEPARATOR)) {
        return null;
    }
    return $path;
}

// Download link of a cage file.
function cage_file_url(int $fileId): string
{
    return 'file_download.php?id=' . $fileId;
}

// The current user may add and delete the files of a cage: an administrator
// or a user assigned to the cage.
function cage_file_can_manage(mysqli $con, string $cageId): bool
{
    if (($_SESSION['role'] ?? '') === 'admin') {
        return true;
    }
    return (bool) db_value($con, 'SELECT 1 FROM cage_users WHERE cage_id = ? AND user_id = ?', 'si', [$cageId, (int) ($_SESSION['user_id'] ?? 0)]);
}

// Files section of a cage page: list with download (and delete) buttons,
// form to add a file. $page is the page to come back to (hc_view / bc_view).
function render_cage_files(mysqli $con, string $cageId, string $page): void
{
    $files = db_rows($con, 'SELECT id, file_name, uploaded_at FROM files WHERE cage_id = ? ORDER BY uploaded_at, id', 's', [$cageId]);
    $canManage = cage_file_can_manage($con, $cageId);
    $token = htmlspecialchars(csrf_token());
    $cage = htmlspecialchars($cageId);
    ?>
    <div class="card mt-4" id="files">
        <div class="card-header">
            <h4><?= trh('Manage Files') ?></h4>
        </div>
        <div class="card-body">
            <?php if ($files) : ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th><?= trh('File Name') ?></th>
                                <th><?= trh('Added on') ?></th>
                                <th><?= trh('Actions') ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($files as $file) : ?>
                                <tr>
                                    <td><?= htmlspecialchars($file['file_name']) ?></td>
                                    <td class="text-nowrap small"><?= htmlspecialchars(substr((string) $file['uploaded_at'], 0, 16)) ?></td>
                                    <td class="text-nowrap">
                                        <a href="<?= htmlspecialchars(cage_file_url((int) $file['id'])) ?>" class="btn btn-sm btn-outline-primary" title="<?= trh('Download') ?>"><i class="fas fa-cloud-download-alt fa-sm"></i></a>
                                        <?php if ($canManage) : ?>
                                            <form method="POST" action="delete_file.php?<?= htmlspecialchars(http_build_query(['url' => $page, 'id' => (int) $file['id']])) ?>" class="d-inline" onsubmit="return confirm(<?= htmlspecialchars(trj('Are you sure you want to delete this file?')) ?>);">
                                                <input type="hidden" name="csrf_token" value="<?= $token ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger" aria-label="<?= trh('Delete {file}', ['file' => $file['file_name']]) ?>"><i class="fas fa-trash fa-sm"></i></button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else : ?>
                <p class="text-muted"><?= trh('No file attached to this cage.') ?></p>
            <?php endif; ?>
            <?php if ($canManage) : ?>
                <form method="POST" action="file_upload.php" enctype="multipart/form-data" class="row g-2 align-items-end" id="cageFileUpload">
                    <input type="hidden" name="csrf_token" value="<?= $token ?>">
                    <input type="hidden" name="cage_id" value="<?= $cage ?>">
                    <input type="hidden" name="page" value="<?= htmlspecialchars($page) ?>">
                    <div class="col-md-8">
                        <label class="form-label" for="fileUpload"><?= trh('Add a file') ?></label>
                        <input type="file" class="form-control" id="fileUpload" name="fileUpload" required accept="<?= htmlspecialchars('.' . implode(',.', array_keys(CAGE_FILE_TYPES))) ?>">
                        <div class="form-text"><?= trh('{types} — 10 MB at most.', ['types' => implode(', ', array_keys(CAGE_FILE_TYPES))]) ?></div>
                    </div>
                    <div class="col-md-4">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-upload"></i> <?= trh('Upload') ?></button>
                    </div>
                </form>
            <?php else : ?>
                <p class="small text-muted mb-0"><?= trh('Only the users of the cage and the administrators can add or delete files.') ?></p>
            <?php endif; ?>
        </div>
    </div>
    <?php
}
