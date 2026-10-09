-- Internal school metadata only; this field is not a RADIUS attribute.
ALTER TABLE permanent_users
    ADD COLUMN IF NOT EXISTS description VARCHAR(255) NOT NULL DEFAULT '';
