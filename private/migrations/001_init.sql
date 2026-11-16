-- DynaPulse initial schema (SQLite)

CREATE TABLE IF NOT EXISTS schema_migrations (
    version    TEXT PRIMARY KEY,
    applied_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS settings (
    key        TEXT PRIMARY KEY,
    value      TEXT NOT NULL DEFAULT '',
    updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS domains (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    domain          TEXT NOT NULL UNIQUE,
    label           TEXT NOT NULL DEFAULT '',
    timezone        TEXT NOT NULL DEFAULT 'UTC',
    enabled         INTEGER NOT NULL DEFAULT 1,
    poll_enabled    INTEGER NOT NULL DEFAULT 1,
    last_polled_at  TEXT,
    last_poll_error TEXT,
    created_at      TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS users (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    domain        TEXT NOT NULL,
    username      TEXT NOT NULL,
    email         TEXT NOT NULL DEFAULT '',
    display_name  TEXT NOT NULL DEFAULT '',
    role          TEXT NOT NULL DEFAULT 'manager',
    active        INTEGER NOT NULL DEFAULT 1,
    last_login_at TEXT,
    created_at    TEXT NOT NULL,
    UNIQUE (domain, username)
);

CREATE TABLE IF NOT EXISTS keywords (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    domain_id      INTEGER REFERENCES domains (id) ON DELETE CASCADE,
    label          TEXT NOT NULL,
    pattern        TEXT NOT NULL,
    match_type     TEXT NOT NULL DEFAULT 'phrase',
    fields         TEXT NOT NULL DEFAULT '["summary","topics","transcript"]',
    severity       TEXT NOT NULL DEFAULT 'medium',
    case_sensitive INTEGER NOT NULL DEFAULT 0,
    active         INTEGER NOT NULL DEFAULT 1,
    notes          TEXT NOT NULL DEFAULT '',
    created_at     TEXT NOT NULL,
    updated_at     TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS keywords_domain_idx ON keywords (domain_id, active);

CREATE TABLE IF NOT EXISTS recipients (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    domain_id       INTEGER NOT NULL REFERENCES domains (id) ON DELETE CASCADE,
    name            TEXT NOT NULL DEFAULT '',
    email           TEXT NOT NULL,
    receive_alerts  INTEGER NOT NULL DEFAULT 1,
    receive_reports INTEGER NOT NULL DEFAULT 1,
    active          INTEGER NOT NULL DEFAULT 1,
    created_at      TEXT NOT NULL,
    UNIQUE (domain_id, email)
);

CREATE TABLE IF NOT EXISTS calls (
    id                   INTEGER PRIMARY KEY AUTOINCREMENT,
    domain_id            INTEGER NOT NULL REFERENCES domains (id) ON DELETE CASCADE,
    call_id              TEXT NOT NULL,
    orig_call_id         TEXT,
    term_call_id         TEXT,
    direction            TEXT NOT NULL DEFAULT 'Unknown',
    caller_number        TEXT NOT NULL DEFAULT '',
    caller_name          TEXT NOT NULL DEFAULT '',
    called_number        TEXT NOT NULL DEFAULT '',
    called_name          TEXT NOT NULL DEFAULT '',
    answered_by          TEXT NOT NULL DEFAULT '',
    started_at           TEXT,
    answered_at          TEXT,
    ended_at             TEXT,
    duration_seconds     INTEGER NOT NULL DEFAULT 0,
    talk_seconds         INTEGER NOT NULL DEFAULT 0,
    hold_seconds         INTEGER NOT NULL DEFAULT 0,
    disconnect_reason    TEXT NOT NULL DEFAULT '',
    disposition          TEXT NOT NULL DEFAULT '',
    recording_status     TEXT NOT NULL DEFAULT 'pending',
    recording_duration   INTEGER NOT NULL DEFAULT 0,
    recording_url        TEXT NOT NULL DEFAULT '',
    recording_size_kb    INTEGER NOT NULL DEFAULT 0,
    has_recording        INTEGER NOT NULL DEFAULT 0,
    transcript_job_id    TEXT NOT NULL DEFAULT '',
    transcript_status    TEXT NOT NULL DEFAULT 'pending',
    has_transcript       INTEGER NOT NULL DEFAULT 0,
    transcript_summary   TEXT NOT NULL DEFAULT '',
    transcript_topics    TEXT NOT NULL DEFAULT '[]',
    transcript_text      TEXT NOT NULL DEFAULT '',
    transcript_segments  TEXT NOT NULL DEFAULT '[]',
    sentiment_label      TEXT NOT NULL DEFAULT '',
    sentiment_positive   INTEGER NOT NULL DEFAULT 0,
    sentiment_neutral    INTEGER NOT NULL DEFAULT 0,
    sentiment_negative   INTEGER NOT NULL DEFAULT 0,
    match_count          INTEGER NOT NULL DEFAULT 0,
    alert_status         TEXT NOT NULL DEFAULT 'none',
    status               TEXT NOT NULL DEFAULT 'pending',
    attempts             INTEGER NOT NULL DEFAULT 0,
    next_attempt_at      TEXT,
    last_error           TEXT NOT NULL DEFAULT '',
    processed_at         TEXT,
    raw_cdr              TEXT NOT NULL DEFAULT '{}',
    created_at           TEXT NOT NULL,
    updated_at           TEXT NOT NULL,
    UNIQUE (domain_id, call_id)
);

CREATE INDEX IF NOT EXISTS calls_domain_started_idx ON calls (domain_id, started_at DESC);
CREATE INDEX IF NOT EXISTS calls_started_idx ON calls (started_at DESC);
CREATE INDEX IF NOT EXISTS calls_queue_idx ON calls (status, next_attempt_at);
CREATE INDEX IF NOT EXISTS calls_alert_idx ON calls (domain_id, alert_status);
CREATE INDEX IF NOT EXISTS calls_sentiment_idx ON calls (sentiment_label);

CREATE TABLE IF NOT EXISTS matches (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    call_id       INTEGER NOT NULL REFERENCES calls (id) ON DELETE CASCADE,
    domain_id     INTEGER NOT NULL,
    keyword_id    INTEGER REFERENCES keywords (id) ON DELETE SET NULL,
    keyword_label TEXT NOT NULL,
    field         TEXT NOT NULL,
    snippet       TEXT NOT NULL DEFAULT '',
    severity      TEXT NOT NULL DEFAULT 'medium',
    created_at    TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS matches_call_idx ON matches (call_id);
CREATE INDEX IF NOT EXISTS matches_domain_idx ON matches (domain_id, created_at DESC);
CREATE INDEX IF NOT EXISTS matches_keyword_idx ON matches (keyword_id);

CREATE TABLE IF NOT EXISTS notifications (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    call_id    INTEGER REFERENCES calls (id) ON DELETE CASCADE,
    domain_id  INTEGER NOT NULL,
    kind       TEXT NOT NULL DEFAULT 'alert',
    recipients TEXT NOT NULL DEFAULT '[]',
    subject    TEXT NOT NULL DEFAULT '',
    status     TEXT NOT NULL DEFAULT 'queued',
    attempts   INTEGER NOT NULL DEFAULT 0,
    error      TEXT NOT NULL DEFAULT '',
    sent_at    TEXT,
    created_at TEXT NOT NULL,
    UNIQUE (call_id, kind)
);

CREATE INDEX IF NOT EXISTS notifications_status_idx ON notifications (status, created_at);

CREATE TABLE IF NOT EXISTS report_schedules (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    name          TEXT NOT NULL,
    domain_id     INTEGER REFERENCES domains (id) ON DELETE CASCADE,
    frequency     TEXT NOT NULL DEFAULT 'weekly',
    weekday       INTEGER NOT NULL DEFAULT 1,
    day_of_month  INTEGER NOT NULL DEFAULT 1,
    send_time     TEXT NOT NULL DEFAULT '07:00',
    period_days   INTEGER NOT NULL DEFAULT 7,
    recipients    TEXT NOT NULL DEFAULT '[]',
    include_csv   INTEGER NOT NULL DEFAULT 1,
    active        INTEGER NOT NULL DEFAULT 1,
    last_run_at   TEXT,
    next_run_at   TEXT,
    last_error    TEXT NOT NULL DEFAULT '',
    created_at    TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS report_schedules_due_idx ON report_schedules (active, next_run_at);

CREATE TABLE IF NOT EXISTS report_runs (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    schedule_id  INTEGER REFERENCES report_schedules (id) ON DELETE CASCADE,
    ran_at       TEXT NOT NULL,
    status       TEXT NOT NULL,
    calls_count  INTEGER NOT NULL DEFAULT 0,
    alerts_count INTEGER NOT NULL DEFAULT 0,
    recipients   TEXT NOT NULL DEFAULT '[]',
    error        TEXT NOT NULL DEFAULT ''
);

CREATE TABLE IF NOT EXISTS login_attempts (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    identity   TEXT NOT NULL,
    ip         TEXT NOT NULL DEFAULT '',
    success    INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS login_attempts_idx ON login_attempts (identity, created_at);

CREATE TABLE IF NOT EXISTS audit_log (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER,
    domain_id  INTEGER,
    action     TEXT NOT NULL,
    meta       TEXT NOT NULL DEFAULT '',
    ip         TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS audit_log_idx ON audit_log (created_at DESC);

CREATE TABLE IF NOT EXISTS api_cache (
    key        TEXT PRIMARY KEY,
    value      TEXT NOT NULL,
    expires_at TEXT
);
