-- migrate:up

CREATE TABLE "glued"."mail_boxes" (
    uuid uuid generated always as (((doc->>'uuid'::text))::uuid) stored not null,
    doc jsonb not null,
    nonce bytea generated always as (decode(md5((doc - 'uuid')::text), 'hex')) stored,
    created_at timestamp default CURRENT_TIMESTAMP,
    updated_at timestamp default CURRENT_TIMESTAMP,
    host VARCHAR(255) GENERATED ALWAYS AS ((doc->'host')) STORED NOT NULL,
    port integer GENERATED ALWAYS AS ((doc->>'port')::int) STORED,
    username VARCHAR(255) GENERATED ALWAYS AS (doc->>'username') STORED NOT NULL,
    UNIQUE (nonce),
    PRIMARY KEY (uuid)
);

CREATE INDEX IF NOT EXISTS mail_boxes_host_idx ON glued.mail_boxes(host);
CREATE INDEX IF NOT EXISTS mail_boxes_username_idx ON glued.mail_boxes(username);
CREATE UNIQUE INDEX IF NOT EXISTS mail_boxes_host_username_uq ON glued.mail_boxes(host, username);

-- migrate:down

DROP TABLE IF EXISTS "glued"."mail_boxes";

