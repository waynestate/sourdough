# The smoke gate image for scripts/smoke-shape.sh: php 8.5, composer 2 and
# node 22 in one place, so a shape can be baked and checked identically
# everywhere. Tags are pinned; bump them deliberately.
FROM php:8.5-cli-trixie

# git lets Composer fall back to source installs; ca-certificates and curl
# are needed by Composer itself; unzip lets Composer open dist archives,
# since the php base image ships without the zip extension.
RUN apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates curl git unzip \
    && rm -rf /var/lib/apt/lists/*

# Composer 2, the same pin the CI files use.
COPY --from=composer:2.10.3 /usr/bin/composer /usr/local/bin/composer

# Node 22 straight from the official multiarch image: the binary plus the npm
# module it drives, then the same shims the node image ships.
COPY --from=node:22.23.4-trixie /usr/local/bin/node /usr/local/bin/node
COPY --from=node:22.23.4-trixie /usr/local/lib/node_modules/npm /usr/local/lib/node_modules/npm
RUN ln -s ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -s ../lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx

# The container runs as root; tell Composer so instead of fighting it.
ENV COMPOSER_ALLOW_SUPERUSER=1
