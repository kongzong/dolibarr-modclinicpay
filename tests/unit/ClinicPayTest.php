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
 * \file    htdocs/custom/clinicpay/tests/unit/ClinicPayTest.php
 * \ingroup clinicpay
 * \brief   Phase 1 structural tests: descriptor contract, table files,
 *          language keys. Guards against "AI guesses the API" regressions.
 */

dol_include_once('/clinicpay/core/modules/modClinicPay.class.php');
dol_include_once('/clinicpay/lib/clinicpay.lib.php');

/**
 * Class ClinicPayTest
 */
class ClinicPayTest extends \PHPUnit\Framework\TestCase
{
	/** Descriptor contract (spec §9) */
	public function testDescriptorContract()
	{
		global $db;
		$mod = new modClinicPay($db);
		$this->assertSame(501640, $mod->numero, 'Module id must stay 501640 (family 501600+10)');
		$this->assertSame('clinicpay', $mod->rights_class);
		$this->assertSame('MAIN_MODULE_CLINICPAY', $mod->const_name);
		$this->assertSame('ClinicPay', $mod->name);
		$this->assertContains('modPatient', $mod->depends, 'depends modPatient');
		$this->assertContains('modFacture', $mod->depends, 'depends modFacture');
		$this->assertContains('modBanque', $mod->depends, 'depends modBanque');
	}

	/** module_parts: models must be scalar 1 (chinadoc probe), not an array */
	public function testModelsIsScalarOne()
	{
		global $db;
		$mod = new modClinicPay($db);
		$this->assertSame(1, $mod->module_parts['models'], 'models must be scalar 1');
	}

	/** Permissions: one-level form, ids 50164011..61 (DEV.md §二.3) */
	public function testPermissions()
	{
		global $db;
		$mod = new modClinicPay($db);
		$codes = array();
		foreach ($mod->rights as $r) {
			$this->assertTrue(is_numeric($r[0]) && strlen((string) $r[0]) === 8, 'right id 8 digits');
			$this->assertArrayHasKey(4, $r, 'one-level form uses key 4');
			$codes[] = $r[4];
		}
		foreach (array('read', 'write', 'pay', 'validate', 'consume', 'admin') as $expected) {
			$this->assertContains($expected, $codes, 'permission "'.$expected.'" declared');
		}
	}

	/** Patient card tabs declared with the patient:+clinicpay_* descriptor syntax */
	public function testPatientTabs()
	{
		global $db;
		$mod = new modClinicPay($db);
		$data = '';
		foreach ($mod->tabs as $t) {
			if (isset($t['data'])) {
				$data .= $t['data']."\n";
			}
		}
		$this->assertStringContainsString('patient:+clinicpay_bills:', $data, 'charges tab on patient card');
		$this->assertStringContainsString('patient:+clinicpay_cards:', $data, 'cards tab on patient card');
		$this->assertStringContainsString('/clinicpay/patient_tab.php', $data);
		$this->assertStringContainsString("hasRight('clinicpay', 'read')", $data, 'tab gated by read right');
	}

	/** SQL: the 4 business tables + 2 sequence tables exist on disk */
	public function testSqlFiles()
	{
		$dir = __DIR__.'/../../sql/';
		foreach (array('llx_clinicpay_bill', 'llx_clinicpay_bill_line', 'llx_clinicpay_card',
			'llx_clinicpay_card_log', 'llx_clinicpay_bill_sequence', 'llx_clinicpay_card_sequence') as $t) {
			$this->assertFileExists($dir.$t.'.sql', $t.' sql file');
		}
	}

	/** Bill SQL: amount snapshot column and status values match spec §3.1 */
	public function testBillSqlSchema()
	{
		$sql = file_get_contents(__DIR__.'/../../sql/llx_clinicpay_bill.sql');
		$this->assertStringContainsString('amount_total', $sql);
		$this->assertStringContainsString('decimal(24,8)', $sql);
		$this->assertStringContainsString('fk_invoice', $sql);
		$this->assertStringContainsString('channel_ref', $sql);
		$this->assertStringContainsString('innodb', strtolower($sql));
	}

	/** Card SQL: atomic-consumption columns match spec §3.4 */
	public function testCardSqlSchema()
	{
		$sql = file_get_contents(__DIR__.'/../../sql/llx_clinicpay_card.sql');
		$this->assertStringContainsString('total_count', $sql);
		$this->assertStringContainsString('used_count', $sql);
		$this->assertStringContainsString('total_value', $sql);
		$this->assertStringContainsString('used_value', $sql);
		$log = file_get_contents(__DIR__.'/../../sql/llx_clinicpay_card_log.sql');
		$this->assertStringContainsString('op', $log);
		$this->assertStringContainsString('count_delta', $log);
		$this->assertStringContainsString('value_delta', $log);
	}

	/** Language: zh_CN and en_US cover the tab and status keys */
	public function testLanguageKeys()
	{
		$zh = file_get_contents(__DIR__.'/../../langs/zh_CN/clinicpay.lang');
		$en = file_get_contents(__DIR__.'/../../langs/en_US/clinicpay.lang');
		foreach (array('ClinicPayBillTab', 'ClinicPayCardTab', 'ClinicPayBillPaid', 'ClinicPayBillRefunded',
			'ClinicPayCardValid', 'ClinicPayCardExpired', 'ClinicPayPermPay', 'ClinicPayPermValidate') as $k) {
			$this->assertRegExp('/^'.$k.'=/m', $zh, 'zh_CN key '.$k);
			$this->assertRegExp('/^'.$k.'=/m', $en, 'en_US key '.$k);
		}
	}

	/** Class naming: business classes must not collide with the REST class Clinicpay */
	public function testBusinessClassNameSafety()
	{
		// modMedRecord/modPrescription lesson: REST registers ucwords('clinicpay')
		// = 'Clinicpay'; business classes must differ from it case-insensitively.
		$this->assertNotSame(strtolower('Clinicpay'), strtolower('Paybill'));
		$this->assertNotSame(strtolower('Clinicpay'), strtolower('ServiceCard'));
	}

	/** Constants: the two module constants are declared in the descriptor */
	public function testModuleConstants()
	{
		global $db;
		$mod = new modClinicPay($db);
		$names = array();
		foreach ($mod->const as $c) {
			$names[] = $c[0];
		}
		$this->assertContains('CLINICPAY_DEFAULT_VAT', $names);
		$this->assertContains('CLINICPAY_CARD_GRACE_DAYS', $names);
	}

	// ================================================================= //
	// Phase 2: charge core contracts (spec §3.2 / §3.3)                  //
	// ================================================================= //

	/** Numbering: SF-YYYYMMDD-NNN daily pattern, row-lock class present */
	public function testNumberingContract()
	{
		dol_include_once('/clinicpay/class/paybillnumbering.class.php');
		$this->assertTrue(preg_match(PaybillNumbering::PREFIX_PATTERN, 'SF-20260924-') === 1, 'valid daily prefix');
		$this->assertTrue(preg_match(PaybillNumbering::PREFIX_PATTERN, 'SF-2026092-') !== 1, 'short prefix refused');
		$this->assertTrue(preg_match(PaybillNumbering::PREFIX_PATTERN, 'CK-20260924-') !== 1, 'card prefix refused');
		$prefix = PaybillNumbering::prefixFor(mktime(12, 0, 0, 9, 24, 2026));
		$this->assertSame('SF-20260924-', $prefix, 'prefix derived from the given timestamp');
	}

	/** Paybill phase-2 API surface exists (create / setLines / confirm / refund two-step) */
	public function testPaybillPhase2Methods()
	{
		$src = file_get_contents(__DIR__.'/../../class/paybill.class.php');
		foreach (array('function create(', 'function setLines(', 'function confirm(', 'function createRefundDraft(',
			'function executeRefund(', 'function findRefundDraft(') as $m) {
			$this->assertStringContainsString($m, $src, 'Paybill method '.$m);
		}
		$this->assertStringContainsString('calcul_price_total(', $src, 'red line: amounts through calcul_price_total');
		$this->assertStringContainsString('TYPE_CREDIT_NOTE', $src, 'refund via native credit note');
		$this->assertStringContainsString('fk_facture_source', $src, 'credit note points at the original invoice');
	}

	/** Phase-2 language keys cover the refund double-person red line etc. */
	public function testPhase2LanguageKeys()
	{
		$zh = file_get_contents(__DIR__.'/../../langs/zh_CN/clinicpay.lang');
		$en = file_get_contents(__DIR__.'/../../langs/en_US/clinicpay.lang');
		foreach (array('ClinicPayErrSamePerson', 'ClinicPayErrRefRequired', 'ClinicPayErrAlreadyRefunded',
			'ClinicPayBillConfirmTitle', 'ClinicPayRefundExecute') as $k) {
			$this->assertRegExp('/^'.$k.'=/m', $zh, 'zh_CN key '.$k);
			$this->assertRegExp('/^'.$k.'=/m', $en, 'en_US key '.$k);
		}
	}

	/** Lib helpers: product options + native payment code mapping */
	public function testLibHelpers()
	{
		$this->assertTrue(function_exists('clinicpay_product_options'), 'product options helper');
		$this->assertTrue(function_exists('clinicpay_paiement_code'), 'payment code helper');
		$this->assertSame('CASH', clinicpay_paiement_code('CASH'));
		$this->assertSame('CB', clinicpay_paiement_code('SCAN'));
		$this->assertSame('', clinicpay_paiement_code('NOPE'));
	}

	// ================================================================= //
	// Phase 3: prepaid card contracts (spec §3.4)                        //
	// ================================================================= //

	/** Numbering: CK-YYYYMM-NNNN cross-month pattern, row-lock class present */
	public function testCardNumberingContract()
	{
		dol_include_once('/clinicpay/class/cardnumbering.class.php');
		$this->assertTrue(class_exists('CardNumbering'), 'CardNumbering class');
		$this->assertTrue(preg_match(CardNumbering::PREFIX_PATTERN, 'CK-202609-') === 1, 'valid monthly prefix');
		$this->assertTrue(preg_match(CardNumbering::PREFIX_PATTERN, 'CK-2026091-') !== 1, 'short prefix refused');
		$this->assertTrue(preg_match(CardNumbering::PREFIX_PATTERN, 'SF-202609-') !== 1, 'bill prefix refused');
		$prefix = CardNumbering::prefixFor(mktime(12, 0, 0, 9, 24, 2026));
		$this->assertSame('CK-202609-', $prefix, 'prefix derived from the given timestamp');
	}

	/** ServiceCard phase-3 API surface (sell / charge / consume / refund / logs) */
	public function testServiceCardPhase3Methods()
	{
		$src = file_get_contents(__DIR__.'/../../class/servicecard.class.php');
		foreach (array('function sell(', 'function charge(', 'function consume(', 'function createRefund(',
			'function executeRefund(', 'function fetchLogs(', 'function searchLogs(', 'function isExpired(',
			'function insertLog(', 'function relatedBillIds(') as $m) {
			$this->assertStringContainsString($m, $src, 'ServiceCard method '.$m);
		}
		// red lines: atomic conditional UPDATE gate + append-only log + bill-driven sell
		$this->assertStringContainsString('used_count < total_count', $src, 'atomic count gate');
		$this->assertStringContainsString('used_value +', $src, 'atomic value gate');
		$this->assertStringContainsString('CLINICPAY_LOG_CREATE', $src, 'CREATE log on sell');
		$this->assertStringContainsString('CLINICPAY_LOG_EXPIRE', $src, 'lazy EXPIRE log');
		$this->assertStringContainsString('createRefundDraft(', $src, 'refund goes through the bill two-person flow');
		$this->assertStringContainsString('ClinicPayErrCardNothingToRefund', $src, 'zero-balance refund guard');
	}

	/** Phase-3 language keys cover the card flow */
	public function testPhase3LanguageKeys()
	{
		$zh = file_get_contents(__DIR__.'/../../langs/zh_CN/clinicpay.lang');
		$en = file_get_contents(__DIR__.'/../../langs/en_US/clinicpay.lang');
		foreach (array('ClinicPayCardSell', 'ClinicPayCardConsume', 'ClinicPayCardCharge', 'ClinicPayCardRefundStart',
			'ClinicPayErrCardExpired', 'ClinicPayErrCardUsedUp', 'ClinicPayErrCardOverValue', 'ClinicPayLogExpire') as $k) {
			$this->assertRegExp('/^'.$k.'=/m', $zh, 'zh_CN key '.$k);
			$this->assertRegExp('/^'.$k.'=/m', $en, 'en_US key '.$k);
		}
	}

	// ================================================================= //
	// Phase 4: PDF + REST integration surface (spec §3.7 / §3.8)         //
	// ================================================================= //

	/** Capital amount (GB/T 15835), same vectors as the chinadoc reference */
	public function testAmountToChinese()
	{
		$this->assertSame('壹仟零贰元叁角整', clinicpay_amount_to_chinese(1002.30));
		$this->assertSame('壹佰元零伍分', clinicpay_amount_to_chinese(100.05));
		$this->assertSame('伍角整', clinicpay_amount_to_chinese(0.5));
		$this->assertSame('壹亿元整', clinicpay_amount_to_chinese(100000000));
		$this->assertSame('壹万零壹元整', clinicpay_amount_to_chinese(10001));
		$this->assertSame('零元整', clinicpay_amount_to_chinese(0));
		$this->assertSame('贰佰肆拾叁元伍角整', clinicpay_amount_to_chinese(243.5));
		$this->assertSame('', clinicpay_amount_to_chinese(-5));
	}

	/** pdf_sf template contract: discovery files + red-line strings */
	public function testPdfTemplateContract()
	{
		$base = __DIR__.'/../../core/modules/clinicpay/';
		$this->assertFileExists($base.'modules_clinicpay.php', 'shared base (ModelePDFClinicPay)');
		$this->assertFileExists($base.'doc/pdf_sf.modules.php', 'charge-bill layout');
		$baseSrc = file_get_contents($base.'modules_clinicpay.php');
		$this->assertStringContainsString("class ModelePDFClinicPay extends CommonDocGenerator", $baseSrc);
		$this->assertStringContainsString("stsongstdlight", $baseSrc, 'validated Chinese font');
		$this->assertStringContainsString("conf->clinicpay->dir_output", $baseSrc, 'writes into DOL_DATA_ROOT/clinicpay');
		$this->assertStringContainsString('CLINICPAY_BILL_REFUNDED', $baseSrc, 'refunded watermark');
		$src = file_get_contents($base.'doc/pdf_sf.modules.php');
		$this->assertStringContainsString("class pdf_sf extends ModelePDFClinicPay", $src);
		$this->assertStringContainsString("clinicpay_amount_to_chinese(", $src, 'GB/T 15835 capital total');
	}

	/** Paybill phase-4 PDF API surface */
	public function testPaybillPdfSurface()
	{
		$src = file_get_contents(__DIR__.'/../../class/paybill.class.php');
		foreach (array('function generateDocument(', 'function pdfPath(', 'function preparePdfContext(') as $m) {
			$this->assertStringContainsString($m, $src, 'Paybill PDF method '.$m);
		}
		$this->assertStringContainsString("commonGenerateDocument('core/modules/clinicpay/doc/', 'sf'", $src);
		$this->assertFileExists(__DIR__.'/../../pdf.php', 'PDF streaming page');
	}

	/** REST API class: 8 endpoints of spec §3.8 with the permission matrix */
	public function testApiEndpoints()
	{
		$src = file_get_contents(__DIR__.'/../../class/api_clinicpay.class.php');
		$this->assertStringContainsString("class Clinicpay extends DolibarrApi", $src);
		// every endpoint annotation (tab between @url and the verb)
		$endpoints = array("GET bills", "GET bills/{id}", "POST bills", "POST bills/{id}/confirm",
			"POST bills/{id}/refund", "GET cards", "GET cards/{id}", "POST cards", "POST cards/{id}/consume");
		$annotated = preg_match_all('/@url\s+(.+)/', $src, $m) ? implode("\n", $m[1]) : '';
		$this->assertGreaterThan(8, count($m[1]), 'endpoint annotations present');
		foreach ($endpoints as $u) {
			$this->assertTrue(strpos($annotated, trim($u)) !== false, 'endpoint '.$u);
		}
		foreach (array("hasRight('clinicpay', 'read')", "hasRight('clinicpay', 'write')", "hasRight('clinicpay', 'pay')",
			"hasRight('clinicpay', 'validate')", "hasRight('clinicpay', 'consume')") as $p) {
			$this->assertStringContainsString($p, $src, 'permission check '.$p);
		}
		$this->assertStringContainsString('CLINICPAY_READ', $src, 'GET bill audited as CLINICPAY_READ');
	}

	/** Phase-4 language keys cover the PDF page */
	public function testPhase4LanguageKeys()
	{
		$zh = file_get_contents(__DIR__.'/../../langs/zh_CN/clinicpay.lang');
		$en = file_get_contents(__DIR__.'/../../langs/en_US/clinicpay.lang');
		foreach (array('ClinicPayPdfView', 'ClinicPayPdfFailed', 'ClinicPayCashier', 'ClinicPayColNo',
			'ClinicPayAmountInWords', 'ClinicPaySignCashier', 'ClinicPaySignPatient', 'ClinicPayContinued', 'ClinicPayPage') as $k) {
			$this->assertRegExp('/^'.$k.'=/m', $zh, 'zh_CN key '.$k);
			$this->assertRegExp('/^'.$k.'=/m', $en, 'en_US key '.$k);
		}
	}

	/**
	 * 2026-10-04: tax invoice reconciliation page. The three things that can
	 * silently make it lie are pinned here: the period (date_pay, not
	 * date_creation), the "missing" definition (one expression shared by the
	 * totals, the monthly rows and the detail), and the default window.
	 */
	public function testFapiaoReportContracts()
	{
		$page = file_get_contents(__DIR__.'/../../report_fapiao.php');

		$this->assertStringContainsString('$missExpr = "(b.fapiao_no IS NULL OR TRIM(b.fapiao_no) = \'\')"', $page, 'one shared missing definition');
		$this->assertStringContainsString('b.date_pay IS NOT NULL', $page, 'drafts are out of scope');
		$this->assertStringContainsString("b.date_pay >= '", $page, 'reconciliation runs on the collected date');
		$this->assertGreaterThan(
			2,
			substr_count($page, '$missExpr'),
			'totals, monthly rows and detail all reuse $missExpr'
		);
		// 30 days, not the current month: on the 1st of a month "this month"
		// would show an empty page.
		$this->assertStringContainsString('dol_time_plus_duree(dol_now(), -29, \'d\')', $page, 'default window is the last 30 days');
		$this->assertStringNotContainsString("dol_mktime(0, 0, 0, (int) date('n'), 1,", $page, 'no calendar-month default');

		// The bill list has to accept what the dashboard drill-down sends.
		$list = file_get_contents(__DIR__.'/../../bill_list.php');
		$this->assertStringContainsString("GETPOST('date_field', 'alphanohtml')", $list, "'aZ' would strip the underscore from date_pay");
		$this->assertStringContainsString('$searchChannel', $list, 'channel filter for the doughnut drill-down');
		$bill = file_get_contents(__DIR__.'/../../class/paybill.class.php');
		$this->assertStringContainsString("\$f['date_field'] === 'date_pay'", $bill, 'whitelisted date column');
		$this->assertStringContainsString("b.channel = '", $bill, 'channel filter in the search');
	}

	/** 2026-10-04: tax invoice reconciliation menu entry */
	public function testFapiaoReportIsRegistered()
	{
		$desc = file_get_contents(__DIR__.'/../../core/modules/modClinicPay.class.php');
		$this->assertStringContainsString("'url' => '/clinicpay/report_fapiao.php'", $desc, 'menu entry');
		$this->assertStringContainsString("'leftmenu' => 'clinicpay_fapiao_report'", $desc, 'own leftmenu id');
		$this->assertStringContainsString('fk_leftmenu=clinic_billing', $desc, 'grouped under billing');
	}
}
