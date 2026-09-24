-- modClinicPay: charge bill line. Prices are snapshots of the product
-- current price at charge time (spec §3.3). fk_prescription / fk_dispense
-- carry the source when a drug line is brought in from a dispense sheet
-- (V0.1: manual entry; pharmacy 0.1.0 ready first).

CREATE TABLE llx_clinicpay_bill_line(
	rowid				integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	fk_bill				integer NOT NULL,
	position			integer DEFAULT 0 NOT NULL,
	fk_product			integer DEFAULT NULL,
	product_ref			varchar(64) DEFAULT NULL,
	label				varchar(255) NOT NULL,
	qty					double(24,8) DEFAULT 1 NOT NULL,
	price_unit			double(24,8) DEFAULT 0 NOT NULL,
	vat_rate			double(6,3) DEFAULT 0 NOT NULL,
	subprice_total		double(24,8) DEFAULT 0 NOT NULL,
	fk_prescription		integer DEFAULT NULL,
	fk_dispense			integer DEFAULT NULL
) ENGINE=innodb;
