#!/usr/bin/env bash
set -euo pipefail

# migrate.sh — Run pending database migrations only
echo "=== Running database migrations ==="
npm run migrate --workspace=packages/backend
echo "=== Migrations complete ==="
