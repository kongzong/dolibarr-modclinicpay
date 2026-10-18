-- modClinicPay: indexes for llx_clinicpay_card
-- Added 2026-10-18 (round-3 audit): the patient card tab lists by fk_patient
-- and the expiry reminder filters on date_end.
ALTER TABLE llx_clinicpay_card ADD UNIQUE INDEX uk_clinicpay_card_ref (ref);
ALTER TABLE llx_clinicpay_card ADD INDEX idx_clinicpay_card_patient (fk_patient);
ALTER TABLE llx_clinicpay_card ADD INDEX idx_clinicpay_card_end (date_end);
ALTER TABLE llx_clinicpay_card ADD INDEX idx_clinicpay_card_status (status);
