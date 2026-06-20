<?php

namespace GetYourBd;

use CURLFile;
use InvalidArgumentException;
use RuntimeException;

class DocumentResolver
{
    private const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'pdf'];
    private const MAX_DOWNLOAD_BYTES = 15728640;

    private string $rootDir;
    private string $basePath;
    private bool $allowRemoteUrls;
    private int $timeout;

    public function __construct(string $rootDir, string $basePath = '', bool $allowRemoteUrls = false, int $timeout = 20)
    {
        $this->rootDir = rtrim($rootDir, '/\\');
        $this->basePath = trim($basePath);
        $this->allowRemoteUrls = $allowRemoteUrls;
        $this->timeout = max(5, $timeout);
    }

    public function resolveRequired(string $reference, string $label): array
    {
        if (trim($reference) === '') {
            throw new InvalidArgumentException($label . ' is required for GetYourBD domain orders.');
        }

        return $this->resolve($reference, $label);
    }

    public function resolveOptional(string $reference, string $label): ?array
    {
        if (trim($reference) === '') {
            return null;
        }

        return $this->resolve($reference, $label);
    }

    public static function cleanup(?array $document): void
    {
        if (!$document || empty($document['cleanup']) || empty($document['path'])) {
            return;
        }

        if (is_file($document['path'])) {
            @unlink($document['path']);
        }
    }

    private function resolve(string $reference, string $label): array
    {
        $reference = trim($reference);
        $uploaded = UploadManager::resolveReference($reference);
        if ($uploaded) {
            return $this->document($uploaded['path'], $uploaded['filename'], false);
        }

        if (preg_match('/^https?:\/\//i', $reference)) {
            return $this->resolveRemote($reference, $label);
        }

        return $this->resolveLocal($reference, $label);
    }

    private function resolveRemote(string $url, string $label): array
    {
        if (!$this->allowRemoteUrls) {
            throw new InvalidArgumentException(
                $label . ' uses a remote URL, but remote document URLs are disabled in registrar settings.'
            );
        }

        $parts = parse_url($url);
        if (($parts['scheme'] ?? '') !== 'https') {
            throw new InvalidArgumentException($label . ' remote URLs must use HTTPS.');
        }

        $host = (string) ($parts['host'] ?? '');
        if ($this->isUnsafeHost($host)) {
            throw new InvalidArgumentException($label . ' URL host is not allowed.');
        }

        $extension = $this->extensionFromPath((string) ($parts['path'] ?? ''));
        $this->assertAllowedExtension($extension, $label);

        $tmp = tempnam(sys_get_temp_dir(), 'getyourbd_');
        if ($tmp === false) {
            throw new RuntimeException('Unable to allocate a temporary file for ' . $label . '.');
        }

        $target = $tmp . '.' . $extension;
        rename($tmp, $target);

        $handle = fopen($target, 'wb');
        if (!$handle) {
            throw new RuntimeException('Unable to write a temporary file for ' . $label . '.');
        }

        $downloaded = 0;
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => $this->timeout,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use ($handle, &$downloaded) {
                $downloaded += strlen($chunk);
                if ($downloaded > self::MAX_DOWNLOAD_BYTES) {
                    return 0;
                }

                return fwrite($handle, $chunk);
            },
        ]);

        $result = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        fclose($handle);

        if ($result === false || $status < 200 || $status >= 300) {
            @unlink($target);
            throw new RuntimeException($label . ' could not be downloaded: ' . ($error ?: 'HTTP ' . $status));
        }

        if (!filesize($target)) {
            @unlink($target);
            throw new RuntimeException($label . ' downloaded as an empty file.');
        }

        return $this->document($target, basename((string) ($parts['path'] ?? ('document.' . $extension))), true);
    }

    private function resolveLocal(string $reference, string $label): array
    {
        if (stripos($reference, 'file://') === 0) {
            $reference = (string) parse_url($reference, PHP_URL_PATH);
        }

        $path = $this->isAbsolutePath($reference)
            ? $reference
            : $this->localBasePath() . DIRECTORY_SEPARATOR . ltrim($reference, '/\\');

        $realPath = realpath($path);
        if ($realPath === false || !is_file($realPath) || !is_readable($realPath)) {
            throw new InvalidArgumentException($label . ' file does not exist or is not readable: ' . $reference);
        }

        $basePath = $this->configuredBaseRealPath();
        if ($basePath !== '' && !$this->pathStartsWith($realPath, $basePath)) {
            throw new InvalidArgumentException($label . ' must be inside the configured document base path.');
        }

        $extension = $this->extensionFromPath($realPath);
        $this->assertAllowedExtension($extension, $label);

        return $this->document($realPath, basename($realPath), false);
    }

    private function document(string $path, string $filename, bool $cleanup): array
    {
        $mimeType = $this->mimeType($path);

        return [
            'path' => $path,
            'filename' => $filename,
            'mimeType' => $mimeType,
            'curlFile' => new CURLFile($path, $mimeType, $filename),
            'cleanup' => $cleanup,
        ];
    }

    private function localBasePath(): string
    {
        if ($this->basePath !== '') {
            return $this->isAbsolutePath($this->basePath)
                ? rtrim($this->basePath, '/\\')
                : $this->rootDir . DIRECTORY_SEPARATOR . trim($this->basePath, '/\\');
        }

        return $this->rootDir;
    }

    private function configuredBaseRealPath(): string
    {
        if ($this->basePath === '') {
            return '';
        }

        $base = realpath($this->localBasePath());

        return $base === false ? '' : rtrim($base, '/\\');
    }

    private function isAbsolutePath(string $path): bool
    {
        return preg_match('/^[A-Za-z]:[\/\\\\]/', $path) === 1
            || strpos($path, '/') === 0
            || strpos($path, '\\\\') === 0;
    }

    private function pathStartsWith(string $path, string $basePath): bool
    {
        $path = rtrim($path, '/\\');
        $basePath = rtrim($basePath, '/\\');

        return $path === $basePath || strpos($path, $basePath . DIRECTORY_SEPARATOR) === 0;
    }

    private function extensionFromPath(string $path): string
    {
        return strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
    }

    private function assertAllowedExtension(string $extension, string $label): void
    {
        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw new InvalidArgumentException($label . ' must be a jpg, jpeg, png, or pdf file.');
        }
    }

    private function mimeType(string $path): string
    {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $mime = finfo_file($finfo, $path);
                finfo_close($finfo);
                if (is_string($mime) && $mime !== '') {
                    return $mime;
                }
            }
        }

        $extension = $this->extensionFromPath($path);
        if ($extension === 'pdf') {
            return 'application/pdf';
        }

        if ($extension === 'png') {
            return 'image/png';
        }

        return 'image/jpeg';
    }

    private function isUnsafeHost(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));
        if ($host === '' || $host === 'localhost' || substr($host, -10) === '.localhost') {
            return true;
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : gethostbynamel($host);
        if (!$ips) {
            return true;
        }

        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return true;
            }
        }

        return false;
    }
}
