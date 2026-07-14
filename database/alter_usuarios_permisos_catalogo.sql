ALTER TABLE user_document_permissions
    ADD COLUMN can_manage_catalog TINYINT(1) NOT NULL DEFAULT 0 AFTER can_upload;
