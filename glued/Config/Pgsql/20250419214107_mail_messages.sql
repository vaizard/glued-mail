-- migrate:up

CREATE TABLE IF NOT EXISTS glued.mail_messages (
                                                   uuid uuid GENERATED ALWAYS AS ((doc->>'uuid')::uuid) STORED PRIMARY KEY,
                                                   doc jsonb NOT NULL,
                                                   nonce bytea GENERATED ALWAYS AS (decode(md5((doc - 'uuid')::text),'hex')) STORED,
                                                   created_at timestamp DEFAULT CURRENT_TIMESTAMP,
                                                   updated_at timestamp DEFAULT CURRENT_TIMESTAMP,
                                                   mailbox_uuid uuid GENERATED ALWAYS AS ((doc->>'mailbox_uuid')::uuid) STORED NOT NULL,
                                                   uid integer GENERATED ALWAYS AS ((doc->>'uid')::int) STORED NOT NULL,
                                                   folder text GENERATED ALWAYS AS (doc->>'folder') STORED NOT NULL,
                                                   subject text GENERATED ALWAYS AS (doc->>'subject') STORED,
                                                   from_address text GENERATED ALWAYS AS (doc->>'fromStr') STORED,
                                                   to_addresses text GENERATED ALWAYS AS (doc->>'toStr') STORED,
                                                   sent_at text GENERATED ALWAYS AS (doc->>'date') STORED NOT NULL,
                                                   message_id text GENERATED ALWAYS AS (doc->>'message_id') STORED,
                                                   in_reply_to text GENERATED ALWAYS AS (doc->>'in_reply_to') STORED,
                                                   "references" jsonb GENERATED ALWAYS AS (doc->'references') STORED,
                                                   conversation_id text GENERATED ALWAYS AS ((doc->>'conversation_id')) STORED,
                                                   full_body_hash text GENERATED ALWAYS AS (doc->>'full_body_hash') STORED,
                                                   UNIQUE (nonce)
);
CREATE INDEX IF NOT EXISTS mail_messages_message_id_idx ON glued.mail_messages(message_id);
CREATE INDEX IF NOT EXISTS mail_messages_conversation_id_idx ON glued.mail_messages(conversation_id);
CREATE INDEX IF NOT EXISTS mail_messages_folder_idx ON glued.mail_messages(folder);
CREATE INDEX IF NOT EXISTS mail_messages_sent_at_idx ON glued.mail_messages(sent_at);
CREATE INDEX IF NOT EXISTS mail_messages_from_idx ON glued.mail_messages(from_address);
CREATE INDEX IF NOT EXISTS mail_messages_to_idx ON glued.mail_messages(to_addresses);

DROP TABLE IF EXISTS glued.mail_threads;
CREATE TABLE IF NOT EXISTS glued.mail_threads (
                                                  conversation_id uuid PRIMARY KEY,
                                                  root_message_id text NOT NULL,
                                                  created_at timestamp DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS mail_threads_root_idx ON glued.mail_threads(root_message_id);




-- migrate:down

DROP TABLE IF EXISTS "glued"."mail_messages";
DROP TABLE IF EXISTS "glued".mail_threads;