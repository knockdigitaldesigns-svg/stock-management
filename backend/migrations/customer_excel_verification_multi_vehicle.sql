ALTER TABLE customer_vehicle_details
    DROP INDEX uq_vehicle_verification_submission,
    ADD KEY idx_vehicle_verification_submission (verification_submission_id);
