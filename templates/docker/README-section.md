## Run it locally with Docker

This app ships its own Docker setup. It works natively on Windows, macOS and Linux (no WSL2 needed) and is checked on amd64 and arm64.

    composer install        # or skip it: the container can run it
    cp .env.example .env
    composer dev-up         # docker compose up --build
    docker compose exec app composer install
    docker compose exec app php artisan key:generate
    docker compose exec app php artisan migrate

The app is at http://localhost:8000. `composer dev-down` stops it and `composer dev-shell` opens a shell in the app container.

The base images are pinned by digest in `Dockerfile`; the `FROM` lines are the single place to swap in SQUIRE's Citadel-parity image
when it exists. The database is MySQL 8.4 LTS. Citadel still runs MySQL 5.6, which Laravel 13 cannot run on, so local parity with
Citadel's database is not possible; PROCTOR's mysql56 check covers the gap.

Production image: `docker build --target prod --secret id=composer_auth,src=auth.json -t myapp .` Playbook credentials go in as a
BuildKit secret (a Composer auth file), never a build argument. The image runs as a non-root user and listens on 8080.
