═══════════════════════════════════════════════════════════
                 DEPLOYMENT CONFIRMATION
═══════════════════════════════════════════════════════════

  Environment:  staging
  Server:       54.*.*.*
  User:         ubuntu
  Deploy Path:  /var/www/devtime.boxinc.app

═══════════════════════════════════════════════════════════

   Do you want to continue with this deployment? (yes/no) [yes]:
 >

[staging] Checking server resources...
[staging] Disk usage: 55%
[staging] Memory usage: 50%
[staging] ✓ Server resources check passed
[staging] ✓ All health checks passed

[staging] 🚀 Starting deployment to staging

[staging] ✓ Deployment locked
[staging] ✓ Deployment structure ready
[staging] ✓ Release 202512.3 created
[staging] Building frontend assets...
[staging] ✓ Assets built successfully

═══════════════════════════════════════════════════════════
  SYNC DIFFERENCE - FILES TO DEPLOY
═══════════════════════════════════════════════════════════

[staging]   ✨ No changes detected - everything is already in sync!


═══════════════════════════════════════════════════════════
  UPLOADING FILES TO SERVER
═══════════════════════════════════════════════════════════

[staging] Syncing files to server...
[staging] Syncing files to release...
[staging] ✓ Files synced successfully
[staging] ✓ Files synced successfully

[staging] ✓   Files uploaded successfully!

[staging] ✓ Shared directories linked
[staging] ✓ Writable permissions set
[staging] Installing Composer dependencies...
[staging] ✓ Composer dependencies installed
[staging] ✓ Module permissions fixed
[staging] Running database migrations...
[staging] Running artisan migrate
[staging] ✓ Migrations completed
[staging] ✓ Release symlinked as current
[staging] Cleaning up old releases (keeping 3)...
[staging] ✓ Cleanup complete. 3 releases remain

[staging] ✓ ✅ Deployment completed successfully!
[staging] ✓ 🎉 Release 202512.3 is now live on staging

[staging] Running post-deployment optimizations...

[staging] Running artisan storage:link
[staging] ✓ Storage link created
[staging] Running artisan config:cache
[staging] ✓ Configuration cached
[staging] Running artisan view:cache
[staging] ✓ Views cached
[staging] Running artisan route:cache
[staging] ✓ Routes cached
[staging] Running artisan optimize
[staging] ✓ Application optimized
[staging] Running artisan queue:restart
[staging] ✓ Queue workers restarted
[staging] Restarting PHP-FPM...
[staging] ✓ Restarted php8.3-fpm
[staging] ✓ Restarted php8.4-fpm
[staging] Reloading Nginx...
[staging] ✓ Nginx reloaded
[staging] Reloading Supervisor...
[staging] ✓ Supervisor reloaded

[staging] ✓ ✅ Optimization completed
