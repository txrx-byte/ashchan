# Database Migrations

This directory contains PostgreSQL migrations for all Ashchan services.

## Running Migrations

```bash
# From service directory
php bin/hyperf.php db:migrate

# Seed data
php bin/hyperf.php db:seed
```

## Migration Conventions

- Filename format: `YYYYMMDDHHMMSS_create_table_name.php`
- One table per migration file
- Use `up()` and `down()` methods
- Always provide rollback capability
