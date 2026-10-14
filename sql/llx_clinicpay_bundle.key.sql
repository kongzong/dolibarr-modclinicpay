-- Copyright (C) 2026 modClinicPay contributors
ALTER TABLE llx_clinicpay_bundle ADD UNIQUE INDEX uk_clinicpay_bundle (entity, name);
ALTER TABLE llx_clinicpay_bundle ADD INDEX idx_clinicpay_bundle_status (status);
ALTER TABLE llx_clinicpay_bundle_line ADD INDEX idx_clinicpay_bundle_line_fk (fk_bundle);
ALTER TABLE llx_clinicpay_bundle_line ADD CONSTRAINT fk_clinicpay_bundle_line_bundle FOREIGN KEY (fk_bundle) REFERENCES llx_clinicpay_bundle (rowid);
