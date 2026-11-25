-- Review this preflight result before applying the constraint. Resolve any
-- returned duplicate groups manually; this migration never changes lead data.
SELECT
    campaign_id,
    REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(TRIM(mobile_no), ' ', ''), '-', ''), '+', ''), '(', ''), ')', '') AS normalized_mobile,
    COUNT(*) AS duplicate_count,
    GROUP_CONCAT(id ORDER BY id) AS lead_ids
FROM crm_leads
WHERE campaign_id IS NOT NULL
GROUP BY
    campaign_id,
    REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(TRIM(mobile_no), ' ', ''), '-', ''), '+', ''), '(', ''), ')', '')
HAVING COUNT(*) > 1;

-- Apply only after the preflight returns no rows.
ALTER TABLE crm_leads
    ADD COLUMN mobile_no_normalized VARCHAR(20)
        GENERATED ALWAYS AS (
            REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(TRIM(mobile_no), ' ', ''), '-', ''), '+', ''), '(', ''), ')', '')
        ) STORED,
    ADD UNIQUE KEY uq_crm_leads_campaign_mobile (campaign_id, mobile_no_normalized);
