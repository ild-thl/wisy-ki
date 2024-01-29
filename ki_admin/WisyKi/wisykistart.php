<?php

require_once($_SERVER['DOCUMENT_ROOT'] . "/ki_admin/sql_curr.inc.php");
require_once($_SERVER['DOCUMENT_ROOT'] . "/ki_admin/config/config.inc.php");
require_once($_SERVER['DOCUMENT_ROOT'] . "/ki_admin/WisyKi/KiAdminUtil.php");




//START
/*******************************************************************************
 Connect to the database
 *******************************************************************************/
if (!class_exists('DB_Admin'))
	die("Verzeichnis ung&uuml;ltig.");

$db = new DB_Admin;
KI_ADMIN_UTIL::selectPortalOrFwd301($db);
$wisyPortalId				= intval($db->f('id'));
$wisyPortalModified			= $db->fs('date_modified');
$wisyPortalName				= $db->fs('name');
$wisyPortalKurzname			= $db->fs('kurzname');
$wisyPortalCSS				= trim($db->fs('css')) == '' ? 0 : 1;
$wisyPortalBodyStart		= stripslashes($db->f('bodystart'));
$wisyPortalEinstellungen	= KI_ADMIN_UTIL::explodeSettings($db->fs('einstellungen'));
$wisyPortalFilter			= KI_ADMIN_UTIL::explodeSettings($db->fs('filter'));
$wisyPortalEinstcache		= KI_ADMIN_UTIL::explodeSettings($db->fs('einstcache'));
$wisyPortalUserGrp          = $db->fs('user_grp');

// $wisyPortalEinstellungen = null;
// $db = new DB_Admin;
$ist_domain = strtolower($_SERVER['HTTP_HOST']);
if (substr($ist_domain, 0, 7) == 'wisyisy') {
	$ist_domain = substr($ist_domain, 7 + 1);
}
// find all matching domains with status = "1" - in this case 404 on purpose (mainly for SEO)
$sql = "SELECT * FROM portale WHERE status=1 AND domains LIKE '" . addslashes(str_replace('www.', '', $ist_domain)) . "';";
$db->query($sql);
if ($db->next_record()) {
	$wisyPortalEinstellungen = KI_ADMIN_UTIL::explodeSettings($db->fs('einstellungen'));
} else {
	KI_ADMIN_UTIL::error404();
}
if (strval($wisyPortalEinstellungen['wisyki'] != '')) {
	$GLOBALS['WisyKi'] = true;
}
if (strval($wisyPortalEinstellungen['kibot'] != '')) {
	$GLOBALS['KiBot'] = $wisyPortalEinstellungen['kibot'];
}
if (strval($wisyPortalEinstellungen['minrel'] != '')) {
	$GLOBALS['MinRel'] = $wisyPortalEinstellungen['minrel'];
	if (strval($wisyPortalEinstellungen['maxpop'] != '')) {
		$GLOBALS['MaxPop'] = $wisyPortalEinstellungen['maxpop'];
	}
	/***************************************************************
Collect all needable keyword-types
	 *************************************************************** */
	if (@file_exists($_SERVER['DOCUMENT_ROOT'] . '/ki_admin/WisyKi/config/codes.inc.php')) {
		require_once($_SERVER['DOCUMENT_ROOT'] . '/ki_admin/WisyKi/config/codes.inc.php');
	} else
		die('Verzeichnis unerwartet.');
	global $codes_stichwort_eigenschaften;
	global $wisyki_keywordtypes;
	$tp = explode("###", $codes_stichwort_eigenschaften);
	for ($c1 = 1; $c1 < sizeof((array) $tp); $c1 += 2) {
		switch ($tp[$c1]) {
			case 'ESCO-Kompetenz':
				$wisyki_keywordtypes['ESCO-Kompetenz']  = $tp[$c1 - 1];
				break;
			case 'ESCO-Synonym':
				$wisyki_keywordtypes['ESCO-Synonym']  = $tp[$c1 - 1];
				break;
			case 'ESCO-Beruf':
				$wisyki_keywordtypes['ESCO-Beruf']  = $tp[$c1 - 1];
				break;

			default:
				break;
		}
	}
}
