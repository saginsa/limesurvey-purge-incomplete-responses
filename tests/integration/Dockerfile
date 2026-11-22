# LimeSurvey for integration tests of the plugin. Not for production use.
FROM php:8.3-apache
RUN apt-get update \
 && apt-get install -y --no-install-recommends libpng-dev libjpeg62-turbo-dev libfreetype6-dev libzip-dev libicu-dev \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j"$(nproc)" pdo_mysql gd zip intl \
 && rm -rf /var/lib/apt/lists/*
# The LimeSurvey source archive (GitHub tag archive), prepared by run.sh
COPY limesurvey.tar.gz /tmp/limesurvey.tar.gz
RUN tar xzf /tmp/limesurvey.tar.gz -C /var/www/html --strip-components=1 \
 && rm /tmp/limesurvey.tar.gz \
 && chown -R www-data:www-data /var/www/html/tmp /var/www/html/upload /var/www/html/application/config
COPY config.php /var/www/html/application/config/config.php
RUN chown www-data:www-data /var/www/html/application/config/config.php
