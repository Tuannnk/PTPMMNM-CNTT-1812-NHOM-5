-- Run once in the Supabase SQL Editor to persist PHP sessions across Vercel instances.
CREATE TABLE IF NOT EXISTS app_sessions (
    session_id text PRIMARY KEY,
    payload text NOT NULL,
    last_activity bigint NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_app_sessions_last_activity
    ON app_sessions (last_activity);
