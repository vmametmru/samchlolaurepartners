#!/usr/bin/env bash
set -euo pipefail

# rollback.sh — Roll back to a previously deployed version
# Usage: ./scripts/rollback.sh <version>
# Example: ./scripts/rollback.sh v1.1.0

VERSION="${1:-}"
if [ -z "$VERSION" ]; then
  echo "Usage: $0 <version>"
  exit 1
fi

DEPLOY_DIR="releases/${VERSION}"

if [ ! -d "$DEPLOY_DIR" ]; then
  echo "Error: Release directory not found: ${DEPLOY_DIR}"
  exit 1
fi

echo "=== Rolling back to version ${VERSION} ==="

# Restore backend
if [ -d "${DEPLOY_DIR}/api" ]; then
  echo "[1/2] Restoring backend..."
  rm -rf packages/backend/dist
  cp -r "${DEPLOY_DIR}/api" packages/backend/dist
fi

# Restore frontend
if [ -d "${DEPLOY_DIR}/dist" ]; then
  echo "[2/2] Restoring frontend dist..."
  rm -rf packages/frontend/dist
  cp -r "${DEPLOY_DIR}/dist" packages/frontend/dist
fi

TIMESTAMP=$(date -u +"%Y-%m-%dT%H:%M:%SZ")
echo "{\"rollback_to\":\"${VERSION}\",\"rolled_back_at\":\"${TIMESTAMP}\"}" >> releases/deployment.log

echo "=== Rollback complete to ${VERSION} ==="
echo "Note: Database migrations are NOT rolled back automatically."
echo "      Please review migration files and reverse manually if needed."
