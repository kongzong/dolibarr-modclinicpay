-- modClinicPay: daily number sequence (SF-YYYYMMDD-NNN), same pattern as
-- modPatient / modMedRecord / modPrescription / modPharmacy. Kept on module
-- disable (spec §5).
CREATE TABLE IF NOT EXISTS llx_clinicpay_bill_sequence (
	ref_prefix	varchar(16) NOT NULL,
	last_value	bigint NOT NULL DEFAULT 0,
	PRIMARY KEY (ref_prefix)
) ENGINE=innodb;
