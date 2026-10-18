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
 * \file    htdocs/custom/clinicpay/bundle.php
 * \brief   Manage charge bundles (收费套餐): list + edit + delete. A bundle is
 *          a named row set (product + qty) applied onto a draft charge bill
 *          from bill.php. Line prices are NOT stored — they are snapshotted
 *          from the product current price at charge time. Requires
 *          'clinicpay read' (write to modify).
 */

$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
dol_include_once('/clinicpay/lib/clinicpay.lib.php');
dol_include_once('/clinicpay/class/bundle.class.php');

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array("patient@patient", "clinicpay@clinicpay"));

$id = GETPOSTINT('id');
$action = GETPOST('action', 'aZ09');
$confirm = GETPOST('confirm', 'alpha');

$canRead = $user->hasRight('clinicpay', 'read');
$canWrite = $user->hasRight('clinicpay', 'write');
if (!$canRead) {
	accessforbidden();
}

$form = new Form($db);
$object = new ClinicPayBundle($db);
$products = clinicpay_product_options($db);
$basePage = dol_buildpath('/clinicpay/bundle.php', 1);

$errors = array();

// ---------------------------------------------------------------- actions
if (($action == 'save' || $action == 'confirm_delete') && $canWrite) {
	if ($action == 'confirm_delete' && $confirm == 'yes') {
		if ($object->fetch($id) > 0) {
			$object->delete();
		}
		header("Location: ".$basePage);
		exit;
	}
	if ($action == 'save' && !GETPOST('cancel', 'alpha')) {
		$fkProducts = GETPOST('line_fk_product', 'array');
		$qtys = GETPOST('line_qty', 'array');
		$lines = array();
		foreach ((array) $fkProducts as $i => $pid) {
			$pid = (int) $pid;
			$qty = isset($qtys[$i]) ? price2num((float) $qtys[$i]) : 0;
			if ($pid > 0 && $qty > 0) {
				$lines[] = array('fk_product' => $pid, 'qty' => $qty);
			}
		}
		$object->name = trim(GETPOST('bundle_name', 'alphanohtml'));
		$object->status = GETPOSTINT('bundle_status') ? 1 : 0;
		$object->note = trim(GETPOST('bundle_note', 'alphanohtml'));
		$object->lines = $lines;
		if ($object->name === '') {
			$errors[] = $langs->trans("ClinicPayBundleErrName");
		} elseif (count($lines) === 0) {
			$errors[] = $langs->trans("ClinicPayBundleErrLines");
		} else {
			if ($id > 0) {
				$object->rowid = $id;
				$ok = $object->update($user);
			} else {
				$ok = $object->create($user);
			}
			if ($ok > 0) {
				header("Location: ".$basePage.'?saved=1');
				exit;
			}
			$errors[] = $langs->trans((string) $object->error);
		}
	} elseif ($action == 'save') {
		header("Location: ".$basePage);
		exit;
	}
}

// ---------------------------------------------------------------- state
$editing = ($action == 'create' || ($action == 'edit' && $id > 0)) && $canWrite;
if ($editing && $id > 0) {
	if ($object->fetch($id) <= 0) {
		accessforbidden($langs->trans("ErrorRecordNotFound"));
	}
}
if ($action == 'create' && !$id) {
	$object = new ClinicPayBundle($db);
	$object->status = 1;
}

// Messages must be queued BEFORE llxHeader(): it renders the message area, so
// a setEventMessages() call after it would only show up on the next page.
llxHeader('', $langs->trans("ClinicPayBundle"));

print load_fiche_titre($langs->trans("ClinicPayBundle"), '', 'fa-boxes');

// Validation errors are printed inline: setEventMessages() after llxHeader()
// only surfaces on the next page load, so a rejected save looked like a silent
// no-op (nothing saved, no message).
foreach ($errors as $e) {
	print '<div class="error" style="padding:6px 10px;">'.dol_escape_htmltag($e).'</div>';
}
if (GETPOSTINT('saved') === 1) {
	print '<div class="ok" style="padding:6px 10px;">'.dol_escape_htmltag($langs->trans("RecordSaved")).'</div>';
}

// ---------------------------------------------------------------- edit view
if ($editing) {
	$jsLines = array();
	foreach ($object->lines as $l) {
		$jsLines[] = array(
			'fk_product' => (int) $l['fk_product'],
			'qty' => rtrim(rtrim(number_format((float) $l['qty'], 3, '.', ''), '0'), '.'),
		);
	}

	print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="save">';
	print '<input type="hidden" name="id" value="'.(int) $object->rowid.'">';

	print '<table class="border centpercent">';
	print '<tr><td class="titlefieldcreate fieldrequired">'.$langs->trans("ClinicPayBundleName").'</td><td><input type="text" name="bundle_name" class="minwidth300" value="'.dol_escape_htmltag($object->name).'" maxlength="128"></td></tr>';
	print '<tr><td>'.$langs->trans("ClinicPayBundleStatus").'</td><td>'.$form->selectarray('bundle_status', array('1' => $langs->trans("ClinicPayBundleActive"), '0' => $langs->trans("ClinicPayBundleInactive")), (string) (int) $object->status, 0, 0, 0, '', 0, 0, 0, '', 'minwidth100').'</td></tr>';
	print '<tr><td class="tdtop">'.$langs->trans("ClinicPayBundleNote").'</td><td><textarea name="bundle_note" class="quatrevingtpercent" rows="2">'.dol_escape_htmltag((string) $object->note).'</textarea></td></tr>';
	print '</table>';

	print '<h3 style="margin:16px 0 6px;font-size:14px;">'.$langs->trans("ClinicPayBundleLines").'</h3>';
	print '<div class="opacitymedium paddingbottom">'.$langs->trans("ClinicPayBundlePriceHint").'</div>';
	print '<button type="button" id="bundle_add_line" class="butActionSmall" style="margin-bottom:6px;">+ '.$langs->trans("Add").'</button>';
	print '<table id="bundle_lines" class="noborder centpercent"><thead><tr class="liste_titre">';
	print '<th class="center" style="width:34px;">#</th><th>'.$langs->trans("ClinicPayBillProduct").'</th><th class="right" style="width:140px">'.$langs->trans("ClinicPayBillQty").'</th>';
	print '<th class="center" style="width:34px;"></th></tr></thead><tbody></tbody></table>';

	print '<div class="center" style="margin-top:10px;">';
	print '<input type="submit" class="button" name="submit" value="'.$langs->trans("Save").'">';
	print ' &nbsp; <a class="button button-cancel" href="'.$basePage.'">'.$langs->trans("Cancel").'</a>';
	print '</div>';

	print '<script type="text/javascript">
$(function() {
	var optProducts = '.json_encode($products, JSON_UNESCAPED_UNICODE).';
	var tbody = $("#bundle_lines tbody");
	function renumber() { tbody.find("tr").each(function(i) { $(this).find("td.bundle-no").text(i + 1); }); }
	function addRow(d) {
		d = d || {};
		var tr = $("<tr class=\\"oddeven\\"></tr>");
		tr.append("<td class=\\"bundle-no center\\"></td>");
		var sel = $("<select class=\\"flat minwidth300\\"></select>").attr("name", "line_fk_product[]");
		sel.append($("<option></option>").attr("value", 0).text("-"));
		$.each(optProducts, function(k, v) { sel.append($("<option></option>").attr("value", k).text(v)); });
		sel.val(d.fk_product || 0);
		tr.append($("<td></td>").append(sel));
		var qty = $("<input type=\\"text\\" class=\\"flat width75 right\\">").attr("name", "line_qty[]").val(d.qty || "1");
		tr.append($("<td class=\\"right\\"></td>").append(qty));
		tr.append($("<td class=\\"center\\"></td>").append($("<a href=\\"#\\">&times;</a>").on("click", function(e) { e.preventDefault(); tr.remove(); renumber(); })));
		tbody.append(tr);
		renumber();
		return tr;
	}
	var initial = '.json_encode($jsLines, JSON_UNESCAPED_UNICODE).';
	if (initial.length) { $.each(initial, function(i, d) { addRow(d); }); } else { addRow(); }
	$("#bundle_add_line").on("click", function(e) { e.preventDefault(); addRow(); });
});
</script>';
	print '</form>';
	exit;
}

// ---------------------------------------------------------------- list view
$bundles = ClinicPayBundle::listAll($db);

print '<div style="margin-bottom:10px;">';
if ($canWrite) {
	print '<a class="butAction" href="'.$basePage.'?action=create">+ '.$langs->trans("ClinicPayBundleNew").'</a>';
}
print '</div>';

print '<table class="noborder centpercent"><thead><tr class="liste_titre">';
print '<th>'.$langs->trans("ClinicPayBundleName").'</th>';
print '<th class="center">'.$langs->trans("Status").'</th>';
print '<th class="center">'.$langs->trans("ClinicPayBundleLineCount").'</th>';
print '<th class="center">'.$langs->trans("ClinicPayBundleNote").'</th>';
print '<th class="center" style="width:120px;"></th>';
print '</tr></thead><tbody>';

if (empty($bundles)) {
	print '<tr><td colspan="5"><span class="opacitymedium">'.$langs->trans("ClinicPayBundleEmpty").'</span></td></tr>';
}
foreach ($bundles as $b) {
	print '<tr class="oddeven">';
	print '<td><a href="'.$basePage.'?action=edit&id='.$b['id'].'">'.dol_escape_htmltag($b['name']).'</a></td>';
	print '<td class="center">'.($b['status'] ? $langs->trans("ClinicPayBundleActive") : $langs->trans("ClinicPayBundleInactive")).'</td>';
	print '<td class="center">'.(int) $b['nlines'].'</td>';
	print '<td class="center">'.dol_escape_htmltag((string) $b['note']).'</td>';
	print '<td class="center">';
	if ($canWrite) {
		print '<a class="paddingright" href="'.$basePage.'?action=edit&id='.$b['id'].'" title="'.dol_escape_htmltag($langs->trans("Edit")).'"><span class="fas fa-edit"></span></a>';
		print '<a href="'.$basePage.'?action=delete&id='.$b['id'].'&token='.newToken().'" title="'.dol_escape_htmltag($langs->trans("Delete")).'"><span class="fas fa-trash" style="color:#c0392b;"></span></a>';
	}
	print '</td></tr>';
}
print '</tbody></table>';

if ($action == 'delete' && $id > 0 && $canWrite && $object->fetch($id) > 0) {
	print $form->formconfirm($_SERVER["PHP_SELF"].'?id='.$id.'&action=delete', $langs->trans("ClinicPayBundleDelete"), $langs->trans("ClinicPayBundleConfirmDelete", $object->name), 'confirm_delete', array(), 0, 1);
}
