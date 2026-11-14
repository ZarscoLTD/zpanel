<?php

declare(strict_types=1);

namespace ZarsPanel;

/** File manager. Everything is resolved inside one root (a site folder or the web root); ".." and symlinks that leave the root are refused. */
final class Files
{
    public const MAX_EDIT = 2097152; // 2 MB open in the editor
    public const BLOCKED = ['/\.(?:php|phtml|phar)\d?$/i' => false]; // reserved: uploads are checked by the caller

    /** Absolute path inside $root for a user-given relative path, or an error. */
    public static function resolve(string $root, string $rel): string
    {
        $root = rtrim(realpath($root) ?: $root, '/');
        $rel = trim(str_replace("\0", '', $rel), '/');
        $parts = [];
        foreach (explode('/', $rel) as $p) {
            if ($p === '' || $p === '.') {
                continue;
            }
            if ($p === '..') {
                throw new PanelError('That path is not allowed.', 403);
            }
            $parts[] = $p;
        }
        $path = $root . ($parts ? '/' . implode('/', $parts) : '');
        $real = realpath($path);
        if ($real !== false && $real !== $root && !str_starts_with($real, $root . '/')) {
            throw new PanelError('That path is not allowed.', 403);
        }
        if ($real === false) { // a new file: its folder must be inside
            $dir = realpath(dirname($path));
            if ($dir === false || ($dir !== $root && !str_starts_with($dir, $root . '/'))) {
                throw new PanelError('That folder does not exist.', 404);
            }
        }

        return $path;
    }

    public static function list(string $root, string $rel): array
    {
        $dir = self::resolve($root, $rel);
        if (!is_dir($dir)) {
            throw new PanelError('That folder does not exist.', 404);
        }
        $out = [];
        foreach (scandir($dir) ?: [] as $f) {
            if ($f === '.' || $f === '..' || ($f === '.zp-trash' && $dir === rtrim(realpath($root) ?: $root, '/'))) { // the recycle bin has its own screen
                continue;
            }
            $p = $dir . '/' . $f;
            $out[] = ['name' => $f, 'dir' => is_dir($p), 'size' => is_dir($p) ? null : (int) @filesize($p), 'modified' => gmdate('c', (int) @filemtime($p)), 'mode' => substr(sprintf('%o', @fileperms($p)), -4)];
        }
        usort($out, fn ($a, $b) => [$b['dir'], strtolower($a['name'])] <=> [$a['dir'], strtolower($b['name'])]);

        return $out;
    }

    public static function read(string $root, string $rel): array
    {
        $p = self::resolve($root, $rel);
        if (!is_file($p)) {
            throw new PanelError('File not found.', 404);
        }
        if (filesize($p) > self::MAX_EDIT) {
            throw new PanelError('This file is too big to edit here (over 2 MB).', 422);
        }
        $c = file_get_contents($p);
        if (str_contains($c, "\0")) {
            throw new PanelError('This looks like a binary file, it cannot be edited as text.', 422);
        }

        return ['content' => $c, 'size' => strlen($c)];
    }

    public static function write(string $root, string $rel, string $content): void
    {
        $p = self::resolve($root, $rel);
        if (is_dir($p)) {
            throw new PanelError('That is a folder.', 422);
        }
        Exec::write($p, $content, 0644);
        self::adopt($root, $p);
    }

    /**
     * What the panel (root) makes in a site - written, uploaded, unzipped, new folders - belongs to the site's own user
     * (the owner of the site's top folder), so PHP of that site can change it and other accounts cannot.
     */
    public static function adopt(string $root, string $path): void
    {
        $www = rtrim(Config::www(), '/') . '/';
        $real = realpath($root) ?: $root;
        if (!str_starts_with($real . '/', $www)) {
            return;
        }
        $top = $www . explode('/', substr($real, strlen($www)))[0];
        $uid = @fileowner($top);
        $gid = @filegroup($top);
        if ($uid === false || $gid === false || (function_exists('posix_geteuid') && posix_geteuid() !== 0)) {
            return; // only root can give files away (tests and dry runs are not root)
        }
        $walk = function (string $p) use (&$walk, $uid, $gid) {
            @lchown($p, $uid);
            @lchgrp($p, $gid);
            if (is_dir($p) && !is_link($p)) {
                foreach (scandir($p) ?: [] as $f) {
                    $f !== '.' && $f !== '..' && $walk($p . '/' . $f);
                }
            }
        };
        $walk($path);
    }

    public static function mkdir(string $root, string $rel): void
    {
        $p = self::resolve($root, $rel);
        if (file_exists($p)) {
            throw new PanelError('It already exists.', 422);
        }
        mkdir($p, 0755);
        self::adopt($root, $p);
    }

    public static function delete(string $root, string $rel): void
    {
        $p = self::resolve($root, $rel);
        if ($p === rtrim(realpath($root) ?: $root, '/')) {
            throw new PanelError('The main folder cannot be deleted here.', 422);
        }
        is_dir($p) && !is_link($p) ? self::rrmdir($p) : @unlink($p);
    }

    public static function rename(string $root, string $from, string $to): void
    {
        $a = self::resolve($root, $from);
        $b = self::resolve($root, $to);
        if (file_exists($b)) {
            throw new PanelError('The new name already exists.', 422);
        }
        rename($a, $b);
    }

    public static function chmod(string $root, string $rel, string $mode): void
    {
        if (!preg_match('/^[0-7]{3,4}$/', $mode)) {
            throw new PanelError('The permissions must look like 755 or 644.', 422);
        }
        chmod(self::resolve($root, $rel), octdec($mode));
    }

    public static function zip(string $root, string $rel, string $zipRel): void
    {
        $src = self::resolve($root, $rel);
        $dest = self::resolve($root, $zipRel);
        $z = new \ZipArchive();
        if ($z->open($dest, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new PanelError('The zip file could not be created.', 500);
        }
        $base = dirname($src);
        $add = function (string $p) use (&$add, $z, $base, $dest) {
            if ($p === $dest) {
                return;
            }
            $name = ltrim(substr($p, strlen($base)), '/');
            if (is_dir($p) && !is_link($p)) {
                $z->addEmptyDir($name);
                foreach (scandir($p) ?: [] as $f) {
                    $f !== '.' && $f !== '..' && $add($p . '/' . $f);
                }
            } elseif (is_file($p)) {
                $z->addFile($p, $name);
            }
        };
        $add($src);
        $z->close();
        self::adopt($root, $dest);
    }

    public static function unzip(string $root, string $zipRel, string $toRel): int
    {
        $zip = self::resolve($root, $zipRel);
        $to = self::resolve($root, $toRel);
        $z = new \ZipArchive();
        if ($z->open($zip) !== true) {
            throw new PanelError('This is not a valid zip file.', 422);
        }
        for ($i = 0; $i < $z->numFiles; $i++) { // a zip entry must not climb out of the target folder
            $n = $z->getNameIndex($i);
            if (str_contains($n, '..') || str_starts_with($n, '/')) {
                $z->close();
                throw new PanelError('The zip file contains unsafe paths.', 422);
            }
        }
        Config::ensure($to);
        $z->extractTo($to);
        $count = $z->numFiles;
        $z->close();
        self::adopt($root, $to);

        return $count;
    }

    public static function rrmdir(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            $p = $dir . '/' . $f;
            is_dir($p) && !is_link($p) ? self::rrmdir($p) : @unlink($p);
        }
        @rmdir($dir);
    }

    public static function size(string $path): int
    {
        if (is_file($path)) {
            return (int) filesize($path);
        }
        $n = 0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)) as $f) {
            $n += $f->isFile() ? $f->getSize() : 0;
        }

        return $n;
    }
}
