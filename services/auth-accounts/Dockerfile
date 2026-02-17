FROM hyperf/hyperf:8.2-alpine-v3.19-swoole
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader
COPY . .
CMD ["php", "bin/hyperf.php", "start"]
