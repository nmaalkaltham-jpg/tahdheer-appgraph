-- Tahdheer — PostgreSQL extensions (runs once on first initdb, DB: tahdheer)
-- MIP §4 (schema) / §6 (Arabic search trigram support at the SQL layer).

CREATE EXTENSION IF NOT EXISTS pg_trgm;   -- trigram similarity (search/dedup)
CREATE EXTENSION IF NOT EXISTS pgcrypto;  -- gen_random_uuid(), digest/hmac

-- pgvector — FUTURE, flag-gated (MIP §6.8 semantic/hybrid readiness).
-- Do NOT enable at launch; requires the pgvector-enabled image or package.
-- CREATE EXTENSION IF NOT EXISTS vector;
