FROM postgres:18.7-alpine@sha256:0115267ab5a509022df9319c77be365cef335adb71556c75f60f5360ee247e71

# El entrypoint oficial invoca gosu únicamente para iniciar PostgreSQL
# como el usuario postgres. su-exec conserva esa transición en Alpine.
RUN set -eux; \
    apk upgrade --no-cache; \
    apk add --no-cache su-exec; \
    ln -sf /sbin/su-exec /usr/local/bin/gosu; \
    test "$(gosu postgres id -u)" = "$(id -u postgres)"; \
    test "$(gosu postgres id -g)" = "$(id -g postgres)"
