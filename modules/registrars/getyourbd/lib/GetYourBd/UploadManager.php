<?php

namespace GetYourBd;

use RuntimeException;
use WHMCS\Database\Capsule;

class UploadManager
{
    public const TOKEN_PREFIX = 'getyourbd-upload:';
    private const TABLE = 'mod_getyourbd_uploads';
    private const MAX_BYTES = 15728640;
    private const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'pdf'];

    public static function ensureTable(): void
    {
        $schema = Capsule::schema();
        if ($schema->hasTable(self::TABLE)) {
            return;
        }

        $schema->create(self::TABLE, function ($table) {
            $table->increments('id');
            $table->string('token', 64)->unique();
            $table->integer('client_id')->unsigned()->nullable()->index();
            $table->integer('domain_id')->unsigned()->nullable()->index();
            $table->string('session_hash', 64)->nullable()->index();
            $table->string('field_type', 32);
            $table->string('original_name', 255);
            $table->string('stored_path', 500);
            $table->string('mime_type', 100);
            $table->integer('file_size')->unsigned();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public static function store(array $file, string $fieldType): array
    {
        self::ensureTable();

        if (!in_array($fieldType, ['nid', 'registration'], true)) {
            throw new RuntimeException('Invalid document type.');
        }

        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Document upload failed with error code ' . (int) ($file['error'] ?? 0) . '.');
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > self::MAX_BYTES) {
            throw new RuntimeException('Document must be larger than 0 bytes and no more than 15 MB.');
        }

        $originalName = basename((string) ($file['name'] ?? 'document'));
        $extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw new RuntimeException('Document must be a JPG, JPEG, PNG, or PDF file.');
        }

        $tmpPath = (string) ($file['tmp_name'] ?? '');
        $mimeType = self::mimeType($tmpPath);
        if (!in_array($mimeType, ['image/jpeg', 'image/png', 'application/pdf'], true)) {
            throw new RuntimeException('Uploaded document content is not a supported image or PDF.');
        }

        $token = bin2hex(random_bytes(24));
        $directory = self::storageDirectory();
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create the GetYourBD upload directory.');
        }

        $denyFile = $directory . DIRECTORY_SEPARATOR . '.htaccess';
        if (!file_exists($denyFile)) {
            file_put_contents($denyFile, "Require all denied\nDeny from all\n");
        }

        $storedPath = $directory . DIRECTORY_SEPARATOR . $token . '.' . $extension;
        if (!move_uploaded_file($tmpPath, $storedPath)) {
            throw new RuntimeException('Unable to store the uploaded document.');
        }

        Capsule::table(self::TABLE)->insert([
            'token' => $token,
            'client_id' => !empty($_SESSION['uid']) ? (int) $_SESSION['uid'] : null,
            'domain_id' => null,
            'session_hash' => self::sessionHash(),
            'field_type' => $fieldType,
            'original_name' => $originalName,
            'stored_path' => $storedPath,
            'mime_type' => $mimeType,
            'file_size' => $size,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return [
            'reference' => self::TOKEN_PREFIX . $token,
            'filename' => $originalName,
        ];
    }

    public static function resolveReference(string $reference): ?array
    {
        if (strpos($reference, self::TOKEN_PREFIX) !== 0) {
            return null;
        }

        self::ensureTable();
        $token = substr($reference, strlen(self::TOKEN_PREFIX));
        $row = Capsule::table(self::TABLE)->where('token', $token)->first();
        if (!$row || !is_file((string) $row->stored_path) || !is_readable((string) $row->stored_path)) {
            throw new RuntimeException('The uploaded GetYourBD document could not be found. Please upload it again.');
        }

        return [
            'path' => (string) $row->stored_path,
            'filename' => (string) $row->original_name,
            'mimeType' => (string) $row->mime_type,
        ];
    }

    public static function bindToDomain(string $reference, int $domainId): void
    {
        if (strpos($reference, self::TOKEN_PREFIX) !== 0) {
            return;
        }

        $token = substr($reference, strlen(self::TOKEN_PREFIX));
        Capsule::table(self::TABLE)->where('token', $token)->update([
            'domain_id' => $domainId,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private static function sessionHash(): string
    {
        return hash('sha256', session_id());
    }

    private static function storageDirectory(): string
    {
        global $attachments_dir;

        $base = !empty($attachments_dir)
            ? rtrim((string) $attachments_dir, '/\\')
            : ROOTDIR . DIRECTORY_SEPARATOR . 'attachments';

        return $base . DIRECTORY_SEPARATOR . 'getyourbd';
    }

    private static function mimeType(string $path): string
    {
        if (!function_exists('finfo_open')) {
            throw new RuntimeException('PHP Fileinfo is required for document uploads.');
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo ? finfo_file($finfo, $path) : false;
        if ($finfo) {
            finfo_close($finfo);
        }

        return is_string($mime) ? $mime : '';
    }
}
