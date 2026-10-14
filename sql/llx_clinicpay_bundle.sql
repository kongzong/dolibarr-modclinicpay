-- modClinicPay: charge bundle (收费套餐). A named, reusable set of billable
-- products (exam / checkup packages) that staff apply onto a draft charge
-- bill. Lines hold composition only (fk_product + qty): prices are snapshotted
-- from the product current price at charge time, exactly like manual lines.
-- status 1 active, 0 inactive. Never deleted rows keep audit trace via bill_line.

CREATE TABLE llx_clinicpay_bundle(
	rowid				integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity				integer DEFAULT 1 NOT NULL,
	name				varchar(128) NOT NULL,
	status				smallint DEFAULT 1 NOT NULL,
	note				text DEFAULT NULL,
	fk_user_creat		integer DEFAULT NULL,
	date_creation		datetime NOT NULL,
	tms					timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
