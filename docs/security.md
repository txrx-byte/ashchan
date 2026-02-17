# Security

## Principles
- Least privilege: minimal service-to-service permissions.
- Defense in depth: layered controls at edge, gateway, and services.
- Fail securely: default-deny policies.

## Service-to-Service Auth
- mTLS for all internal HTTP traffic.
- Service identity via certificates.

## Secrets Management
- Environment variables for local dev.
- Secrets manager for production (Vault, K8s Secrets).
- Rotation schedule for credentials.

## Input Validation
- Strict schema validation at gateway and service boundaries.
- Sanitization for user-generated content.
- Content Security Policy headers.

## Audit Logging
- Structured logs with correlation IDs.
- Immutable audit log for compliance actions.
- Tamper-evident hashing for sensitive operations.

## Data Encryption
- TLS in transit.
- Encrypted at rest for sensitive fields (PII, consents).
- Key management via KMS.

## Rate Limiting & DDoS Protection
- Edge WAF (Cloudflare, AWS Shield).
- Gateway-level rate limits.
- Per-service circuit breakers.

## Incident Response
- Runbooks for common attacks (DDoS, scraping, spam waves).
- Kill switches for individual boards or features.
- Log export for forensics.
