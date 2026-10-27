ALTER TABLE renewal_history
    ADD COLUMN previous_state_json LONGTEXT DEFAULT NULL AFTER notes,
    ADD COLUMN result_state_json LONGTEXT DEFAULT NULL AFTER previous_state_json,
    ADD COLUMN undone_at DATETIME DEFAULT NULL AFTER result_state_json,
    ADD COLUMN undone_by INT DEFAULT NULL AFTER undone_at,
    ADD COLUMN undo_reason VARCHAR(500) DEFAULT NULL AFTER undone_by;
