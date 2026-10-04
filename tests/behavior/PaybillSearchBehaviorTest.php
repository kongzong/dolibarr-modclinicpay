<?php
/* Copyright (C) 2026  modClinicPay contributors
 *
 * Behaviour tests for Paybill::search().
 *
 * The dashboard charts and the tax-invoice reconciliation page both drill into
 * this method, and they disagree with the list page on purpose: charts count by
 * date_pay / date_dispense while the list used to count by date_creation. That
 * mismatch was invisible to the source-scanning tests and only showed up when a
 * user clicked a bar and saw the wrong rows, so the filter is asserted here
 * against the demo documents.
 *
 * Invariants, not counts: the demo database is meant to change, so these tests
 * compare the DAO result with an independent SQL aggregate instead of pinning
 * a row number. That keeps them meaningful when someone edits the demo data.
 */

use ClinicPay\Behavior\BehaviorTestCase;
use ClinicPay\Behavior\BehaviorTestSkip;

// master.inc.php (used by the harness to reach the database) neither defines
// dol_include_once() nor DOL_DOCUMENT_ROOT — both come from main.inc.php, which
// redirects to the login page outside a web request. Load the DAO by path.
require_once __DIR__.'/../../lib/clinicpay.lib.php';
require_once __DIR__.'/../../class/paybill.class.php';

class PaybillSearchBehaviorTest extends BehaviorTestCase
{
	/**
	 * Load a demo bill that carries a date_pay, and return
	 * [bill, dayStart, dayEnd] for that single day.
	 *
	 * @return	array
	 */
	private function demoDay()
	{
		$bills = $this->demoPaidBills();
		$bill = $bills[0];
		$day = substr((string) $bill->date_pay, 0, 10);
		$from = strtotime($day.' 00:00:00');
		$to = strtotime($day.' 23:59:59');
		return array($bill, $from, $to);
	}

	/**
	 * Aggregate the bills whose date_pay falls in a window, with plain SQL.
	 *
	 * @param	int	$from	timestamp, inclusive
	 * @param	int	$to		timestamp, inclusive
	 * @return	array{count:int,amount:float}
	 */
	private function sqlBillsPaidBetween($from, $to)
	{
		$where = "date_pay IS NOT NULL AND date_pay BETWEEN '".$this->db->escape(date('Y-m-d H:i:s', $from))."'"
			." AND '".$this->db->escape(date('Y-m-d H:i:s', $to))."'";
		$sql = "SELECT COUNT(*) AS n FROM ".$this->db->prefix()."clinicpay_bill WHERE ".$where;
		$resql = $this->db->query($sql);
		$count = $resql ? (int) $this->db->fetch_object($resql)->n : 0;
		if ($resql) {
			$this->db->free($resql);
		}
		return array(
			'count' => $count,
			'amount' => $this->sqlSum('clinicpay_bill', $where, 'amount_total'),
		);
	}

	/**
	 * date_field=date_pay must actually filter on date_pay. Before the
	 * whitelist was added the option silently fell back to date_creation, and
	 * the dashboard bar for a given day showed a different set of bills.
	 */
	public function testDateFieldPayFiltersOnDatePayNotCreation()
	{
		list($bill, $from, $to) = $this->demoDay();

		$dao = new Paybill($this->db);
		$res = $dao->search(array(
			'date_field' => 'date_pay',
			'from' => $from,
			'to' => $to,
		), 100, 0);
		$this->assertTrue(is_array($res), 'search() must return a result set, got '.gettype($res).' ('.$dao->error.')');

		$expected = $this->sqlBillsPaidBetween($from, $to);
		$this->assertSame(
			$expected['count'],
			(int) $res['total'],
			'the filtered count must equal the SQL aggregate for that day'
		);

		$rows = $res['rows'];
		$this->assertCount((int) $res['total'], $rows, 'total and rows must describe the same set');
		foreach ($rows as $row) {
			$this->assertTrue(
				$row->date_pay !== null,
				'bill '.$row->ref.' came back although its date_pay is NULL'
			);
			$ts = strtotime((string) $row->date_pay);
			$this->assertTrue(
				$ts >= $from && $ts <= $to,
				'bill '.$row->ref.' has date_pay '.$row->date_pay.', outside the requested day'
			);
		}

		// The demo bill itself must be in the result, otherwise the window is
		// wrong rather than merely empty.
		$found = false;
		foreach ($rows as $row) {
			if ((int) $row->rowid === (int) $bill->rowid) {
				$found = true;
			}
		}
		$this->assertTrue($found, 'the demo bill paid that day is missing from its own day filter');
	}

	/**
	 * Switching date_field must change which column is filtered.
	 *
	 * The demo data cannot prove this: every demo bill has date_creation equal to
	 * date_pay (they are paid on the spot), so both settings select the same rows
	 * and a broken whitelist still looks correct. The test therefore builds a
	 * bill whose two dates are a week apart, inside a transaction it rolls back.
	 */
	public function testDateFieldChangesTheColumnBeingFiltered()
	{
		$createdDay = dol_mktime(9, 0, 0, 3, 15, 2026);
		$paidDay = dol_mktime(11, 30, 0, 3, 22, 2026);
		$billId = $this->insertBillWithDistinctDates($createdDay, $paidDay);

		$byCreation = $this->searchIds('date_creation', $createdDay);
		$byPay = $this->searchIds('date_pay', $paidDay);


		$this->assertTrue(
			in_array($billId, $byCreation, true),
			'the bill must appear when the filter column is date_creation and the window is its creation day'
		);
		$this->assertTrue(
			in_array($billId, $byPay, true),
			'the bill must appear when the filter column is date_pay and the window is its payment day'
		);

		// The decisive pair: the SAME window must select a different set depending
		// on the column. Ask for the creation day twice, once per column: with
		// date_creation the bill is in, with date_pay it is out. A whitelist that
		// silently fell back to one column would return the same rows both times
		// and the dashboard bar would show the wrong bills.
		$creationDayViaPay = $this->searchIds('date_pay', $createdDay);
		$this->assertFalse(
			in_array($billId, $creationDayViaPay, true),
			'with date_field=date_pay the creation-day window must not return a bill that was paid a week later, '
			.'so date_field is being ignored'
		);
		$paidDayViaCreation = $this->searchIds('date_creation', $paidDay);
		$this->assertFalse(
			in_array($billId, $paidDayViaCreation, true),
			'with date_field=date_creation the payment-day window must not return a bill that was created a week earlier, '
			.'so date_field is being ignored'
		);
	}

	/**
	 * The Ids a date-field search returns for a one-day window.
	 *
	 * @param	string	$dateField	'date_pay' or 'date_creation'
	 * @param	int		$day		timestamp inside the wanted day
	 * @return	int[]
	 */
	private function searchIds($dateField, $day)
	{
		$from = strtotime(date('Y-m-d 00:00:00', $day));
		$to = strtotime(date('Y-m-d 23:59:59', $day));
		$dao = new Paybill($this->db);
		$res = $dao->search(array('date_field' => $dateField, 'from' => $from, 'to' => $to), 500, 0);
		$this->assertTrue(is_array($res), 'search() must return a result set, got '.$dao->error);
		$ids = array();
		foreach ($res['rows'] as $row) {
			$ids[] = (int) $row->rowid;
		}
		return $ids;
	}

	/**
	 * Insert a paid bill whose creation and payment dates are far apart.
	 *
	 * The demo rows all have both dates equal, which is exactly why the
	 * date_field whitelist needs a purpose-built row to be testable. The insert
	 * runs inside the test transaction and is rolled back by tearDown().
	 *
	 * @param	int	$createdDay	timestamp for date_creation
	 * @param	int	$paidDay		timestamp for date_pay
	 * @return	int					the new bill id
	 */
	private function insertBillWithDistinctDates($createdDay, $paidDay)
	{
		$this->begin();

		// search() INNER JOINs patient_profile and societe, so the bill needs a
		// patient that exists. Reuse the first one the demo data has.
		$sql = "SELECT pp.rowid AS fk_patient FROM ".$this->db->prefix()."patient_profile AS pp LIMIT 1";
		$resql = $this->db->query($sql);
		$patient = $resql ? (int) $this->db->fetch_object($resql)->fk_patient : 0;
		if ($resql) {
			$this->db->free($resql);
		}
		$this->assertGreaterThan(0, $patient, 'the demo data has no patient to attach the bill to');

		$P = $this->db->prefix();
		$ref = 'ZZ-BEHAVIOR-'.gmdate('YmdHis');
		$sql = "INSERT INTO ".$P."clinicpay_bill";
		$sql .= " (entity, ref, fk_patient, status, amount_total, channel, date_creation, date_pay)";
		$sql .= " VALUES (".(int) $this->confEntity().", '".$this->db->escape($ref)."', ".$patient.", ".CLINICPAY_BILL_PAID.", 1.0, 'CASH',";
		$sql .= " '".$this->db->escape(date('Y-m-d H:i:s', $createdDay))."', '".$this->db->escape(date('Y-m-d H:i:s', $paidDay))."')";
		$this->assertTrue((bool) $this->db->query($sql), 'inserting the behaviour bill failed: '.$this->db->lasterror());
		$id = (int) $this->db->last_insert_id($P.'clinicpay_bill');
		$this->assertGreaterThan(0, $id, 'the behaviour bill got no id');
		return $id;
	}

	/**
	 * @return	int the entity the tests operate in
	 */
	private function confEntity()
	{
		return !empty($GLOBALS['conf']->entity) ? (int) $GLOBALS['conf']->entity : 1;
	}

	/**
	 * A closed window far in the past must return nothing, and must not be
	 * silently widened by a date_field fallback.
	 */
	public function testEmptyWindowReturnsNothing()
	{
		$dao = new Paybill($this->db);
		$res = $dao->search(array(
			'date_field' => 'date_pay',
			'from' => strtotime('2001-01-01 00:00:00'),
			'to' => strtotime('2001-01-02 00:00:00'),
		), 50, 0);
		$this->assertTrue(is_array($res), 'search() must return a result set');
		$this->assertSame(0, (int) $res['total'], 'a 2001 window cannot match a 2026 demo database');
		$this->assertCount(0, $res['rows'], 'an empty filter must return no rows');
	}

	/**
	 * The status filter is used by the tax-invoice page: it must narrow the set
	 * to that status and agree with the SQL aggregate.
	 */
	public function testStatusFilterAgreesWithSql()
	{
		$sql = "SELECT COUNT(*) AS n FROM ".$this->db->prefix()."clinicpay_bill";
		$sql .= " WHERE status = ".CLINICPAY_BILL_PAID." AND date_pay IS NOT NULL";
		$resql = $this->db->query($sql);
		$expected = $resql ? (int) $this->db->fetch_object($resql)->n : 0;
		if ($resql) {
			$this->db->free($resql);
		}
		if ($expected === 0) {
			throw new BehaviorTestSkip('no paid demo bill');
		}

		$dao = new Paybill($this->db);
		$res = $dao->search(array('status' => CLINICPAY_BILL_PAID), 200, 0);
		$this->assertTrue(is_array($res), 'search() must return a result set');
		$this->assertSame($expected, (int) $res['total'], 'the paid filter must match the SQL aggregate');

		foreach ($res['rows'] as $row) {
			$this->assertSame(
				CLINICPAY_BILL_PAID,
				(int) $row->status,
				'bill '.$row->ref.' is not paid but came back from the paid filter'
			);
		}
	}

	/**
	 * A limit smaller than the match set must be honoured, and total must still
	 * report the full count: the list pages paginate on total.
	 */
	public function testLimitIsHonouredAndTotalStaysFull()
	{
		$dao = new Paybill($this->db);
		$all = $dao->search(array(), 500, 0);
		$this->assertTrue(is_array($all), 'search() must return a result set');
		if ((int) $all['total'] < 2) {
			throw new BehaviorTestSkip('need at least two demo bills to test the limit');
		}

		$limited = $dao->search(array(), 1, 0);
		$this->assertCount(1, $limited['rows'], 'a limit of 1 must return one row');
		$this->assertSame(
			(int) $all['total'],
			(int) $limited['total'],
			'total must report the full match count even when the page is limited'
		);
	}

	/**
	 * A form select with show_empty=1 submits -1 for "nothing chosen". Treating
	 * that as a real value searched for a channel literally named "-1" and
	 * emptied the whole list, which is what a user reported as a broken filter.
	 *
	 * @param	string	$label	human name for the message
	 * @param	array	$filters	search filters to try
	 * @return	void
	 */
	private function assertNotWipedByPlaceholder($label, array $filters)
	{
		$dao = new Paybill($this->db);
		$all = $dao->search(array(), 500, 0);
		$res = $dao->search($filters, 500, 0);
		$this->assertTrue(is_array($res), $label.': search must return a result set, got '.$dao->error);
		$this->assertSame(
			(int) $all['total'],
			(int) $res['total'],
			$label.': the placeholder must not narrow anything, all='.$all['total'].' filtered='.$res['total']
		);
	}

	/**
	 * The status select and the channel select both submit -1 when untouched.
	 */
	public function testSelectPlaceholdersDoNotEmptyTheList()
	{
		$this->assertNotWipedByPlaceholder('channel placeholder', array('channel' => '-1'));
		$this->assertNotWipedByPlaceholder('status placeholder', array('status' => -1));
		$this->assertNotWipedByPlaceholder('both placeholders', array('channel' => '-1', 'status' => -1));
	}

	/**
	 * A real channel must still narrow the list, otherwise "ignoring -1" could
	 * be satisfied by dropping the filter altogether.
	 */
	public function testRealChannelStillNarrows()
	{
		$sql = "SELECT DISTINCT channel FROM ".$this->db->prefix()."clinicpay_bill";
		$sql .= " WHERE channel IS NOT NULL AND channel <> ''";
		$resql = $this->db->query($sql);
		$channels = array();
		if ($resql) {
			while ($o = $this->db->fetch_object($resql)) {
				$channels[] = (string) $o->channel;
			}
			$this->db->free($resql);
		}
		if (empty($channels)) {
			throw new BehaviorTestSkip('no demo bill carries a channel');
		}

		$dao = new Paybill($this->db);
		$all = $dao->search(array(), 500, 0);
		foreach ($channels as $channel) {
			$res = $dao->search(array('channel' => $channel), 500, 0);
			$this->assertTrue(is_array($res), 'search failed for channel '.$channel);
			$this->assertGreaterThan(0, (int) $res['total'], 'channel '.$channel.' must match at least its own bills');
			$this->assertTrue(
				(int) $res['total'] <= (int) $all['total'],
				'a channel filter must never widen the result set'
			);
			foreach ($res['rows'] as $row) {
				$this->assertSame($channel, (string) $row->channel, 'bill '.$row->ref.' is not on channel '.$channel);
			}
		}
	}

	/**
	 * The tax invoice number became filterable on the bill list because the
	 * reconciliation page needs the same view. An empty value must apply no
	 * filter (the normal case), a value must match, and an impossible value must
	 * return nothing rather than everything.
	 */
	public function testFapiaoFilter()
	{
		$dao = new Paybill($this->db);
		$all = $dao->search(array(), 500, 0);
		$baseline = (int) $all['total'];

		// Empty is the default state of the input: it must not filter. The list
		// page submits it on every request, so an empty value that filtered would
		// hide every bill that already carries a number.
		$empty = $dao->search(array('fapiao' => ''), 500, 0);
		$this->assertSame($baseline, (int) $empty['total'], 'an empty fapiao input must not filter anything');

		// The bills still waiting for a number are a separate, explicit question.
		$missing = $dao->search(array('fapiao_empty' => 1), 500, 0);
		$this->assertTrue(is_array($missing), 'fapiao_empty must be accepted');
		foreach ($missing['rows'] as $row) {
			$this->assertSame(
				'',
				(string) $row->fapiao_no,
				'bill '.$row->ref.' has a number '.$row->fapiao_no.' but came back from the "still missing" filter'
			);
		}

		// An impossible number must return nothing.
		$none = $dao->search(array('fapiao' => 'ZZ-NOT-A-REAL-INVOICE'), 500, 0);
		$this->assertSame(0, (int) $none['total'], 'a fapiao number nobody has must match nothing');

		// A real one must return exactly the bills that carry it.
		$sql = "SELECT fapiao_no FROM ".$this->db->prefix()."clinicpay_bill";
		$sql .= " WHERE fapiao_no IS NOT NULL AND fapiao_no <> '' ORDER BY rowid LIMIT 1";
		$resql = $this->db->query($sql);
		$sample = $resql ? (string) $this->db->fetch_object($resql)->fapiao_no : '';
		if ($resql) {
			$this->db->free($resql);
		}
		if ($sample === '') {
			throw new BehaviorTestSkip('no demo bill carries a tax invoice number');
		}
		$hit = $dao->search(array('fapiao' => $sample), 500, 0);
		$this->assertGreaterThan(0, (int) $hit['total'], 'the real fapiao number must match its own bill');
		foreach ($hit['rows'] as $row) {
			$this->assertTrue(
				strpos((string) $row->fapiao_no, $sample) !== false,
				'bill '.$row->ref.' does not carry '.$sample
			);
		}
	}
}
