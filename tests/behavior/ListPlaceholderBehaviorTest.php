<?php
/* Copyright (C) 2026  modClinicPay contributors
 *
 * Behaviour tests for the filter placeholders of the three list DAOs.
 *
 * A Form::selectarray() with show_empty=1 has no emptyvalue parameter: the
 * "nothing chosen" option is always -1. The pages normalise that on the way in,
 * but a user can also type one into the URL, and a DAO that treats it as a real
 * value searches for a record with id -1 and shows an empty list. That is what
 * was reported as "the status filter is wrong" — the real culprit was a
 * different select on the same row.
 *
 * Each placeholder must leave the result set exactly as it was, and each real id
 * must still narrow it, so "ignore the placeholder" cannot be satisfied by
 * dropping the filter altogether.
 */

use ClinicPay\Behavior\BehaviorTestCase;
use ClinicPay\Behavior\BehaviorTestSkip;

require_once __DIR__.'/../../lib/clinicpay.lib.php';
require_once __DIR__.'/../../class/paybill.class.php';
require_once __DIR__.'/../../class/servicecard.class.php';
require_once __DIR__.'/../../../pharmacy/lib/pharmacy.lib.php';
require_once __DIR__.'/../../../pharmacy/class/dispense.class.php';

class ListPlaceholderBehaviorTest extends BehaviorTestCase
{
	/**
	 * Assert a filter key left at its placeholder changes nothing.
	 *
	 * @param	string	$label	DAO + key, used in messages
	 * @param	object	$dao		the list DAO
	 * @param	string	$key		filter key
	 * @param	mixed	$placeholder	the value a select submits when untouched
	 * @return	void
	 */
	private function assertPlaceholderIsInert($label, $dao, $key, $placeholder)
	{
		$base = $dao->search(array(), 500, 0);
		$this->assertTrue(is_array($base), $label.': the unfiltered search must work, got '.$dao->error);
		if ((int) $base['total'] === 0) {
			throw new BehaviorTestSkip('no '.$label.' rows in the demo database');
		}
		$filters = array($key => $placeholder);
		$res = $dao->search($filters, 500, 0);
		$this->assertTrue(is_array($res), $label.': search with '.$key.'='.var_export($placeholder, true).' failed: '.$dao->error);
		$this->assertSame(
			(int) $base['total'],
			(int) $res['total'],
			$label.': '.$key.'='.var_export($placeholder, true).' is the "nothing chosen" placeholder and must not narrow anything'
		);
	}

	/**
	 * The dispensing list has three selects: status, product and the hidden
	 * patient carried over from a patient card.
	 */
	public function testDispensePlaceholders()
	{
		$dao = new Dispense($this->db);
		$this->assertPlaceholderIsInert('Dispense status', $dao, 'status', -1);
		$this->assertPlaceholderIsInert('Dispense product', $dao, 'fk_product', -1);
		$this->assertPlaceholderIsInert('Dispense patient', $dao, 'fk_patient', -1);
		$this->assertPlaceholderIsInert('Dispense prescription', $dao, 'fk_prescription', -1);
	}

	/**
	 * A real product must still narrow the dispensing list; otherwise "ignore the
	 * placeholder" could be satisfied by ignoring the filter altogether.
	 */
	public function testDispenseRealProductNarrows()
	{
		$sql = "SELECT fk_product FROM ".$this->db->prefix()."pharmacy_dispense_line";
		$sql .= " GROUP BY fk_product ORDER BY COUNT(*) DESC LIMIT 1";
		$resql = $this->db->query($sql);
		$product = $resql ? (int) $this->db->fetch_object($resql)->fk_product : 0;
		if ($resql) {
			$this->db->free($resql);
		}
		if ($product === 0) {
			throw new BehaviorTestSkip('no dispensing line in the demo database');
		}

		$dao = new Dispense($this->db);
		$all = $dao->search(array(), 500, 0);
		$res = $dao->search(array('fk_product' => $product), 500, 0);
		$this->assertTrue(is_array($res), 'search failed: '.$dao->error);
		$this->assertGreaterThan(0, (int) $res['total'], 'a real product must match the sheets it appears on');
		$this->assertTrue(
			(int) $res['total'] <= (int) $all['total'],
			'a product filter must never widen the result set'
		);

		// Every returned sheet must actually carry the product.
		foreach ($res['rows'] as $sheet) {
			$lineSql = "SELECT COUNT(*) AS n FROM ".$this->db->prefix()."pharmacy_dispense_line";
			$lineSql .= " WHERE fk_dispense = ".(int) $sheet->rowid." AND fk_product = ".$product;
			$lineResql = $this->db->query($lineSql);
			$found = $lineResql ? (int) $this->db->fetch_object($lineResql)->n : 0;
			if ($lineResql) {
				$this->db->free($lineResql);
			}
			$this->assertGreaterThan(0, $found, 'sheet '.$sheet->ref.' does not carry product '.$product);
		}
	}

	/**
	 * The card (prepaid balance) list has a status select and the patient carried
	 * over from a patient card.
	 */
	public function testServiceCardPlaceholders()
	{
		$dao = new ServiceCard($this->db);
		$this->assertPlaceholderIsInert('ServiceCard status', $dao, 'status', -1);
		$this->assertPlaceholderIsInert('ServiceCard patient', $dao, 'fk_patient', -1);
	}

	/**
	 * The bill list, for symmetry with the two above: the same placeholder
	 * reached it through the channel select.
	 */
	public function testBillPlaceholders()
	{
		$dao = new Paybill($this->db);
		$this->assertPlaceholderIsInert('Paybill status', $dao, 'status', -1);
		$this->assertPlaceholderIsInert('Paybill channel', $dao, 'channel', '-1');
		$this->assertPlaceholderIsInert('Paybill patient', $dao, 'fk_patient', -1);
	}
}
