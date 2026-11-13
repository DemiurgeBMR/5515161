-- Общие документы гостиницы. Файлы хранятся вне публичной директории.
CREATE TABLE admin_documents (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    title VARCHAR(160) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    stored_name VARCHAR(80) NOT NULL,
    mime_type VARCHAR(120) NOT NULL,
    file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
    uploaded_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_admin_documents_created (created_at),
    KEY idx_admin_documents_uploader (uploaded_by),
    CONSTRAINT fk_admin_documents_uploader FOREIGN KEY (uploaded_by) REFERENCES staff (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
