# PHP + Apache で public/ を Web ルートに設定
FROM php:8.2-apache


# 必要拡張を導入（pdo_mysql, zip など）
RUN docker-php-ext-install pdo_mysql && \
a2enmod rewrite


# Apache DocumentRoot を /var/www/html/public に変更
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf && \
sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf


# 権限（開発用途）
RUN chown -R www-data:www-data /var/www/html


# Composer を使う場合は以下（任意）
# COPY --from=composer:2 /usr/bin/composer /usr/bin/composer