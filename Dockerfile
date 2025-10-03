# PHP + Apache で public/ を Web ルートに設定
FROM php:8.2-apache


# 必要拡張を導入（pdo_mysql, gd, zip など）
RUN apt-get update && apt-get install -y \
    libfreetype6-dev \
    libjpeg62-turbo-dev \
    libpng-dev \
    libzip-dev \
    mariadb-client \
    cron \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo_mysql gd zip \
    && a2enmod rewrite


# Apache DocumentRoot を /var/www/html/public に変更
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf && \
sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf


# 権限（開発用途）
RUN chown -R www-data:www-data /var/www/html


# Composer をインストール
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Composer の依存関係をインストール
COPY composer.json ./
RUN composer update --no-dev --optimize-autoloader