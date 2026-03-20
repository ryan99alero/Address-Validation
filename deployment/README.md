# Production Deployment

## Queue Workers (Supervisor)

The application uses Laravel queues for background processing of imports, exports, and API calls.

### Install Supervisor Config

Copy the supervisor config to the server:

```bash
sudo cp deployment/supervisor/address-validation-worker.conf /etc/supervisor/conf.d/
sudo supervisorctl reread
sudo supervisorctl update
```

### Managing Workers

```bash
# Check status
sudo supervisorctl status

# Restart workers (after code deployment)
sudo supervisorctl restart address-validation-worker:*

# Stop workers (for maintenance)
sudo supervisorctl stop address-validation-worker:*

# Start workers
sudo supervisorctl start address-validation-worker:*
```

### Viewing Logs

```bash
# Worker logs
tail -f /var/www/address-validation/storage/logs/worker.log

# Application logs
tail -f /var/www/address-validation/storage/logs/laravel.log
```

## Environment Variables

Add to production `.env`:

```env
# Disable auto-spawn workers (Supervisor handles this)
QUEUE_AUTO_SPAWN_WORKERS=false
```

## Deployment Checklist

After deploying code changes:

1. `cd /var/www/address-validation`
2. `git pull origin master`
3. `composer install --no-dev --optimize-autoloader`
4. `php artisan migrate --force`
5. `php artisan config:cache`
6. `php artisan route:cache`
7. `php artisan view:cache`
8. `sudo supervisorctl restart address-validation-worker:*`
9. `sudo systemctl reload php8.4-fpm`

## Scaling Workers

With 8 CPUs and 31GB RAM, you can safely run 2-4 workers:

- **Light load**: `numprocs=2` (default)
- **Heavy batch processing**: `numprocs=4`

Edit `/etc/supervisor/conf.d/address-validation-worker.conf` and run:
```bash
sudo supervisorctl reread
sudo supervisorctl update
```
