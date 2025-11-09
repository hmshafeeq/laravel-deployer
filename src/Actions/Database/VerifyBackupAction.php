<?php

namespace Shaf\LaravelDeployer\Actions\Database;

use Shaf\LaravelDeployer\Support\Abstract\DatabaseAction;

class VerifyBackupAction extends DatabaseAction
{
    public function execute(string $backupFile): void
    {
        $this->checkFileExists($backupFile);
        $this->checkFileSize($backupFile);
        $this->displaySuccess($backupFile);
    }

    protected function checkFileExists(string $file): void
    {
        $this->writeln("run test -f {$file} && echo 'OK' || echo 'FAIL'");
        $exists = trim($this->cmd("test -f {$file} && echo 'OK' || echo 'FAIL'"));

        if (!empty($exists)) {
            $this->writeln($exists);
        }

        if ($exists !== 'OK') {
            throw new \RuntimeException("Backup file was not created: {$file}");
        }
    }

    protected function checkFileSize(string $file): void
    {
        $this->writeln("run stat -c%s {$file} 2>/dev/null || stat -f%z {$file} 2>/dev/null || echo 0");
        $fileSize = (int) trim($this->cmd("stat -c%s {$file} 2>/dev/null || stat -f%z {$file} 2>/dev/null || echo 0"));
        $this->writeln($fileSize);

        if ($fileSize < 100) {
            throw new \RuntimeException("Backup file is too small ({$fileSize} bytes), backup likely failed");
        }
    }

    protected function displaySuccess(string $file): void
    {
        $this->writeln("");
        $this->writeln("✅ Database backup completed successfully!");

        $this->writeln("run ls -lh {$file} | awk '{print \$5}'");
        $fileSizeHuman = trim($this->cmd("ls -lh {$file} | awk '{print \$5}'"));
        $this->writeln($fileSizeHuman);

        $this->writeln("📁 Location: {$file}");
        $this->writeln("📊 Size: {$fileSizeHuman}");
    }
}
