FROM alpine:3.19

# Install supercronic, bash, jq, docker-cli for the ingest script
RUN apk add --no-cache \
    bash \
    curl \
    jq \
    docker-cli \
    && curl -fsSLO https://github.com/aptible/supercronic/releases/download/v0.2.29/supercronic-linux-amd64 \
    && chmod +x supercronic-linux-amd64 \
    && mv supercronic-linux-amd64 /usr/local/bin/supercronic

# Set working directory
WORKDIR /app


# Default command (will be overridden by compose)
CMD ["/usr/local/bin/supercronic", "/etc/cron/crontab"]

