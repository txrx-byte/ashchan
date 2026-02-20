# Ashchan Media/Uploads Service Dockerfile
# mTLS ServiceMesh Enabled

FROM docker.io/hyperf/hyperf:8.2-alpine-v3.19-swoole

# Install OpenSSL, CA certificates, and image processing libraries for mTLS and media handling
RUN apk add --no-cache openssl ca-certificates gd imagemagick ffmpeg

WORKDIR /app

# Copy composer.json and install dependencies
COPY composer.json ./
RUN composer install --optimize-autoloader --no-scripts 2>/dev/null || composer install --no-scripts

# Copy application code
COPY . .

# Optimize autoloader
RUN composer dump-autoload --optimize

# Create runtime directory
RUN mkdir -p /app/runtime

# Create mTLS certificate directory
RUN mkdir -p /etc/mtls/media && \
    mkdir -p /etc/mtls/ca && \
    chmod 755 /etc/mtls

# Create non-root user for security
RUN addgroup -g 1000 appgroup && \
    adduser -u 1000 -G appgroup -s /bin/sh -D appuser && \
    chown -R appuser:appgroup /app /etc/mtls

USER appuser

# Expose ports
# 9504: HTTP (development only)
# 8443: Internal mTLS
EXPOSE 9504 8443

# Health check
HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD wget --no-verbose --tries=1 --spider http://localhost:9504/health || exit 1

# Start the service
CMD ["php", "bin/hyperf.php", "start"]
