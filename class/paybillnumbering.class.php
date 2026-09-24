<?php
/* Copyright (C) 2026  modClinicPay contributors
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    htdocs/custom/clinicpay/class/paybillnumbering.class.php
 * \ingroup clinicpay
 * \brief   Daily charge bill numbers (SF-YYYYMMDD-NNN), reserved inside the
 *          caller's database transaction (spec §3.2). Same InnoDB row-lock
 *          pattern as modPatient / modMedRecord / modPrescription. A call
 *          outside a transaction is a read-only preview (setup page).
 */

/**
 * Class PaybillNumbering
 */
class PaybillNumbering
{
	/** Prefix shape: SF-YYYYMMDD- */
	const PREFIX_PATTERN = '/^SF-[0-9]{8}-$/D';

	/** @var DoliDB */
	private $db;

	/** @param DoliDB $db Database connection of the bill transaction */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * @param	int|null	$timestamp	Unix timestamp (default now)
	 * @return	string					SF-YYYYMMDD-
	 */
	public static function prefixFor($timestamp = null)
	{
		return 'SF-'.date('Ymd', $timestamp === null ? time() : (int) $timestamp).'-';
	}

	/**
	 * @param string $prefix Daily prefix, for example SF-20260924-
	 * @return string Reference (a preview unless a caller transaction is open)
	 * @throws RuntimeException On any failure; never returns an invalid reference.
	 */
	public function nextReference($prefix)
	{
		$step = 'checking the numbering context';
		try {
			if (!is_object($this->db) || $this->db->type !== 'mysqli' || !preg_match(self::PREFIX_PATTERN, $prefix)) {
				throw new RuntimeException('MySQL/MariaDB and a daily charge bill prefix are required');
			}

			$reserve = $this->db->transaction_opened > 0;
			$table = $this->db->prefix().'clinicpay_bill_sequence';
			$escapedPrefix = $this->db->escape($prefix);
			$lock = $reserve ? ' FOR UPDATE' : '';

			if ($reserve) {
				$step = 'locking the daily sequence';
				$this->query("INSERT INTO ".$table." (ref_prefix, last_value) VALUES ('".$escapedPrefix."', 0)"
					." ON DUPLICATE KEY UPDATE last_value = last_value");
			}

			$step = 'reading the daily sequence';
			$result = $this->query("SELECT last_value FROM ".$table." WHERE ref_prefix = '".$escapedPrefix."'".$lock);
			$counter = $this->db->fetch_object($result);
			if (!$counter && ($reserve || $this->db->num_rows($result) !== 0)) {
				throw new RuntimeException('Cannot read the daily sequence row');
			}
			$lastValue = $this->sequenceValue($counter ? $counter->last_value : null);
			$this->db->free($result);

			$step = 'reading existing bill references';
			$result = $this->query("SELECT MAX(CAST(SUBSTRING(ref, ".(strlen($prefix) + 1).") AS UNSIGNED)) AS maxseq"
				." FROM ".$this->db->prefix()."clinicpay_bill WHERE ref LIKE '".$escapedPrefix."%'".$lock);
			$history = $this->db->fetch_object($result);
			if (!$history || !property_exists($history, 'maxseq')) {
				throw new RuntimeException('Cannot read existing bill references');
			}
			$lastValue = max($lastValue, $this->sequenceValue($history->maxseq));
			$this->db->free($result);
			if ($lastValue >= PHP_INT_MAX) {
				throw new RuntimeException('Paybill sequence exhausted');
			}
			$nextValue = $lastValue + 1;

			if ($reserve) {
				$step = 'reserving the bill reference';
				$this->query("UPDATE ".$table." SET last_value = ".$nextValue." WHERE ref_prefix = '".$escapedPrefix."'");
			}

			return $prefix.sprintf('%03d', $nextValue);
		} catch (Throwable $error) {
			$this->rollback();
			$message = 'modClinicPay could not generate a charge bill number while '.$step.'.';
			dol_syslog($message, LOG_ERR);
			throw new RuntimeException($message, 0, $error);
		}
	}

	/** @param string $sql SQL statement
	 *  @return mixed Successful result
	 */
	private function query($sql)
	{
		$result = $this->db->query($sql);
		if (!$result) {
			throw new RuntimeException('Paybill numbering query failed');
		}
		return $result;
	}

	/** @param mixed $value Database integer or NULL for an empty day
	 *  @return int Valid nonnegative sequence
	 */
	private function sequenceValue($value)
	{
		if ($value === null) {
			return 0;
		}
		$text = (string) $value;
		$maximum = (string) PHP_INT_MAX;
		if (!preg_match('/^[0-9]+$/D', $text) || strlen($text) > strlen($maximum)
			|| (strlen($text) === strlen($maximum) && strcmp($text, $maximum) > 0)) {
			throw new RuntimeException('Invalid or exhausted bill sequence');
		}
		return (int) $text;
	}

	/** Fully unwind DoliDB's transaction depth; an inner rollback alone is a no-op. */
	private function rollback()
	{
		if (!is_object($this->db)) {
			return;
		}
		try {
			while ($this->db->transaction_opened > 0) {
				if (!$this->db->rollback()) {
					$this->db->close();
					break;
				}
			}
		} catch (Throwable $error) {
			try {
				$this->db->close();
			} catch (Throwable $ignored) {
				// Preserve the original numbering failure for the caller.
			}
		}
	}
}
