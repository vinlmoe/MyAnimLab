<?php

/**
 * Files attached to a cage (cage edit pages).
 *
 * A file is stored under uploads/ with a random name, which Apache never
 * serves (.htaccess): it is downloaded through file_download.php, by a
 * signed-in user, always as an attachment. Its content must match its
 * extension (no page or script disguised as a document or an image).
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
 * the message to show (success or why the file was refused).
 */
function store_cage_file(mysqli $con, string $cageId, array $file): string
{
    if ($file['size'] > CAGE_FILE_MAX_BYTES) {
        return tr('File size exceeds 10MB limit.');
    }
    $extension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
    if (!isset(CAGE_FILE_TYPES[$extension])) {
        return tr('Invalid file type. Allowed: {types}', ['types' => implode(', ', array_keys(CAGE_FILE_TYPES))]);
    }
    $detected = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!in_array($detected, CAGE_FILE_DETECTED[$extension], true)) {
        return tr('The content of the file does not match its type ({type}).', ['type' => $extension]);
    }

    // Name shown to the users; the stored name is random.
    $displayName = preg_replace('/[^a-zA-Z0-9._-]/', '_', basename((string) $file['name']));
    $storedPath = 'uploads/' . bin2hex(random_bytes(16)) . '.' . $extension;
    cage_files_dir();
    if (!move_uploaded_file($file['tmp_name'], __DIR__ . '/' . $storedPath)) {
        return tr('Sorry, there was an error uploading your file.');
    }
    chmod(__DIR__ . '/' . $storedPath, 0640);

    $insert = $con->prepare("INSERT INTO files (file_name, file_path, cage_id) VALUES (?, ?, ?)");
    $insert->bind_param("sss", $displayName, $storedPath, $cageId);
    $ok = $insert->execute();
    $insert->close();
    if (!$ok) {
        unlink(__DIR__ . '/' . $storedPath);
        return tr('File upload failed, please try again.');
    }
    return tr('File uploaded successfully.');
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
