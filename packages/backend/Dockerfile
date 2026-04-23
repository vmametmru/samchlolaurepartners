FROM node:20-alpine AS builder
WORKDIR /app
COPY package*.json ./
COPY packages/shared ./packages/shared
COPY packages/backend/package*.json ./packages/backend/
RUN npm install --workspace=packages/shared --workspace=packages/backend
COPY packages/backend ./packages/backend
RUN npm run build --workspace=packages/backend

FROM node:20-alpine
WORKDIR /app
COPY --from=builder /app/packages/backend/dist ./dist
COPY --from=builder /app/node_modules ./node_modules
COPY packages/backend/package.json .
EXPOSE 3000
CMD ["node", "dist/index.js"]
