-- modClinicPay: one charge bill per visit (spec §3.1).
-- status 0 draft, 1 paid, 9 refunded. Never deleted: a refunded bill stays
-- as the trace of the credit note. fk_invoice stays NULL until confirm().
-- amount_total is a snapshot taken through calcul_price_total() (spec §5.1).

CREATE TABLE llx_clinicpay_bill(
	rowid				integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity				integer DEFAULT 1 NOT NULL,
	ref					varchar(32) NOT NULL,
	fk_patient			integer NOT NULL,
	fk_invoice			integer DEFAULT NULL,
	status				smallint DEFAULT 0 NOT NULL,
	amount_total		decimal(24,8) DEFAULT 0 NOT NULL,
	channel				varchar(16) DEFAULT NULL,
	channel_ref			varchar(64) DEFAULT NULL,
	fk_user_pay			integer DEFAULT NULL,
	date_pay			datetime DEFAULT NULL,
	note				text DEFAULT NULL,
	model_pdf			varchar(255) DEFAULT NULL,
	last_main_doc		varchar(255) DEFAULT NULL,
	fk_user_creat		integer DEFAULT NULL,
	date_creation		datetime NOT NULL,
	tms					timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
