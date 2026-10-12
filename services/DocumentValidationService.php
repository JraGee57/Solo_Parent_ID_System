<?php
/**
 * Validates uploaded files and stores them under a random name. Also
 * manages the per-draft document map used by the Apply Online and ID
 * Renewal flows.
 *
 * An upload goes UPLOADED -> VALIDATING -> VALID or INVALID within the
 * request (see Document::STATUSES). Validation never trusts the client's
 * filename, extension or Content-Type: the extension must be allowed, the
 * real content type (magic bytes) must be allowed AND match the extension,
 * and the content must actually parse as that type (a decodable image, or
 * a PDF with its header and end marker). Invalid files are discarded,
 * never stored.
 */
class DocumentValidationService
{
    /** Allowed extension => the only content type it may contain. */
    private const EXT_MIME = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'pdf' => 'application/pdf'];

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Validates one application document and moves it into $destDir.
     * Returns ['ok' => bool, 'error' => string, 'status' => 'VALID'|'INVALID', 'meta' => array|null].
     */
    public function storeDocument(array $file, string $destDir): array
    {
        $fail = fn(string $msg) => ['ok' => false, 'error' => $msg, 'status' => 'INVALID', 'meta' => null];

        $rules = document_rules();
        $maxMb = $rules['max_mb'];
        $uploadError = $this->uploadError($file, 'File is larger than ' . format_mb($maxMb) . '.');
        if ($uploadError !== null) {
            return $fail($uploadError);
        }

        $originalName = $this->cleanFileName($file['name']);
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!isset(self::EXT_MIME[$ext]) || !in_array($ext, $rules['exts'], true)) {
            return $fail('File must be ' . $rules['label'] . '.');
        }
        $size = (int) filesize($file['tmp_name']);
        if ($size === 0) {
            return $fail('The file is empty.');
        }
        if ($size > (int) ($maxMb * 1024 * 1024)) {
            return $fail('File is larger than ' . format_mb($maxMb) . '.');
        }
        $mime = $this->detectMime($file['tmp_name']);
        if (!in_array($mime, $rules['mimes'], true)) {
            return $fail('File must be ' . $rules['label'] . '.');
        }
        if ($mime !== self::EXT_MIME[$ext]) {
            return $fail("The file's contents don't match its .$ext extension.");
        }
        if (!$this->contentIsValid($file['tmp_name'], $mime)) {
            return $fail('The file appears to be damaged or is not a real ' . strtoupper($ext === 'jpeg' ? 'jpg' : $ext) . ' file.');
        }

        if (!$this->ensureDir($destDir)) {
            return $fail('Could not save the file. Please try again.');
        }
        $storedName = bin2hex(random_bytes(16)) . '.' . $ext;
        $destination = $destDir . '/' . $storedName;
        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            return $fail('Could not save the file. Please try again.');
        }

        return [
            'ok' => true,
            'error' => '',
            'status' => 'VALID',
            'meta' => [
                'original_name' => $originalName,
                'stored_name' => $storedName,
                'path' => $destination,
                'mime' => $mime,
                'size' => $size,
                'sha256' => hash_file('sha256', $destination),
                'status' => 'VALID',
            ],
        ];
    }

    /** Validates a profile photo and stores it in AVATAR_STORAGE_PATH. Returns ['ok', 'error', 'relativePath']. */
    public function storeProfilePhoto(array $file, int $userId): array
    {
        $fail = fn(string $msg) => ['ok' => false, 'error' => $msg, 'relativePath' => null];

        $uploadError = $this->uploadError($file, 'Photo is larger than 2MB.', 'Please choose a photo.');
        if ($uploadError !== null) {
            return $fail($uploadError);
        }
        $ext = strtolower(pathinfo($this->cleanFileName($file['name']), PATHINFO_EXTENSION));
        if (!in_array($ext, ALLOWED_AVATAR_EXT, true) || !isset(self::EXT_MIME[$ext])) {
            return $fail('Photo must be a JPG or PNG image.');
        }
        if (filesize($file['tmp_name']) > MAX_AVATAR_BYTES) {
            return $fail('Photo is larger than 2MB.');
        }
        $mime = $this->detectMime($file['tmp_name']);
        if (!in_array($mime, ALLOWED_AVATAR_MIME, true) || $mime !== self::EXT_MIME[$ext] || !$this->contentIsValid($file['tmp_name'], $mime)) {
            return $fail('Photo must be a JPG or PNG image.');
        }
        if (!$this->ensureDir(AVATAR_STORAGE_PATH)) {
            return $fail('Could not save the photo. Please try again.');
        }

        $relativePath = $userId . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        if (!move_uploaded_file($file['tmp_name'], AVATAR_STORAGE_PATH . '/' . $relativePath)) {
            return $fail('Could not save the photo. Please try again.');
        }
        return ['ok' => true, 'error' => '', 'relativePath' => $relativePath];
    }

    /**
     * Stores an upload into a draft's folder, replacing any earlier file for
     * the same doc_key. An invalid upload leaves the earlier file in place.
     */
    public function attachToDraft(array $draft, string $docKey, array $file, string $tmpDir): array
    {
        $result = $this->storeDocument($file, $tmpDir);
        if ($result['ok']) {
            $this->deleteDraftFile($draft, $docKey);
            $draft['documents'][$docKey] = $result['meta'];
            unset($draft['docErrors'][$docKey]);
        } else {
            $draft['docErrors'][$docKey] = $result['error'];
        }
        return $draft;
    }

    public function detachFromDraft(array $draft, string $docKey): array
    {
        $this->deleteDraftFile($draft, $docKey);
        unset($draft['documents'][$docKey], $draft['docErrors'][$docKey]);
        return $draft;
    }

    /** Deletes every file in a draft folder, then the folder itself. */
    public function deleteDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '/*') ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        @rmdir($dir);
    }

    private function deleteDraftFile(array $draft, string $docKey): void
    {
        $path = $draft['documents'][$docKey]['path'] ?? '';
        if ($path !== '' && is_file($path)) {
            @unlink($path);
        }
    }

    /** Null if $file is a genuine, successful single-file upload; otherwise the message to show. */
    private function uploadError(array $file, string $tooLargeMessage, string $noFileMessage = 'Please choose a file.'): ?string
    {
        // A tampered form (e.g. name="file[]") gives arrays here instead of scalars.
        if (!isset($file['error'], $file['tmp_name'], $file['name'])
            || !is_int($file['error']) || !is_string($file['tmp_name']) || !is_string($file['name'])) {
            return $noFileMessage;
        }
        switch ($file['error']) {
            case UPLOAD_ERR_OK:
                return is_uploaded_file($file['tmp_name']) ? null : 'Upload failed. Please try again.';
            case UPLOAD_ERR_NO_FILE:
                return $noFileMessage;
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return $tooLargeMessage;
            default:
                return 'Upload failed. Please try again.';
        }
    }

    /**
     * The client's filename, kept only for display: no path, no control
     * characters or quotes, valid UTF-8, at most 150 characters (keeping the
     * extension).
     */
    private function cleanFileName(string $name): string
    {
        $name = mb_convert_encoding($name, 'UTF-8', 'UTF-8');
        $name = basename(str_replace('\\', '/', $name));
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F"]+/u', '', $name));
        if (mb_strlen($name) > 150) {
            $ext = pathinfo($name, PATHINFO_EXTENSION);
            $name = mb_substr($name, 0, 140) . ($ext !== '' ? '.' . mb_substr($ext, 0, 8) : '');
        }
        return $name !== '' ? $name : 'document';
    }

    /** The content really is what its type claims: a decodable image, or a PDF with its header and end-of-file marker. */
    private function contentIsValid(string $path, string $mime): bool
    {
        if ($mime === 'application/pdf') {
            $fh = fopen($path, 'rb');
            if (!$fh) {
                return false;
            }
            $head = (string) fread($fh, 5);
            fseek($fh, -min(1024, (int) filesize($path)), SEEK_END);
            $tail = (string) fread($fh, 1024);
            fclose($fh);
            return $head === '%PDF-' && strpos($tail, '%%EOF') !== false;
        }
        $expected = ['image/jpeg' => IMAGETYPE_JPEG, 'image/png' => IMAGETYPE_PNG][$mime] ?? null;
        $info = @getimagesize($path);
        return $info !== false && $info[2] === $expected && $info[0] > 0 && $info[1] > 0;
    }

    private function detectMime(string $path): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = (string) finfo_file($finfo, $path);
        finfo_close($finfo);
        return $mime;
    }

    private function ensureDir(string $dir): bool
    {
        return is_dir($dir) || mkdir($dir, 0750, true) || is_dir($dir);
    }
}
