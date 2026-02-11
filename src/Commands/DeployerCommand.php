<?php

namespace Shaf\LaravelDeployer\Commands;

use Illuminate\Console\Command;

class DeployerCommand extends Command
{
    protected $signature = 'deployer';

    protected $description = 'List all deployer commands';

    public function handle(): int
    {
        // Box width: 60 chars total (58 inner + 2 borders)
        $w = 58;

        $commands = [
            ['deployer:release', '{env}', 'New release (full deploy)'],
            ['deployer:sync', '{env}', 'Sync files to release'],
            ['deployer:rollback', '{env}', 'Rollback to previous release'],
            ['deployer:server', '{act}', 'Server management'],
            ['deployer:db', '{act}', 'Database backup/restore'],
            ['deployer:setup', '{act}', 'Initial setup & config'],
            ['deployer:diagnose', '{env}', 'Diagnose deployment issues'],
        ];

        $strategies = [
            ['deployer:sync staging', 'Full rsync scan'],
            ['deployer:sync staging --dirty', 'Uncommitted changes'],
            ['deployer:sync staging --since=abc', 'Since a commit'],
            ['deployer:sync staging --branch', 'vs main branch'],
        ];

        $this->newLine();
        $this->line('<fg=cyan>╔'.str_repeat('═', $w).'╗</>');
        $this->line('<fg=cyan>║</>'.str_pad('Laravel Deployer Commands', $w, ' ', STR_PAD_BOTH).'<fg=cyan>║</>');
        $this->line('<fg=cyan>╠'.str_repeat('═', $w).'╣</>');
        $this->line('<fg=cyan>║</>'.str_repeat(' ', $w).'<fg=cyan>║</>');

        $cmdCol = 18; // width for command name
        $argCol = 5;  // width for {env}/{act}

        foreach ($commands as [$cmd, $arg, $desc]) {
            $cmdPad = str_repeat(' ', max(1, $cmdCol - mb_strlen($cmd)));
            $argPad = str_repeat(' ', max(1, $argCol - mb_strlen($arg)));
            $visible = '  '.$cmd.$cmdPad.$arg.$argPad.$desc;
            $pad = $w - mb_strlen($visible);
            $this->line(
                '<fg=cyan>║</>  <fg=green>'.$cmd.'</>'.$cmdPad.'<fg=gray>'.$arg.'</>'.$argPad.$desc.str_repeat(' ', max(0, $pad)).'<fg=cyan>║</>'
            );
        }

        $this->line('<fg=cyan>║</>'.str_repeat(' ', $w).'<fg=cyan>║</>');
        $this->line('<fg=cyan>╠'.str_repeat('═', $w).'╣</>');

        $visible = ' Sync Strategies:';
        $pad = $w - mb_strlen($visible);
        $this->line('<fg=cyan>║</> <fg=gray>Sync Strategies:</>'.str_repeat(' ', max(0, $pad)).'<fg=cyan>║</>');

        $stratCol = 34; // width for strategy command

        foreach ($strategies as [$cmd, $desc]) {
            $sPad = str_repeat(' ', max(1, $stratCol - mb_strlen($cmd)));
            $visible = '  '.$cmd.$sPad.$desc;
            $pad = $w - mb_strlen($visible);
            $this->line(
                '<fg=cyan>║</>  <fg=yellow>'.$cmd.'</>'.$sPad.$desc.str_repeat(' ', max(0, $pad)).'<fg=cyan>║</>'
            );
        }

        $this->line('<fg=cyan>║</>'.str_repeat(' ', $w).'<fg=cyan>║</>');
        $this->line('<fg=cyan>╚'.str_repeat('═', $w).'╝</>');
        $this->newLine();

        return self::SUCCESS;
    }
}
