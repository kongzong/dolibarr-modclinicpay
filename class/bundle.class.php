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
 * \file    htdocs/custom/clinicpay/class/bundle.class.php
 * \brief   Charge bundle (收费套餐): a named, reusable set of billable
 *          products applied onto a draft charge bill. Lines hold composition
 *          only (fk_product + qty); unit prices are snapshotted from the
 *          product current price at charge time (Paybill::create()).
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';

/**
 * Class ClinicPayBundle
 */
class ClinicPayBundle extends CommonObject
{
	/** @var string Module element name */
	public $element = 'clinicpay_bundle';
	/** @var string Table without prefix */
	public $table_element = 'clinicpay_bundle';

	/** @var int */
	public $rowid;
	/** @var int */
	public $entity;
	/** @var string */
	public $name;
	/** @var int 1 active, 0 inactive */
	public $status;
	/** @var string|null */
	public $note;
	/** @var int|null */
	public $fk_user_creat;
	/** @var string|int */
	public $date_creation;
	/** @var array<int,array> Rows: fk_product, qty */
	public $lines = array();

	/** @var string Last error */
	public $error = '';

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Fetch head + ordered lines.
	 *
	 * @param	int		$id		Rowid
	 * @return	int				1 ok, 0 not found, <0 error
	 */
	public function fetch($id)
	{
		$prefix = $this->db->prefix();
		$sql = "SELECT rowid, entity, name, status, note, fk_user_creat, date_creation";
		$sql .= " FROM ".$prefix."clinicpay_bundle WHERE rowid = ".((int) $id)." AND status <> 9";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$o = $this->db->fetch_object($resql);
		$this->db->free($resql);
		if (!$o) {
			return 0;
		}
		$this->rowid = (int) $o->rowid;
		$this->entity = (int) $o->entity;
		$this->name = (string) $o->name;
		$this->status = (int) $o->status;
		$this->note = $o->note !== null ? (string) $o->note : null;
		$this->fk_user_creat = $o->fk_user_creat !== null ? (int) $o->fk_user_creat : null;
		$this->date_creation = $o->date_creation;
		return $this->fetchLines();
	}

	/**
	 * Load ordered rows into $this->lines.
	 *
	 * @return	int		1 ok, <0 error
	 */
	private function fetchLines()
	{
		$this->lines = array();
		$prefix = $this->db->prefix();
		$sql = "SELECT rowid, position, fk_product, qty";
		$sql .= " FROM ".$prefix."clinicpay_bundle_line WHERE fk_bundle = ".((int) $this->rowid);
		$sql .= " ORDER BY position ASC, rowid ASC";
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		while ($o = $this->db->fetch_object($resql)) {
			$this->lines[] = array(
				'id' => (int) $o->rowid,
				'position' => (int) $o->position,
				'fk_product' => (int) $o->fk_product,
				'qty' => (float) $o->qty,
			);
		}
		$this->db->free($resql);
		return 1;
	}

	/**
	 * Create head + lines from the current properties.
	 *
	 * @param	User	$user	Author
	 * @return	int				New rowid, <0 error
	 */
	public function create($user)
	{
		if (trim((string) $this->name) === '') {
			$this->error = 'BadValueForName';
			return -1;
		}
		if (dol_strlen(trim((string) $this->name)) > 128) {
			// llx_clinicpay_bundle.name is varchar(128): catch it here so the user
			// gets a readable message instead of MySQL "Data too long".
			$this->error = 'ClinicPayBundleErrNameLong';
			return -1;
		}
		$prefix = $this->db->prefix();
		$this->db->begin();

		$sql = "INSERT INTO ".$prefix."clinicpay_bundle (entity, name, status, note, fk_user_creat, date_creation)";
		$sql .= " VALUES (".getDolGlobalInt('entity', 1).", '".$this->db->escape($this->name)."', ".($this->status ? 1 : 0).", ";
		$sql .= ($this->note !== null && $this->note !== '' ? "'".$this->db->escape($this->note)."'" : 'NULL').", ";
		$sql .= (!empty($user->id) ? (int) $user->id : 'NULL').", '".$this->db->idate(dol_now())."')";
		$res = $this->db->query($sql);
		if (!$res) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}
		$this->rowid = (int) $this->db->last_insert_id($prefix.'clinicpay_bundle');

		if ($this->saveLines() < 0) {
			$this->db->rollback();
			return -1;
		}
		$this->db->commit();
		return $this->rowid;
	}

	/**
	 * Update head + replace all lines with the current $this->lines.
	 *
	 * @param	User|null	$user	Unused (kept for call symmetry)
	 * @return	int					1 ok, <0 error
	 */
	public function update($user = null)
	{
		if ($this->rowid <= 0 || trim((string) $this->name) === '') {
			$this->error = 'BadValueForIdOrName';
			return -1;
		}
		if (dol_strlen(trim((string) $this->name)) > 128) {
			$this->error = 'ClinicPayBundleErrNameLong';
			return -1;
		}
		$prefix = $this->db->prefix();
		$this->db->begin();

		$sql = "UPDATE ".$prefix."clinicpay_bundle SET";
		$sql .= " name = '".$this->db->escape($this->name)."'";
		$sql .= ", status = ".($this->status ? 1 : 0);
		$sql .= ", note = ".($this->note !== null && $this->note !== '' ? "'".$this->db->escape($this->note)."'" : 'NULL');
		$sql .= " WHERE rowid = ".((int) $this->rowid);
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}

		if (!$this->db->query("DELETE FROM ".$prefix."clinicpay_bundle_line WHERE fk_bundle = ".((int) $this->rowid))) {
			$this->error = $this->db->lasterror();
			$this->db->rollback();
			return -1;
		}
		if ($this->saveLines() < 0) {
			$this->db->rollback();
			return -1;
		}
		$this->db->commit();
		return 1;
	}

	/**
	 * Soft delete: keep head + lines, mark status 9 so the trace survives.
	 *
	 * @return	int		1 ok, <0 error
	 */
	public function delete()
	{
		if ($this->rowid <= 0) {
			return -1;
		}
		$prefix = $this->db->prefix();
		$sql = "UPDATE ".$prefix."clinicpay_bundle SET status = 9 WHERE rowid = ".((int) $this->rowid);
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$this->status = 9;
		return 1;
	}

	/**
	 * Insert $this->lines (no transaction; caller owns it).
	 *
	 * @return	int		1 ok, <0 error
	 */
	private function saveLines()
	{
		$prefix = $this->db->prefix();
		foreach ($this->lines as $i => $l) {
			$fkProduct = (int) ($l['fk_product'] ?? 0);
			$qty = (float) ($l['qty'] ?? 0);
			if ($fkProduct <= 0 || $qty <= 0) {
				continue;
			}
			$sql = "INSERT INTO ".$prefix."clinicpay_bundle_line (fk_bundle, position, fk_product, qty)";
			$sql .= " VALUES (".((int) $this->rowid).", ".((int) $i).", ".$fkProduct.", ".$qty.")";
			if (!$this->db->query($sql)) {
				$this->error = $this->db->lasterror();
				return -1;
			}
		}
		return 1;
	}

	/**
	 * List heads (id + name + status + line count) of the current entity.
	 *
	 * @param	DoliDB		$db
	 * @param	int|null	$activeOnly	1 to keep active bundles only
	 * @return	array<int,array>		Empty array on error
	 */
	public static function listAll($db, $activeOnly = null)
	{
		$prefix = $db->prefix();
		$sql = "SELECT t.rowid, t.name, t.status, t.note, COUNT(l.rowid) AS nlines";
		$sql .= " FROM ".$prefix."clinicpay_bundle t";
		$sql .= " LEFT JOIN ".$prefix."clinicpay_bundle_line l ON l.fk_bundle = t.rowid";
		$sql .= " WHERE t.entity = ".getDolGlobalInt('entity', 1)." AND t.status <> 9";
		if ($activeOnly === 1) {
			$sql .= " AND t.status = 1";
		}
		$sql .= " GROUP BY t.rowid, t.name, t.status, t.note";
		$sql .= " ORDER BY t.status DESC, t.name ASC";
		$resql = $db->query($sql);
		$out = array();
		if (!$resql) {
			return $out;
		}
		while ($o = $db->fetch_object($resql)) {
			$out[] = array(
				'id' => (int) $o->rowid,
				'name' => (string) $o->name,
				'status' => (int) $o->status,
				'note' => $o->note !== null ? (string) $o->note : null,
				'nlines' => (int) $o->nlines,
			);
		}
		$db->free($resql);
		return $out;
	}
}
