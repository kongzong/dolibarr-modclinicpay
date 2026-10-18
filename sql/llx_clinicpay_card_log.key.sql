-- modClinicPay: indexes for llx_clinicpay_card_log
-- Added 2026-10-18 (round-3 audit): every card card shows its log, filtered
-- by fk_card; the audit trail also links back to the bill that consumed it.
ALTER TABLE llx_clinicpay_card_log ADD INDEX idx_clinicpay_card_log_card (fk_card);
ALTER TABLE llx_clinicpay_card_log ADD INDEX idx_clinicpay_card_log_bill (fk_bill);
