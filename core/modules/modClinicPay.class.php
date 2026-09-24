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
 *  \defgroup   clinicpay    Module ClinicPay
 *  \brief      Clinic charging: a charge bill that creates a native invoice
 *              in the same transaction, plus prepaid count/value cards.
 *
 *  \file       htdocs/custom/clinicpay/core/modules/modClinicPay.class.php
 *  \ingroup    clinicpay
 *  \brief      Description and activation file for module ClinicPay
 */
include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

/**
 *  Description and activation class for module ClinicPay
 */
class modClinicPay extends DolibarrModules
{
	/**
	 * Constructor. Define names, constants, directories, permissions
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		global $conf, $langs;

		$this->db = $db;

		// Healthcare module family: 501600 + 10 per module (spec §9)
		$this->numero = 501640;

		$this->rights_class = 'clinicpay';

		$this->family = "crm";
		$this->module_position = '96';

		$this->name = preg_replace('/^mod/i', '', get_class($this));

		$this->description = "ModuleClinicPayDesc";
		$this->descriptionlong = "ModuleClinicPayDescLong";

		$this->editor_name = 'modClinicPay';
		$this->editor_url = 'https://github.com/kongzong/dolibarr-modclinicpay';

		$this->version = '0.1.0';

		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);

		$this->picto = 'fa-credit-card';

		$this->module_parts = array(
			'triggers' => 0,
			'login' => 0,
			'substitutions' => 0,
			'menus' => 0,
			'tpl' => 0,
			'barcode' => 0,
			// Scalar 1 so commonGenerateDocument() scans /clinicpay/ for
			// core/modules/clinicpay/doc/pdf_*.modules.php (chinadoc probe:
			// array form breaks dol_buildpath)
			'models' => 1,
			'printing' => 0,
			'theme' => 0,
			'css' => array(),
			'js' => array(),
			'hooks' => array(),
			'moduleforexternal' => 0,
			'websitetemplates' => 0,
			'captcha' => 0,
		);

		$this->dirs = array("/clinicpay/temp");

		$this->config_page_url = array("setup.php@clinicpay");

		$this->hidden = getDolGlobalInt('MODULE_CLINICPAY_DISABLED');
		$this->depends = array('modPatient', 'modFacture', 'modBanque');
		$this->requiredby = array();
		$this->conflictwith = array();

		$this->langfiles = array("clinicpay@clinicpay");

		$this->phpmin = array(7, 4);
		$this->need_dolibarr_version = array(20, -3);
		$this->need_javascript_ajax = 1;

		$this->warnings_activation = array();
		$this->warnings_activation_ext = array();

		// Spec §3.1 constants (read at charge/confirm time, only affect new bills)
		$this->const = array(
			0 => array('CLINICPAY_DEFAULT_VAT', 'chaine', '0', 'Default VAT rate for charge items (0 = clinic exempt/simplified scenario)', 0, 'current', 1),
			1 => array('CLINICPAY_CARD_GRACE_DAYS', 'chaine', '0', 'Grace days after card expiry before it is marked expired', 0, 'current', 1),
		);

		if (!isModEnabled("clinicpay")) {
			$conf->clinicpay = new stdClass();
			$conf->clinicpay->enabled = 0;
		}

		// Patient card tabs (spec §3.6): "Charges" and "Prepaid cards".
		// __ID__ is the patient profile rowid (patient_prepare_head passes the
		// PatientProfile object to complete_head_from_modules), matching
		// llx_clinicpay_bill.fk_patient / llx_clinicpay_card.fk_patient.
		$this->tabs = array();
		$this->tabs[] = array('data' => 'patient:+clinicpay_bills:ClinicPayBillTab:clinicpay@clinicpay:$user->hasRight(\'clinicpay\', \'read\'):/clinicpay/patient_tab.php?tab=bills&id=__ID__');
		$this->tabs[] = array('data' => 'patient:+clinicpay_cards:ClinicPayCardTab:clinicpay@clinicpay:$user->hasRight(\'clinicpay\', \'read\'):/clinicpay/patient_tab.php?tab=cards&id=__ID__');

		$this->boxes = array();

		// Permissions: one-level form, ids 50164011..61 (spec §9).
		// read / write / pay / validate / consume / admin.
		$this->rights = array();
		$r = 0;
		$perms = array(11 => 'read', 21 => 'write', 31 => 'pay', 41 => 'validate', 51 => 'consume', 61 => 'admin');
		foreach ($perms as $suffix => $code) {
			$this->rights[$r][0] = $this->numero . $suffix;
			$this->rights[$r][1] = 'ClinicPayPerm'.ucfirst($code);
			$this->rights[$r][4] = $code;
			$r++;
		}

		// Left menu under the shared "Clinic" top menu owned by modPatient
		$this->menu = array();
		$r = 0;

		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=clinic',
			'type' => 'left',
			'titre' => 'ClinicPayBillList',
			'mainmenu' => 'clinic',
			'leftmenu' => 'clinicpay_bill_list',
			'url' => '/clinicpay/bill_list.php',
			'langs' => 'clinicpay@clinicpay',
			'position' => 1400 + $r,
			'enabled' => 'isModEnabled("clinicpay")',
			'perms' => '$user->hasRight("clinicpay", "read")',
			'target' => '',
			'user' => 2,
		);
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=clinic',
			'type' => 'left',
			'titre' => 'ClinicPayCardList',
			'mainmenu' => 'clinic',
			'leftmenu' => 'clinicpay_card_list',
			'url' => '/clinicpay/card_list.php',
			'langs' => 'clinicpay@clinicpay',
			'position' => 1400 + $r,
			'enabled' => 'isModEnabled("clinicpay")',
			'perms' => '$user->hasRight("clinicpay", "read")',
			'target' => '',
			'user' => 2,
		);
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=clinic',
			'type' => 'left',
			'titre' => 'ClinicPaySetup',
			'mainmenu' => 'clinic',
			'leftmenu' => 'clinicpay_setup',
			'url' => '/clinicpay/admin/setup.php',
			'langs' => 'clinicpay@clinicpay',
			'position' => 1400 + $r,
			'enabled' => 'isModEnabled("clinicpay")',
			'perms' => '$user->hasRight("clinicpay", "admin")',
			'target' => '',
			'user' => 0,
		);
	}

	/**
	 *  Function called when module is enabled.
	 *
	 *  @param      string  $options    Options when enabling module ('', 'noboxes')
	 *  @return     int<-1,1>          1 if OK, <=0 if KO
	 */
	public function init($options = '')
	{
		$result = $this->_load_tables('/clinicpay/sql/');
		if ($result < 0) {
			return -1;
		}

		$this->remove($options);

		$sql = array();

		return $this->_init($sql, $options);
	}

	/**
	 *	Function called when module is disabled.
	 *	Removes constants, permissions, menus, tabs only.
	 *	Bills, cards, the logs and both sequence tables are kept (spec §5).
	 *
	 *	@param	string		$options	Options when enabling module ('', 'noboxes')
	 *	@return	int<-1,1>				1 if OK, <=0 if KO
	 */
	public function remove($options = '')
	{
		$sql = array();
		return $this->_remove($sql, $options);
	}
}
