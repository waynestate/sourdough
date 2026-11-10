## Run it locally with Docker

This app ships its own Docker setup. It works natively on Windows, macOS and Linux (no WSL2 needed) and is checked on amd64 and arm64.

    cp .env.example .env
    composer dev-up         # docker compose up --build; the first start runs composer install for you
    docker compose exec app php artisan migrate
    docker compose exec app composer test

The first start also generates the application key; restart the app container (`docker compose restart app`) only if you change `.env` while it runs. It also runs the migrations on every start, so the sessions table exists. When the app has a vite service (the Blade + Vue and Inertia shapes), that service runs `npm install` on its first start, then the Vite dev server on port 5173; the pages load once it is up.

The dev container runs as root on purpose (it is local only, and Docker Desktop bind mounts are root-owned), so files it creates, such as `vendor/`, may be root-owned on a Linux host; run `sudo chown -R "$USER" .` once if you need to edit them.

The app is at http://localhost:8000. `composer dev-down` stops it and `composer dev-shell` opens a shell in the app container.

The base images are pinned by digest in `Dockerfile`. The database is MySQL 8.4 LTS; Citadel still runs MySQL 5.6, which is
older than Laravel 13 supports, and PROCTOR's mysql56 check covers that gap. To run the same folder on Citadel's own image, proxy
and MySQL 5.6.51, use Parity (the "Run it like Citadel" steps in this README). Its first deployment reads only `.env`, and this
`.env` points at the `mysql` service here, so the release stops at the migrate step with `Access denied`: set
`DB_CONNECTION=sqlite` in `.env` for Parity runs and put `mysql` back for `composer dev-up`.

Production image: `docker build --target prod --secret id=composer_auth,src=auth.json -t myapp .` Playbook credentials go in as a
BuildKit secret (a Composer auth file), never a build argument. The image runs as a non-root user and listens on 8080.
