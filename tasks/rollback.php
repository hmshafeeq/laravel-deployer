<?php

namespace Deployer;

// Full rollback with database restore
task('rollback:full', function () {
    $confirm = askConfirmation('This will rollback code AND database. Continue?');
    if (! $confirm) {
        writeln('Rollback cancelled');

        return;
    }

    // Rollback database first
    invoke('database:restore');

    // Then rollback code
    invoke('rollback:quick');

    writeln('Full rollback completed (code + database)');
})->desc('Full rollback including database restore');
