-- MageCode 2.0 — PostgreSQL initialization
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";
CREATE EXTENSION IF NOT EXISTS "pgcrypto";

-- Test database for `php artisan test` (U-6: Postgres-only test suite).
-- Reached directly on 5432; PgBouncer only maps the primary database.
CREATE DATABASE magecode_test;
