# Daemon Management

## Quick Start

Use `./run_daemons.sh` to manage local daemon processes:

```
./run_daemons.sh start   # launch GSM and JSVV listeners
./run_daemons.sh status  # check if they are running
./run_daemons.sh stop    # stop both daemons
./run_daemons.sh restart # restart both
```

The script writes PID files and logs to `storage/logs/daemons`. Override
settings via environment variables:

- `PYTHON_BINARY` – path to Python interpreter
- `GSM_WEBHOOK` – backend endpoint for GSM events (default `http://127.0.0.1/api/gsm/events`)
- `JSVV_WEBHOOK` – backend endpoint for JSVV events (default `http://127.0.0.1/api/jsvv/events`)

## Production

For production deployments use supervisor configs in `supervisor/`. Include them
from your main supervisor configuration and set the environment block:

```
[include]
files = /var/www/rozhlas/supervisor/*.conf

[environment]
PYTHON_BINARY=/usr/bin/python3
PROJECT_ROOT=/var/www/rozhlas
GSM_WEBHOOK=https://app.example.cz/api/gsm/events
JSVV_WEBHOOK=https://app.example.cz/api/jsvv/events
```

Reload supervisor to apply the changes:

```
supervisorctl reread
supervisorctl update
```

Queue workers can be managed with `supervisor/queue_worker.conf`. Example:

```
[program:laravel_queue]
command=php /var/www/rozhlas/artisan queue:work --tries=3 --sleep=2
autostart=true
autorestart=true
stderr_logfile=/var/www/rozhlas/storage/logs/queue-worker.err.log
stdout_logfile=/var/www/rozhlas/storage/logs/queue-worker.log
```

Monitoring stubs are provided in `supervisor/monitoring.yml`; integrate them
with your preferred monitoring stack to keep an eye on daemon health and queue
backlog.
