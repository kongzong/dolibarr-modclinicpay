-- modClinicPay: charge bundle line. Composition only — the unit price is not
-- stored here; Paybill::create() snapshots the product current price at
-- charge time (same rule as manual bill lines, spec §3.3).

CREATE TABLE llx_clinicpay_bundle_line(
	rowid				integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	fk_bundle			integer NOT NULL,
	position			integer DEFAULT 0 NOT NULL,
	fk_product			integer NOT NULL,
	qty					double(24,8) DEFAULT 1 NOT NULL
) ENGINE=innodb;
