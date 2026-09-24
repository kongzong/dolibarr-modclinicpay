-- modClinicPay: cross-month number sequence (CK-YYYYMM-NNNN), same pattern
-- as modPatient card number. Kept on module disable (spec §5).
CREATE TABLE IF NOT EXISTS llx_clinicpay_card_sequence (
	ref_prefix	varchar(16) NOT NULL,
	last_value	bigint NOT NULL DEFAULT 0,
	PRIMARY KEY (ref_prefix)
) ENGINE=innodb;
