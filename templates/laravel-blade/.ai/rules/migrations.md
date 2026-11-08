---
paths:
  - 'database/migrations/**'
---

# Migrations

## Keep indexed strings within 191 characters
Deploys run on the shared MySQL 5.6 server, where an index key holds at most 767 bytes: 191 characters in utf8mb4. `AppServiceProvider` sets `Schema::defaultStringLength(191)`, so never give an indexed string column a longer length, and do not use JSON columns, which MySQL 5.6 does not have. SQLite and a current MySQL accept both, so the mistake passes on a laptop and fails on deploy.
