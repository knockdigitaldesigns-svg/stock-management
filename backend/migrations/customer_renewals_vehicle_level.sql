ALTER TABLE customer_renewals
    ADD COLUMN vehicle_id INT DEFAULT NULL AFTER customer_id;

UPDATE customer_renewals cr
INNER JOIN (
    SELECT cr.id AS renewal_id,
        CASE
            WHEN COUNT(DISTINCT cv.id) = 1 THEN MIN(cv.id)
            ELSE MIN(CASE WHEN cv.validity_months = cr.validity_months THEN cv.id END)
        END AS vehicle_id
    FROM customer_renewals cr
    INNER JOIN customer_installations ci
        ON ci.customer_id = cr.customer_id
       AND ci.installation_date = cr.installation_date
    INNER JOIN customer_vehicle_details cv
        ON cv.id = ci.vehicle_id
       AND cv.customer_id = cr.customer_id
    LEFT JOIN customer_renewals linked
        ON linked.vehicle_id = cv.id
       AND linked.id <> cr.id
    WHERE cr.vehicle_id IS NULL
    GROUP BY cr.id
    HAVING (
        COUNT(DISTINCT cv.id) = 1
        OR COUNT(DISTINCT CASE
            WHEN cv.validity_months = cr.validity_months THEN cv.id
        END) = 1
    )
       AND COUNT(DISTINCT linked.id) = 0
) matched ON matched.renewal_id = cr.id
SET cr.vehicle_id = matched.vehicle_id
WHERE cr.vehicle_id IS NULL;

ALTER TABLE customer_renewals
    DROP INDEX uq_customer_renewal_customer,
    ADD UNIQUE KEY uq_customer_renewal_vehicle (vehicle_id),
    ADD INDEX idx_customer_renewal_customer (customer_id),
    ADD CONSTRAINT fk_customer_renewal_vehicle
        FOREIGN KEY (vehicle_id)
        REFERENCES customer_vehicle_details(id)
        ON UPDATE CASCADE
        ON DELETE CASCADE;
