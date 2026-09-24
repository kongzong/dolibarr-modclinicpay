-- modClinicPay: prepaid count card (疗程卡) or value card (储值卡).
-- status 0 valid, 1 used up, 2 expired, 3 refunded. Never deleted.
-- card_type COUNT (times) or VALUE (stored value).

CREATE TABLE llx_clinicpay_card(
	rowid				integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity				integer DEFAULT 1 NOT NULL,
	ref					varchar(32) NOT NULL,
	fk_patient			integer NOT NULL,
	fk_product			integer DEFAULT NULL,
	card_type			varchar(16) NOT NULL,
	total_count			integer DEFAULT 0 NOT NULL,
	used_count			integer DEFAULT 0 NOT NULL,
	total_value			double(24,8) DEFAULT 0 NOT NULL,
	used_value			double(24,8) DEFAULT 0 NOT NULL,
	date_start			date DEFAULT NULL,
	date_end			date DEFAULT NULL,
	status				smallint DEFAULT 0 NOT NULL,
	note				text DEFAULT NULL,
	fk_user_creat		integer DEFAULT NULL,
	date_creation		datetime NOT NULL,
	tms					timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
