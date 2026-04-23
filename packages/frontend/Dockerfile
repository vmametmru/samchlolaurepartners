FROM node:20-alpine AS builder
WORKDIR /app
COPY package*.json ./
COPY packages/shared ./packages/shared
COPY packages/frontend/package*.json ./packages/frontend/
RUN npm install --workspace=packages/shared --workspace=packages/frontend
COPY packages/frontend ./packages/frontend
RUN npm run build --workspace=packages/frontend

FROM nginx:alpine
COPY --from=builder /app/packages/frontend/dist /usr/share/nginx/html
COPY packages/frontend/nginx.conf /etc/nginx/conf.d/default.conf
EXPOSE 80
