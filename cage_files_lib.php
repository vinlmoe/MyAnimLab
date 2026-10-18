<?php

/**
 * Files attached to a cage (listed on hc_view.php / bc_view.php).
 *
 * The files are stored under uploads/, which Apache never serves
 * (.htaccess): they are downloaded through file_download.php, by a
 * signed-in user, always as an attachment with the content type of their
 * extension. (Adding files went with the retired cage edit pages.)
 */

// Content types served for the extensions of the cage files.
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
