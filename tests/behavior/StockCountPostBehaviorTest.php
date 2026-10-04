<?php
/* Copyright (C) 2026  modPharmacy contributors
 *
 * Behaviour tests for PharmacyStockCount::post().
 *
 * Posting a stock count writes the difference in three places at once:
 * llx_product_batch.qty, llx_product_stock.reel and llx_stock_mouvement. Miss
 * any one and the batch rule (sum of batches == reel) breaks, after which FEFO
 * reports healthy products as short. The rule is checked on every posting, but a
 * source-scanning test cannot see any of it, so it is asserted here against the
 * database.
 *
 * Everything runs inside a transaction the harness rolls back. The DAO opens and
 * commits its own nested transaction; DoliDB counts nesting, so the inner commit
 * only decrements and the outer rollback is what actually undoes the work. The
 * demo database is therefore left exactly as it was.
 */

use ClinicPay\Behavior\BehaviorTestCase;
use ClinicPay\Behavior\BehaviorTestSkip;

require_once __DIR__.'/../../../pharmacy/lib/pharmacy.lib.php';
require_once __DIR__.'/../../../pharmacy/class/pharmacystockcount.class.php';

class StockCountPostBehaviorTest extends BehaviorTestCase
{
	/** @var int */
	private $warehouseId = 0;

	public function setUp()
	{
		$this->warehouseId = $this->anyWarehouseWithStock();
	}

	/**
	 * A warehouse that has stock rows, otherwise there is nothing to count.
	 *
	 * @return	int
	 */
	private function anyWarehouseWithStock()
	{
		$sql = "SELECT ps.fk_entrepot AS w, COUNT(*) AS n FROM ".$this->db->prefix()."product_stock AS ps";
		$sql .= " GROUP BY ps.fk_entrepot HAVING n > 0 ORDER BY n DESC";
		$resql = $this->db->query($sql);
		$id = 0;
		if ($resql) {
			$o = $this->db->fetch_object($resql);
			$id = $o ? (int) $o->w : 0;
			$this->db->free($resql);
		}
		if ($id === 0) {
			throw new BehaviorTestSkip('no warehouse carries stock');
		}
		return $id;
	}

	/**
	 * The user who counts the goods.
	 *
	 * @return	User
	 */
	private function counter()
	{
		return $this->userByLogin('admin');
	}

	/**
	 * The user who posts the sheet.
	 *
	 * GSP requires the poster to differ from the counter, so a second account is
	 * needed; the demo database ships one (see ensure_demo_users.php).
	 *
	 * @return	User
	 */
	private function poster()
	{
		$user = $this->userByLogin('xuwenjing');
		if ($user->id <= 0) {
			throw new BehaviorTestSkip('no second demo account to post with');
		}
		return $user;
	}

	/**
	 * @param	string	$login	Dolibarr login
	 * @return	User			the user, or a User with id 0 when absent
	 */
	private function userByLogin($login)
	{
		$user = new User($this->db);
		$user->fetch(0, $login);
		return $user;
	}

	/**
	 * Stock totals of the whole warehouse, the aggregate the batch rule applies to.
	 *
	 * @return	array{reel:float,batches:float}
	 */
	private function warehouseTotals()
	{
		$sql = "SELECT COALESCE(SUM(ps.reel), 0) AS reel, COALESCE(SUM(b.s), 0) AS batches";
		$sql .= " FROM ".$this->db->prefix()."product_stock AS ps";
		$sql .= " LEFT JOIN (SELECT fk_product_stock, SUM(qty) AS s FROM ".$this->db->prefix()."product_batch";
		$sql .= " GROUP BY fk_product_stock) AS b ON b.fk_product_stock = ps.rowid";
		$sql .= " WHERE ps.fk_entrepot = ".(int) $this->warehouseId;
		$resql = $this->db->query($sql);
		$o = $resql ? $this->db->fetch_object($resql) : null;
		if ($resql) {
			$this->db->free($resql);
		}
		return array('reel' => (float) $o->reel, 'batches' => (float) $o->batches);
	}

	/**
	 * Build a draft sheet in the test transaction and return the DAO.
	 *
	 * @param	array	$adjustments	productId => counted qty
	 * @return	PharmacyStockCount
	 */
	private function draftWithCounts(array $adjustments)
	{
		$this->begin();

		$dao = new PharmacyStockCount($this->db);
		$id = $dao->createFromWarehouse($this->counter(), $this->warehouseId, date('Y-m-d'), 'behaviour test');
		$this->assertGreaterThan(0, $id, 'createFromWarehouse failed: '.$dao->error);
		$dao->fetch($id);
		$this->assertGreaterThan(0, count($dao->lines), 'the new sheet captured no lines');

		// Count everything as booked, then apply the requested differences.
		// A product occupies one line per batch, so each adjustment is applied to
		// the FIRST line of that product only: applying it to every batch would
		// multiply the difference by the number of batches.
		$touched = array();
		foreach ($dao->lines as $line) {
			$counted = (float) $line->qty_book;
			$product = (int) $line->fk_product;
			if (array_key_exists($product, $adjustments) && empty($touched[$product])) {
				$counted += (float) $adjustments[$product];
				$touched[$product] = true;
			}
			$rc = $dao->setCounted((int) $line->rowid, $counted);
			$this->assertGreaterThan(0, $rc, 'setCounted refused a value: '.$dao->error);
		}
		foreach (array_keys($adjustments) as $product) {
			$this->assertTrue(
				!empty($touched[$product]),
				'product '.$product.' was not on the sheet, so the difference had nowhere to go'
			);
		}
		$dao->fetchLines();
		$dao->refreshTotals();
		return $dao;
	}

	/**
	 * A sheet counted exactly as booked posts without changing any stock, and
	 * leaves the batch rule intact.
	 */
	public function testPostingWithoutDifferenceChangesNothing()
	{
		$dao = $this->draftWithCounts(array());
		$countId = (int) $dao->id;

		$before = $this->warehouseTotals();
		$rc = $dao->post($this->poster());
		$this->assertGreaterThan(0, $rc, 'posting a balanced sheet failed: '.$dao->error);

		$after = $this->warehouseTotals();
		$this->assertEquals($before['reel'], $after['reel'], 'a balanced count must not move the stock total');
		$this->assertEquals(
			$after['reel'],
			$after['batches'],
			'the batch rule must hold after posting: sum(llx_product_batch.qty) == sum(llx_product_stock.reel)'
		);

		$check = new PharmacyStockCount($this->db);
		$check->fetch($countId);
		$this->assertSame(
			PharmacyStockCount::STATUS_POSTED,
			(int) $check->status,
			'the sheet must be marked posted'
		);
	}

	/**
	 * A difference must move all three records together. This is the invariant
	 * that keeps FEFO honest: a reel that disagrees with its batches makes a
	 * stocked product look short.
	 */
	public function testDifferenceMovesBatchStockAndMovementTogether()
	{
		// Pick a product that has a batch, so a difference has somewhere to land.
		$sql = "SELECT ps.fk_product AS p FROM ".$this->db->prefix()."product_stock AS ps";
		$sql .= " INNER JOIN ".$this->db->prefix()."product_batch AS pb ON pb.fk_product_stock = ps.rowid";
		$sql .= " WHERE ps.fk_entrepot = ".(int) $this->warehouseId;
		$sql .= " AND ps.reel > 0 GROUP BY ps.fk_product, ps.reel ORDER BY ps.reel DESC";
		$resql = $this->db->query($sql);
		$product = $resql ? (int) $this->db->fetch_object($resql)->p : 0;
		if ($resql) {
			$this->db->free($resql);
		}
		if ($product === 0) {
			throw new BehaviorTestSkip('no product in this warehouse has both stock and batches');
		}

		$delta = -3.0;
		$dao = $this->draftWithCounts(array($product => $delta));
		$countId = (int) $dao->id;

		$productBefore = $this->reelOf($product, $this->warehouseId);
		$warehouseBefore = $this->warehouseTotals();
		$movementsBefore = $this->movementCount();

		$rc = $dao->post($this->poster());
		$this->assertGreaterThan(0, $rc, 'posting failed: '.$dao->error);

		// A product can sit on several product_stock rows in the same warehouse,
		// and the posting settles FEFO across them, so the product total is the
		// meaningful figure here, not one row.
		$productAfter = $this->reelOf($product, $this->warehouseId);
		$this->assertEquals(
			$productBefore + $delta,
			$productAfter,
			'llx_product_stock.reel must move by the counted difference, summed over the product\'s stock rows'
		);

		// The sheet changed exactly one product, so the warehouse total moves by
		// the same amount: this catches an adjustment leaking onto another
		// product.
		$warehouseAfter = $this->warehouseTotals();
		$this->assertEquals(
			$warehouseBefore['reel'] + $delta,
			$warehouseAfter['reel'],
			'the warehouse total must move by exactly the counted difference'
		);

		$this->assertEquals(
			$warehouseAfter['reel'],
			$warehouseAfter['batches'],
			'the batch rule must hold after posting a difference: the batches and the reel moved together'
		);

		$this->assertGreaterThan(
			$movementsBefore,
			$this->movementCount(),
			'the posting must leave a stock movement, otherwise the adjustment is untraceable'
		);

		// The movement must be attributed to this sheet.
		$sql = "SELECT COUNT(*) AS n FROM ".$this->db->prefix()."stock_mouvement";
		$sql .= " WHERE label = 'Stock count ".$this->db->escape($dao->ref)."'";
		$resql = $this->db->query($sql);
		$tagged = $resql ? (int) $this->db->fetch_object($resql)->n : 0;
		if ($resql) {
			$this->db->free($resql);
		}
		$this->assertGreaterThan(
			0,
			$tagged,
			'the movement must carry the sheet ref in its label, found none for '.$dao->ref
		);
		$this->assertGreaterThan(0, $countId, 'the sheet must have an id');
	}

	/**
	 * GSP: the counter may not post their own sheet. The rule is a real guard
	 * against one person both counting and approving, so it is asserted rather
	 * than worked around.
	 */
	public function testCounterMayNotPostTheirOwnSheet()
	{
		$dao = $this->draftWithCounts(array());
		$before = $this->warehouseTotals();

		$rc = $dao->post($this->counter());
		$this->assertSame(
			-4,
			$rc,
			'posting must be refused for the counter, got '.$rc.' ('.$dao->error.')'
		);
		$this->assertTrue(is_string($dao->error) && $dao->error !== '', 'the refusal must name a reason');

		$after = $this->warehouseTotals();
		$this->assertEquals($before['reel'], $after['reel'], 'a refused posting must not touch the stock');

		// The override exists for a documented reason, and it must work.
		$this->assertGreaterThan(0, $dao->post($this->counter(), true), 'the explicit override must work: '.$dao->error);
	}

	/**
	 * Posting twice must be refused: the second run would double-count the
	 * difference and quietly corrupt the stock.
	 */
	public function testPostingTwiceIsRefused()
	{
		$dao = $this->draftWithCounts(array());
		$this->assertGreaterThan(0, $dao->post($this->poster()), 'the first posting failed: '.$dao->error);

		$after = $this->warehouseTotals();
		$again = $dao->post($this->poster());
		$this->assertTrue($again <= 0, 'a second posting must be refused, got '.$again);

		$now = $this->warehouseTotals();
		$this->assertEquals($after['reel'], $now['reel'], 'the refused posting must not have touched the stock');
	}

	/**
	 * @return	int number of stock movements
	 */
	private function movementCount()
	{
		$sql = "SELECT COUNT(*) AS n FROM ".$this->db->prefix()."stock_mouvement";
		$sql .= " WHERE type_mouvement IN (2, 3)";
		$resql = $this->db->query($sql);
		$n = $resql ? (int) $this->db->fetch_object($resql)->n : 0;
		if ($resql) {
			$this->db->free($resql);
		}
		return $n;
	}
}
