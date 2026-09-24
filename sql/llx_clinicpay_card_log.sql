-- modClinicPay: card movement log (CREATE/CHARGE/CONSUME/REFUND/EXPIRE).
-- Append-only: rows are never updated or deleted (spec §3.4 / §5.3).

CREATE TABLE llx_clinicpay_card_log(
	rowid				integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	fk_card				integer NOT NULL,
	op					varchar(16) NOT NULL,
	count_delta			integer DEFAULT 0 NOT NULL,
	value_delta			double(24,8) DEFAULT 0 NOT NULL,
	fk_bill				integer DEFAULT NULL,
	fk_user				integer DEFAULT NULL,
	note				varchar(255) DEFAULT NULL,
	date_creation		datetime NOT NULL
) ENGINE=innodb;
