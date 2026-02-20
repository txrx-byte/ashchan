# Ashchan API Gateway Dockerfile
# mTLS ServiceMesh Enabled

FROM docker.io/hyperf/hyperf:8.2-alpine-v3.19-swoole

# Install OpenSSL and CA certificates for mTLS
RUN apk add --no-cache openssl ca-certificates

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
RUN mkdir -p /etc/mtls/gateway && \
    mkdir -p /etc/mtls/ca && \
    chmod 755 /etc/mtls

# Create non-root user for security
RUN addgroup -g 1000 appgroup && \
    adduser -u 1000 -G appgroup -s /bin/sh -D appuser && \
    chown -R appuser:appgroup /app /etc/mtls

USER appuser

# Expose ports
# 9501: Public HTTP
# 9443: Public HTTPS (optional)
# 8443: Internal mTLS
EXPOSE 9501 9443 8443

# Health check
HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD wget --no-verbose --tries=1 --spider http://localhost:9501/health || exit 1

# Start the service
CMD ["php", "bin/hyperf.php", "start"]
