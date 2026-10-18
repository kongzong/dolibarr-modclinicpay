-- modClinicPay: indexes for llx_clinicpay_bill_line
-- Added 2026-10-18 (round-3 audit): the bill card and the reconciliation
-- reports join back on these two FKs; without them every lookup is a scan.
ALTER TABLE llx_clinicpay_bill_line ADD INDEX idx_clinicpay_bill_line_bill (fk_bill);
ALTER TABLE llx_clinicpay_bill_line ADD INDEX idx_clinicpay_bill_line_presc (fk_prescription);
ALTER TABLE llx_clinicpay_bill_line ADD INDEX idx_clinicpay_bill_line_disp (fk_dispense);
