## Образ приложения riveg-rent: PHP 8.2 + Apache, расширения как в CI
## (.github/workflows/ci.yml) — pdo_mysql, mysqli, mbstring, gd, curl.
FROM php:8.2-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libpng-dev \
        libjpeg62-turbo-dev \
        libwebp-dev \
        libfreetype6-dev \
        libcurl4-openssl-dev \
        default-mysql-client \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" gd pdo_mysql mysqli mbstring curl \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

## DocumentRoot проекта — public_html/, а не корень репозитория (так же,
## как web_root в .osp/project.ini у Open Server).
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public_html
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf \
    && printf '<Directory ${APACHE_DOCUMENT_ROOT}>\n\tAllowOverride All\n\tRequire all granted\n</Directory>\n' \
        > /etc/apache2/conf-available/rr-docroot.conf \
    && a2enconf rr-docroot

WORKDIR /var/www/html

COPY . .
COPY docker/entrypoint.sh /usr/local/bin/rr-entrypoint.sh
RUN chmod +x /usr/local/bin/rr-entrypoint.sh \
    && mkdir -p public_html/uploads cache/recommendations private_uploads/service_order_attachments \
    && chown -R www-data:www-data public_html/uploads cache private_uploads

ENTRYPOINT ["rr-entrypoint.sh"]
CMD ["apache2-foreground"]
