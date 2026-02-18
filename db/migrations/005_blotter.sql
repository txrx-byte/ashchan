-- Blotter schema
CREATE TABLE blotter (
    id SERIAL PRIMARY KEY,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    content TEXT NOT NULL,
    is_important BOOLEAN DEFAULT false
);

-- Initial entries
INSERT INTO blotter (content, is_important) VALUES
('Welcome to ashchan! A modern microservices-based imageboard.', true),
('Blotter functionality has been added.', false);
