<?php

namespace Shaf\LaravelDeployer\Concerns;

trait ExecutesCommands
{
    /**
     * Check if a path exists (file, directory, or symlink)
     */
    protected function pathExists(string $path): bool
    {
        return $this->test("[ -e {$path} ]");
    }

    /**
     * Check if a directory exists
     */
    protected function directoryExists(string $path): bool
    {
        return $this->test("[ -d {$path} ]");
    }

    /**
     * Check if a file exists
     */
    protected function fileExists(string $path): bool
    {
        return $this->test("[ -f {$path} ]");
    }

    /**
     * Check if a symlink exists
     */
    protected function symlinkExists(string $path): bool
    {
        return $this->test("[ -L {$path} ]");
    }

    /**
     * Check if a directory is empty
     */
    protected function directoryIsEmpty(string $path): bool
    {
        return !$this->test("[ -d {$path} ] && [ \"\$(ls -A {$path})\" ]");
    }

    /**
     * Create a directory
     */
    protected function createDirectory(string $path): void
    {
        $this->run("mkdir -p {$path}");
    }

    /**
     * Remove a file
     */
    protected function removeFile(string $path): void
    {
        $this->run("rm -f {$path}");
    }

    /**
     * Remove a directory recursively
     */
    protected function removeDirectory(string $path): void
    {
        $this->run("rm -rf {$path}");
    }

    /**
     * Create a symlink
     */
    protected function createSymlink(string $target, string $link, bool $relative = true): void
    {
        $relativeFlag = $relative ? '--relative ' : '';
        $this->run("ln -nfs {$relativeFlag}{$target} {$link}");
    }

    /**
     * Read the target of a symlink
     */
    protected function readSymlink(string $path): string
    {
        return trim($this->run("readlink {$path}"));
    }

    /**
     * Change file permissions
     */
    protected function chmod(string $path, string $mode): void
    {
        $this->run("chmod {$mode} {$path}");
    }

    /**
     * Change ownership
     */
    protected function chown(string $path, string $owner, bool $recursive = false): void
    {
        $recursiveFlag = $recursive ? '-R ' : '';
        $this->run("chown {$recursiveFlag}{$owner} {$path}");
    }

    /**
     * Write content to a file
     */
    protected function writeFile(string $path, string $content): void
    {
        $escapedContent = escapeshellarg($content);
        $this->run("echo {$escapedContent} > {$path}");
    }

    /**
     * Append content to a file
     */
    protected function appendToFile(string $path, string $content): void
    {
        $escapedContent = escapeshellarg($content);
        $this->run("echo {$escapedContent} >> {$path}");
    }

    /**
     * Read a file
     */
    protected function readFile(string $path): string
    {
        return trim($this->run("cat {$path}"));
    }

    /**
     * Touch a file (create if not exists, update timestamp if exists)
     */
    protected function touch(string $path): void
    {
        $this->run("touch {$path}");
    }

    /**
     * Move/rename a file or directory
     */
    protected function move(string $source, string $destination, bool $noTargetDirectory = false): void
    {
        $flag = $noTargetDirectory ? '-T ' : '';
        $this->run("mv {$flag}{$source} {$destination}");
    }

    /**
     * Copy a file or directory
     */
    protected function copy(string $source, string $destination, bool $recursive = false): void
    {
        $flag = $recursive ? '-r ' : '';
        $this->run("cp {$flag}{$source} {$destination}");
    }
}
