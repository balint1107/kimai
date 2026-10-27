FROM node:22-alpine AS frontend
WORKDIR /app
RUN corepack enable && corepack prepare pnpm@10.20.0 --activate
COPY package.json pnpm-lock.yaml ./
RUN pnpm install --frozen-lockfile
COPY webpack.config.js eslint.config.mjs ./
COPY assets ./assets
ARG APP_BASE_PATH=/kimai
ENV APP_BASE_PATH=${APP_BASE_PATH}
RUN pnpm lint:shifts && pnpm build

FROM kimai/kimai2:2.71.0@sha256:19b34043e7631b122c5e5aca27c3f3caa0bb37d2bce1478342c666bcdfe84f48
# Publish only the standalone frontend, removing inherited upstream browser bundles.
RUN rm -rf /opt/kimai/public/build /opt/kimai/public/bundles
COPY --chown=www-data:www-data src /opt/kimai/src
COPY --chown=www-data:www-data templates /opt/kimai/templates
COPY --chown=www-data:www-data translations /opt/kimai/translations
COPY --chown=www-data:www-data migrations /opt/kimai/migrations
COPY --chown=www-data:www-data config /opt/kimai/config
COPY --from=frontend --chown=www-data:www-data /app/public/build /opt/kimai/public/build
COPY deploy/accounting.yaml /opt/kimai/config/packages/prod/accounting.yaml
COPY deploy/uploads.ini /usr/local/etc/php/conf.d/zz-shift-uploads.ini
COPY deploy/source.tar.gz /opt/kimai/public/fork-source.tar.gz
