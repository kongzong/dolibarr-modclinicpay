<?php
/* Copyright (C) 2026 modClinicPay contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Render the charge-bill layout (pdf_sf) with real TCPDF, isolated from
 * the database (stub object, in-memory company). Writes fixtures to
 * temp/pdf-acceptance/ and checks page counts / text presence (numbers,
 * patient, capital amount, signature slots, refunded watermark).
 * Run: php tests/integration/pdf.php
 */

if (PHP_SAPI !== 'cli') {
	die('CLI only');
}
error_reporting(E_ALL & ~E_DEPRECATED);
set_error_handler(function ($severity, $message, $file, $line) {
	if (!(error_reporting() & $severity)) {
		return false;
	}
	throw new ErrorException($message, 0, $severity, $file, $line);
});
date_default_timezone_set('Asia/Shanghai');
define('DOL_DOCUMENT_ROOT', getenv('DOLIBARR_DOCUMENT_ROOT') ?: dirname(__DIR__, 4));
define('DOL_URL_ROOT', '');
define('DOL_MAIN_URL_ROOT', 'http://localhost');
define('DOL_VERSION', '22.0.4');
define('DOL_DATA_ROOT', dirname(__DIR__, 2).'/temp/pdf-runtime');
define('TCPDF_PATH', DOL_DOCUMENT_ROOT.'/includes/tecnickcom/tcpdf/');
define('TCPDI_PATH', DOL_DOCUMENT_ROOT.'/includes/tcpdi/');
define('MAIN_DB_PREFIX', 'llx_');

require_once DOL_DOCUMENT_ROOT.'/core/lib/functions.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/conf.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/translate.class.php';

$conf = new Conf();
$conf->entity = 1;
$conf->file->dol_document_root = array('main' => DOL_DOCUMENT_ROOT, 'custom' => dirname(__DIR__, 3));
$conf->file->dol_url_root = array('main' => '', 'custom' => '/custom');
$conf->global = (object) array(
	'MAIN_PDF_FORMAT' => 'A4', 'MAIN_DISABLE_TCPDI' => 1, 'TCPDF_THROW_ERRORS_INSTEAD_OF_DIE' => 1,
	'MAIN_MAX_DECIMALS_UNIT' => 5, 'MAIN_MAX_DECIMALS_TOT' => 2, 'MAIN_MAX_DECIMALS_SHOWN' => '2',
	'MAIN_MAX_DECIMALS_SHOWN_MAIN' => '2', 'MAIN_THOUSANDS_SEP' => ' ', 'MAIN_DECIMAL_SEP' => '.',
);
$outputDir = getenv('CLINICPAY_PDF_OUTPUT') ?: dirname(__DIR__, 2).'/temp/pdf-acceptance';
$conf->clinicpay = (object) array('dir_output' => $outputDir, 'enabled' => 1);
if (dol_mkdir($outputDir) < 0) {
	throw new RuntimeException('Cannot create PDF fixture output directory');
}

// dol_include_once() in the templates resolves through $conf->file->dol_document_root; no DB needed
$langs = new Translate('', $conf);
$langs->dir[] = dirname(__DIR__, 2);
$langs->dir[] = dirname(__DIR__, 3).'/patient';
$langs->setDefaultLang('zh_CN');
$langs->load('main', 0, 0, '', 1);
$langs->load('companies', 0, 0, '', 1);
$langs->load('patient', 0, 0, '', 1);
$langs->load('clinicpay', 0, 0, '', 1);

$mysoc = (object) array('name' => '示例中医馆（测试）', 'country_code' => 'CN', 'address' => '', 'phone' => '');
$user = (object) array('id' => 1, 'login' => 'test');
$db = null;

require_once dirname(__DIR__, 2).'/lib/clinicpay.lib.php';
require_once dirname(__DIR__, 2).'/core/modules/clinicpay/doc/pdf_sf.modules.php';

/**
 * Build a stub Paybill-like object with the fields the template reads.
 *
 * @param	string	$ref		Bill ref
 * @param	int		$status		CLINICPAY_BILL_*
 * @param	array	$lines		Bill line stubs
 * @param	array	$extra		Overrides
 * @return	object
 */
function fixture($ref, $status, $lines, $extra = array())
{
	$o = (object) array_merge(array(
		'id' => 1, 'ref' => $ref, 'status' => $status, 'fk_patient' => 1,
		'amount_total' => 0, 'channel' => 'CASH', 'channel_ref' => '',
		'date_pay' => $status >= 1 ? strtotime('2026-09-24 15:30:00') : null,
		'fk_user_pay' => 2, 'cashier_name' => '钱收银',
		'patient_name' => '李四', 'card_no' => 'HZ-202609-0002',
		'note' => '', 'lines' => $lines,
	), $extra);
	return $o;
}

/**
 * @param	string	$label	Item label
 * @param	mixed	$qty	Quantity
 * @param	float	$pu		Unit price
 * @param	float	$vat	VAT rate
 * @param	float	$sub	Line total (TTC snapshot)
 * @return	array
 */
function sfLine($label, $qty, $pu, $vat, $sub)
{
	return array(
		'fk_product' => null, 'product_ref' => '', 'label' => $label,
		'qty' => $qty, 'price_unit' => $pu, 'vat_rate' => $vat, 'subprice_total' => $sub,
	);
}

$cases = array();
// 1. Paid bill: three lines, numeric + capital total, signature slots
$lines = array(
	sfLine('挂号费', 1, 30, 0, 30),
	sfLine('针灸治疗（30分钟）', 2, 88, 0, 176),
	sfLine('艾条（自制剂）', 3, 12.5, 0, 37.5),
);
$cases[] = array('name' => 'paid', 'object' => fixture('SF-20260924-001', CLINICPAY_BILL_PAID, $lines, array('amount_total' => 243.5)), 'pages' => 1,
	'must' => array('收费单', 'SF-20260924-001', '李四', 'HZ-202609-0002', '挂号费', '针灸治疗', '243.50', '贰佰肆拾叁元伍角整', '钱收银', '收银员签字', '患者签字', '现金'),
	'mustnot' => array('已退费'));
// 2. Refunded bill: 已退费 watermark
$cases[] = array('name' => 'refunded', 'object' => fixture('SF-20260924-002', CLINICPAY_BILL_REFUNDED, $lines, array('amount_total' => 243.5)), 'pages' => 1,
	'must' => array('已退费'), 'mustnot' => array());
// 3. SCAN channel + channel_ref + note
$cases[] = array('name' => 'scan', 'object' => fixture('SF-20260924-003', CLINICPAY_BILL_PAID, array(sfLine('诊金', 1, 50, 0, 50)), array('amount_total' => 50, 'channel' => 'SCAN', 'channel_ref' => 'WX1000234567', 'note' => '偶数行备注测试')), 'pages' => 1,
	'must' => array('扫码', 'WX1000234567', '伍拾元整', '备注测试'), 'mustnot' => array());

$passed = 0;
$failed = 0;
$manifest = array();
foreach ($cases as $c) {
	try {
		$gen = new pdf_sf($db);
		$r = $gen->write_file($c['object'], $langs);
		if ($r <= 0) {
			throw new RuntimeException('write_file returned '.$r.': '.($gen->error ?: ''));
		}
		$file = $gen->result['fullpath'];
		if (!is_file($file) || filesize($file) < 1000) {
			throw new RuntimeException('PDF missing or too small');
		}
		$raw = file_get_contents($file);
		// TCPDF writes page objects uncompressed: count them
		$pages = preg_match_all('#/Type\s*/Page(?!s)#', $raw);
		if (isset($c['pages']) && $pages !== $c['pages']) {
			throw new RuntimeException('expected '.$c['pages'].' page(s), got '.$pages);
		}
		// Text check through pypdf (black box). TCPDF's stsongstdlight CID
		// subset splits text into non-contiguous UTF-16BE operands, so raw
		// stream matching is unreliable; pypdf honours the ToUnicode CMap.
		$py = getenv('CLINICPAY_PDF_PYTHON') ?: 'C:/Users/MR/.workbuddy/binaries/python/versions/3.13.12/python.exe';
		$txtFile = $file.'.txt';
		$cmd = '"'.addcslashes($py, '"').'" -c "import sys; from pypdf import PdfReader; sys.stdout.write(chr(10).join((p.extract_text() or chr(32)) for p in PdfReader(r\"'.addcslashes($file, '\\').'\").pages))" > "'.addcslashes($txtFile, '"').'" 2>&1';
		exec($cmd, $outLines, $rc);
		$text = (string) file_get_contents($txtFile);
		if ($rc !== 0 || trim($text) === '') {
			throw new RuntimeException('pypdf extraction failed (rc='.$rc.'): '.implode(' ', $outLines));
		}
		if (strpos($text, 'pypdf') !== false && strpos($text, 'Error') !== false) {
			throw new RuntimeException('pypdf error: '.$text);
		}
		foreach ($c['must'] as $needle) {
			if (strpos($text, $needle) === false) {
				throw new RuntimeException('missing text: '.$needle);
			}
		}
		foreach ($c['mustnot'] as $needle) {
			if (strpos($text, $needle) !== false) {
				throw new RuntimeException('unexpected text: '.$needle);
			}
		}
		$manifest[] = array('name' => $c['name'], 'file' => $file, 'pages' => $pages);
		echo 'PASS  '.$c['name'].' ('.$pages.' page(s), '.basename($file).")\n";
		$passed++;
	} catch (Throwable $e) {
		echo 'FAIL  '.$c['name'].' - '.$e->getMessage()."\n";
		$failed++;
	}
}
file_put_contents($outputDir.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
echo "\nResult: $passed passed, $failed failed\n";
exit($failed ? 1 : 0);
