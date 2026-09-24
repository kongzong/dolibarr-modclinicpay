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
 * \file    htdocs/custom/clinicpay/core/modules/clinicpay/doc/pdf_sf.modules.php
 * \ingroup clinicpay
 * \brief   Charge-bill layout (收费单, spec §3.7): institution / bill ref /
 *          patient header, item table (qty / unit price / line total),
 *          numeric + GB/T 15835 capital amount, cashier / patient signature
 *          slots. Refunded bills carry a 已退费 watermark (no separate layout).
 */

require_once DOL_DOCUMENT_ROOT.'/custom/clinicpay/core/modules/clinicpay/modules_clinicpay.php';

/**
 * Class pdf_sf
 */
class pdf_sf extends ModelePDFClinicPay
{
	/** @var array<string,float> Body column widths in mm (A4 content ≈ 186 mm) */
	protected $columns = array(
		'no'     => 8,
		'item'   => 78,
		'qty'    => 18,
		'unit'   => 32,
		'vat'    => 18,
		'sub'    => 32,
	);

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		parent::__construct($db);
		$this->name = 'sf';
		$this->description = 'Charge bill (收费单)';
	}

	/**
	 * Column widths as fractions of the content width.
	 *
	 * @return	array<string,float>
	 */
	protected function colWidths()
	{
		$total = 0;
		foreach ($this->columns as $w) {
			$total += $w;
		}
		$scale = $this->contentWidth() / $total;
		$cols = array();
		foreach ($this->columns as $key => $w) {
			$cols[$key] = $w * $scale;
		}
		return $cols;
	}

	/**
	 * @param	TCPDF	$pdf	PDF
	 * @param	float	$y		Y
	 * @param	array	$texts	Cell texts keyed by column
	 * @param	bool	$header	Header row
	 * @return	float			Row height
	 */
	protected function drawRow($pdf, $y, array $texts, $header = false)
	{
		$cols = $this->colWidths();
		$h = 0;
		foreach ($cols as $key => $cw) {
			$h = max($h, $pdf->getStringHeight($cw, isset($texts[$key]) ? $texts[$key] : '', true));
		}
		$x = $this->marge_gauche;
		foreach ($cols as $key => $cw) {
			$align = in_array($key, array('no', 'qty', 'vat'), true) || $header ? 'C' : 'R';
			if ($header || $key === 'item') {
				$align = $header ? 'C' : 'L';
			}
			$pdf->MultiCell($cw, $h, isset($texts[$key]) ? $texts[$key] : '', 1, $align, false, 1, $x, $y, true, 0, false, true, $h, 'M');
			$x += $cw;
		}
		return $h;
	}

	/**
	 * 正文: item / qty / unit price / vat / line-total table + numeric and
	 * capital (GB/T 15835) grand total.
	 *
	 * @param	TCPDF		$pdf			PDF
	 * @param	object		$object			Paybill (with ->lines, ...)
	 * @param	Translate	$outputlangs	Lang
	 * @param	float		$y				Start Y
	 * @return	float
	 */
	protected function drawBody($pdf, $object, $outputlangs, $y)
	{
		$x = $this->marge_gauche;
		$w = $this->contentWidth();

		$header = array(
			'no'     => $outputlangs->transnoentities('ClinicPayColNo'),
			'item'   => $outputlangs->transnoentities('ClinicPayBillProduct'),
			'qty'    => $outputlangs->transnoentities('ClinicPayBillQty'),
			'unit'   => $outputlangs->transnoentities('ClinicPayBillPriceUnit'),
			'vat'    => $outputlangs->transnoentities('ClinicPayBillVat'),
			'sub'    => $outputlangs->transnoentities('ClinicPayBillSubtotal'),
		);
		$y += $this->drawRow($pdf, $y, $header, true);

		foreach ((array) $object->lines as $i => $l) {
			$texts = array(
				'no'     => (string) ($i + 1),
				'item'   => (string) $l['label'],
				'qty'    => $this->num($l['qty']),
				'unit'   => price((float) $l['price_unit']),
				'vat'    => $this->num($l['vat_rate']).'%',
				'sub'    => price((float) $l['subprice_total']),
			);
			$cols = $this->colWidths();
			$h = 0;
			foreach ($cols as $key => $cw) {
				$h = max($h, $pdf->getStringHeight($cw, $texts[$key], true));
			}
			if ($y + $h > $this->bodyBottom()) {
				$y = $this->newPage($pdf, false);
				$pdf->SetFont($this->font, '', $this->fontSize);
				$y += $this->drawRow($pdf, $y, $header, true);
			}
			$y += $this->drawRow($pdf, $y, $texts);
		}
		if (empty($object->lines)) {
			$y += $this->drawRow($pdf, $y, array('no' => '', 'item' => '—', 'qty' => '', 'unit' => '', 'vat' => '', 'sub' => ''));
		}

		// Grand total row (numeric), then the capital amount (GB/T 15835)
		$totalRow = array('no' => '', 'item' => $outputlangs->transnoentities('Total'), 'qty' => '', 'unit' => '', 'vat' => '', 'sub' => price((float) $object->amount_total));
		$y += 2;
		$pdf->SetFont($this->font, 'B', $this->fontSize);
		$y += $this->drawRow($pdf, $y, $totalRow);
		$pdf->SetFont($this->font, '', $this->fontSize);

		$words = clinicpay_amount_to_chinese((float) $object->amount_total);
		if ($words !== '') {
			$h = $pdf->getStringHeight($w, $outputlangs->transnoentities('ClinicPayAmountInWords').': '.$words, true);
			if ($y + $h > $this->bodyBottom()) {
				$y = $this->newPage($pdf, false);
				$pdf->SetFont($this->font, '', $this->fontSize);
			}
			$y = $this->text($pdf, $x, $y + 1, $w, $outputlangs->transnoentities('ClinicPayAmountInWords').': '.$words);
		}

		$y += 3;
		if (!empty($object->note) && $y + 2 * $this->lineHeight <= $this->bodyBottom()) {
			$y = $this->text($pdf, $x, $y, $w, $outputlangs->transnoentities('ClinicPayBillNote').': '.$object->note);
		}
		return $y;
	}
}
