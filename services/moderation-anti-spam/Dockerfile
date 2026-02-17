FROM hyperf/hyperf:8.2-alpine-v3.19-swoole
WORKDIR /app

COPY composer.json ./
RUN composer install --no-dev --optimize-autoloader --no-scripts 2>/dev/null || composer install --no-dev --no-scripts

COPY . .

RUN composer dump-autoload --optimize

RUN mkdir -p /app/runtime

EXPOSE 9506
CMD ["php", "bin/hyperf.php", "start"]
