-- Apply against stock_management to separate the closure's CRM customer number
-- from the platform username. Existing values were usernames.
USE stock_management;

ALTER TABLE crm_lead_closure_forms
    ADD COLUMN IF NOT EXISTS username VARCHAR(100) DEFAULT NULL AFTER customer_number;

UPDATE crm_lead_closure_forms
SET username = customer_number,
    customer_number = NULL
WHERE (username IS NULL OR TRIM(username) = '')
  AND customer_number IS NOT NULL
  AND TRIM(customer_number) <> '';
