-- modClinicPay: indexes for llx_clinicpay_bill
-- Added 2026-10-18 (round-3 audit): reports filter on date_pay, lists on
-- fk_patient/status; ref gets a unique key like the other clinic tables.
ALTER TABLE llx_clinicpay_bill ADD UNIQUE INDEX uk_clinicpay_bill_ref (ref);
ALTER TABLE llx_clinicpay_bill ADD INDEX idx_clinicpay_bill_patient (fk_patient);
ALTER TABLE llx_clinicpay_bill ADD INDEX idx_clinicpay_bill_medrecord (fk_medrecord);
ALTER TABLE llx_clinicpay_bill ADD INDEX idx_clinicpay_bill_pay (date_pay);
ALTER TABLE llx_clinicpay_bill ADD INDEX idx_clinicpay_bill_status (status);
ALTER TABLE llx_clinicpay_bill ADD INDEX idx_clinicpay_bill_entity (entity);
