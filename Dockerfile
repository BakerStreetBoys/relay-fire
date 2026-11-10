FROM php:8.3-cli

# The official image ships with curl; install it only if it is missing.
RUN php -m | grep -qi '^curl$' || ( \
      apt-get update \
      && apt-get install -y --no-install-recommends libcurl4-openssl-dev \
      && docker-php-ext-install curl \
      && rm -rf /var/lib/apt/lists/* )

WORKDIR /app
COPY relay.php /app/relay.php

# 8 worker processes, so concurrent requests are not serialized.
ENV PHP_CLI_SERVER_WORKERS=8

# relay.php is the router script, so every request goes through it.
# stderr is discarded on purpose: the built-in server logs each request's
# full URL there, and the URL contains the secret.
CMD ["sh", "-c", "exec php -S 0.0.0.0:${PORT:-8080} /app/relay.php 2>/dev/null"]
