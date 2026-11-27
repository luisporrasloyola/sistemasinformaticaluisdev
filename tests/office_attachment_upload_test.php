<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/upload.php';

$extensions = array_intersect_key(format_attachment_extensions(), array_flip(['pdf', 'jpg', 'jpeg', 'png', 'webp', 'doc', 'docx', 'xls', 'xlsx']));
foreach (['doc', 'docx', 'xls', 'xlsx'] as $extension) {
    if (empty($extensions[$extension])) throw new RuntimeException('Missing Office format: ' . $extension);
}
foreach (['docx' => 'word/document.xml', 'xlsx' => 'xl/workbook.xml', 'zip' => 'readme.txt'] as $extension => $part) {
    $temporary = tempnam(sys_get_temp_dir(), 'pmi_office_');
    if ($temporary === false) throw new RuntimeException('Could not prepare fixture.');
    $archivePath = $temporary . '.zip';
    try {
        $archive = new PharData($archivePath, 0, null, Phar::ZIP);
        $archive->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
        $archive->addFromString($part, '<?xml version="1.0"?><document/>');
        unset($archive);
        copy($archivePath, $temporary);
        $originalHash = hash_file('sha256', $temporary);
        foreach (['docx', 'xlsx'] as $candidate) {
            if (office_attachment_archive_matches($temporary, $candidate) !== ($extension === $candidate)) {
                throw new RuntimeException('Incorrect Office archive validation: ' . $extension . ' as ' . $candidate);
            }
        }
        if ($extension !== 'zip') {
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($temporary);
            if (!in_array($mime, $extensions[$extension], true)) throw new RuntimeException('Detected MIME not supported.');
        }
        if ($originalHash !== hash_file('sha256', $temporary)) throw new RuntimeException('Validation changed the original file.');
    } finally {
        if (is_file($temporary)) unlink($temporary);
        if (is_file($archivePath)) unlink($archivePath);
    }
}
echo "OK: DOC/DOCX/XLS/XLSX allowed; DOCX/XLSX MIME, mismatched extension and non-Office ZIP rejection verified. Original files preserved.\n";
