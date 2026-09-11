<?php
if (!defined("WHMCS")) { die("This file cannot be accessed directly"); }

/**
 * Caminhos de certificado, debug e cache fora do webroot.
 */
class NfseStorage
{
    public const DEFAULT_RELATIVE = '{ROOTDIR}/../nfse_nacional_data';

    public static function defaultBase(): string
    {
        return self::canonicalize(self::expand(self::DEFAULT_RELATIVE));
    }

    public static function expand(string $path): string
    {
        if (defined('ROOTDIR')) {
            $path = str_replace(['{ROOTDIR}', '%ROOTDIR%'], (string)ROOTDIR, $path);
        }
        return $path;
    }

    public static function canonicalize(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        if ($path === '') {
            return '';
        }

        $prefix = '';
        if (preg_match('#^([A-Za-z]:)(/.*)$#', $path, $m)) {
            $prefix = strtoupper($m[1]);
            $path = $m[2];
        } elseif (preg_match('#^([A-Za-z]:)$#', $path, $m)) {
            return strtoupper($m[1]) . '/';
        }

        $absolute = ($prefix !== '') || (isset($path[0]) && $path[0] === '/');
        $parts = explode('/', $path);
        $stack = [];
        foreach ($parts as $i => $part) {
            if ($part === '' || $part === '.') {
                if ($part === '' && $i === 0 && $absolute && $prefix === '') {
                    $stack[] = '';
                }
                continue;
            }
            if ($part === '..') {
                if (count($stack) === 0) {
                    continue;
                }
                $last = $stack[count($stack) - 1];
                if ($last === '') {
                    continue;
                }
                array_pop($stack);
                continue;
            }
            $stack[] = $part;
        }

        $joined = implode('/', $stack);
        if ($prefix !== '') {
            $joined = $prefix . '/' . ltrim($joined, '/');
        } elseif ($absolute && $joined === '') {
            $joined = '/';
        } elseif ($absolute && isset($joined[0]) && $joined[0] !== '/') {
            $joined = '/' . $joined;
        }

        return str_replace('/', DIRECTORY_SEPARATOR, $joined);
    }

    public static function isOutsideWebRoot(string $path): bool
    {
        if ($path === '' || !defined('ROOTDIR') || ROOTDIR === '') {
            return $path !== '';
        }

        $root = self::normalizeForCompare(self::canonicalize((string)ROOTDIR));
        $base = self::normalizeForCompare(self::canonicalize($path));
        if ($root === '' || $base === '') {
            return false;
        }
        if ($base === $root) {
            return false;
        }
        return !str_starts_with($base, $root . '/');
    }

    /**
     * @return array{ok:bool,base:string,error:?string}
     */
    public static function resolveBase(array $config): array
    {
        $configured = trim((string)($config['storage_path'] ?? ''));
        if ($configured === '') {
            $configured = self::DEFAULT_RELATIVE;
        }

        $base = self::canonicalize(self::expand($configured));
        if ($base === '') {
            return ['ok' => false, 'base' => '', 'error' => 'Caminho de armazenamento vazio.'];
        }
        if (!self::isOutsideWebRoot($base)) {
            return [
                'ok'    => false,
                'base'  => $base,
                'error' => 'Caminho de armazenamento deve ficar fora do diretorio do WHMCS (webroot).',
            ];
        }

        return ['ok' => true, 'base' => $base, 'error' => null];
    }

    public static function baseDir(array $config): string
    {
        $resolved = self::resolveBase($config);
        if ($resolved['ok']) {
            return $resolved['base'];
        }

        $fallback = self::defaultBase();
        if ($fallback !== '' && self::isOutsideWebRoot($fallback)) {
            return $fallback;
        }

        return rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . 'nfse_nacional_data';
    }

    public static function certDir(array $config): string
    {
        return self::baseDir($config) . DIRECTORY_SEPARATOR . 'certs';
    }

    public static function debugDir(array $config): string
    {
        return self::baseDir($config) . DIRECTORY_SEPARATOR . 'debug';
    }

    public static function cacheDir(array $config): string
    {
        return self::baseDir($config) . DIRECTORY_SEPARATOR . 'cache';
    }

    public static function protectDir(string $dir): void
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        if (!is_dir($dir)) {
            return;
        }
        file_put_contents($dir . DIRECTORY_SEPARATOR . '.htaccess', "Require all denied\nDeny from all\n");
        file_put_contents($dir . DIRECTORY_SEPARATOR . 'index.php', "<?php // silence");
    }

    private static function normalizeForCompare(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $path = rtrim($path, '/');
        if (preg_match('#^[A-Za-z]:#', $path)) {
            $path = strtolower($path);
        }
        return $path;
    }
}
