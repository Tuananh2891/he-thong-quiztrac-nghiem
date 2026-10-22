FROM php:8.3-fpm-alpine
RUN docker-php-ext-install pdo pdo_mysql \
 && addgroup -S quiz && adduser -S -G quiz quiz \
 && mkdir -p /var/log/quiz \
 && chown -R quiz:quiz /var/log/quiz
COPY --chown=quiz:quiz src/ /var/www/html/
COPY php-fpm.conf /usr/local/etc/php-fpm.d/zz-quiz.conf
USER quiz
WORKDIR /var/www/html
EXPOSE 9000
