#!/usr/bin/env bash
set -euo pipefail

# deploy.sh — Build, migrate, and deploy a new version
# Usage: ./scripts/deploy.sh <version>
# Example: ./scripts/deploy.sh v1.2.3

VERSION="${1:-}"
if [ -z "$VERSION" ]; then
  echo "Usage: $0 <version>"
  exit 1
fi

DEPLOY_DIR="releases/${VERSION}"
echo "=== Deploying version ${VERSION} ==="

# 1. Build backend
echo "[1/4] Building backend..."
npm run build --workspace=packages/backend

# 2. Build frontend
echo "[2/4] Building frontend..."
npm run build --workspace=packages/frontend

# 3. Copy artifacts
echo "[3/4] Copying build artifacts to ${DEPLOY_DIR}..."
mkdir -p "${DEPLOY_DIR}/api" "${DEPLOY_DIR}/dist"
cp -r packages/backend/dist/* "${DEPLOY_DIR}/api/"
cp -r packages/frontend/dist/* "${DEPLOY_DIR}/dist/"
cp packages/backend/package.json "${DEPLOY_DIR}/api/"

# 4. Run migrations
echo "[4/4] Running database migrations..."
npm run migrate --workspace=packages/backend

# 5. Log version
TIMESTAMP=$(date -u +"%Y-%m-%dT%H:%M:%SZ")
echo "{\"version\":\"${VERSION}\",\"deployed_at\":\"${TIMESTAMP}\"}" >> releases/deployment.log

echo "=== Deploy complete: ${VERSION} ==="
