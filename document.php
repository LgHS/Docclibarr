<?php
/* Copyright (C) 2026 iooner.io for Liège Hackerspace
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */
/**
 * Sert un document ECM stocké par IngestionWorker (PDF ou XML), pour la prévisualisation
 * depuis le dashboard (voir SPEC.md section 11 : le PDF doit être prévisualisable
 * directement avant validation).
 *
 * Sécurité : `llx_ecm_files` est la table documentaire GLOBALE de Dolibarr, partagée par
 * tous les modules. Un simple contrôle du droit docclibarr->read ne suffit pas à
 * empêcher un utilisateur d'énumérer des `id` et de récupérer n'importe quel document
 * ECM de l'instance (factures, RH, autre chose), pas seulement ceux de Docclibarr. On
 * exige donc aussi l'id de l'enregistrement de staging concerné, et on vérifie que
 * l'`id` ECM demandé correspond bien à un des fichiers réellement rattachés à CET
 * enregistrement précis (eml/pdf/xml), jamais un id ECM accepté isolément.
 *
 * AVERTISSEMENT : s'appuie sur EcmFiles::fetch() et sur des fonctions utilitaires du
 * cœur Dolibarr (top_httphead, dol_mimetype) non vérifiées contre une instance réelle,
 * voir SPEC.md section 14 (couche 3).
 */

$res = 0;
$tmpDir = __DIR__.'/..';
$depthTry = 0;
while ($depthTry < 5 && !$res) {
	if (file_exists($tmpDir.'/main.inc.php')) {
		$res = @include $tmpDir.'/main.inc.php';
	}
	$tmpDir .= '/..';
	$depthTry++;
}
if (!$res) {
	die("Impossible de trouver main.inc.php de Dolibarr");
}

require_once DOL_DOCUMENT_ROOT.'/ecm/class/ecmfiles.class.php';
require_once __DIR__.'/class/facturationelectroniquestaging.class.php';

global $user, $db;

if (!$user->rights->docclibarr->read) {
	accessforbidden();
}

// Cast explicite : GETPOST('...', 'int') ne garantit pas un vrai type int en sortie sur
// cette instance (trouvé en conditions réelles, la comparaison stricte plus bas échouait
// à cause du type malgré des valeurs identiques).
$id = (int) GETPOST('id', 'int');
$stagingId = (int) GETPOST('staging_id', 'int');

$staging = new FacturationElectroniqueStaging($db);
if ($stagingId <= 0 || $staging->fetch($stagingId) <= 0) {
	http_response_code(404);
	print "Document introuvable";
	exit;
}

// Cast explicite en int : les valeurs venues de la base (via fetch()) peuvent revenir
// en chaîne de caractères, ce qui casserait une comparaison stricte avec $id.
$allowedEcmFileIds = array_map('intval', array_filter(array($staging->eml_ecm_file_id, $staging->pdf_ecm_file_id, $staging->xml_ecm_file_id)));
if ($id <= 0 || !in_array($id, $allowedEcmFileIds, true)) {
	// L'id ECM demandé n'est pas un des fichiers rattachés à cet enregistrement de
	// staging précis : refusé, même si l'id existe bien dans llx_ecm_files pour un
	// autre document Dolibarr.
	http_response_code(403);
	print "Accès refusé";
	exit;
}

$ecmfile = new EcmFiles($db);
if ($ecmfile->fetch($id) <= 0) {
	http_response_code(404);
	print "Document introuvable";
	exit;
}

$fullPath = DOL_DATA_ROOT.'/'.$ecmfile->filepath.'/'.$ecmfile->filename;

// PHP natif plutôt que dol_is_file() : dol_is_dir() s'est révélée ne pas exister en
// conditions réelles (voir IngestionWorker::storeEcmFile), pas la peine de parier sur un
// autre nom de fonction Dolibarr non vérifié pour une simple vérification d'existence.
if (!is_file($fullPath)) {
	http_response_code(404);
	print "Fichier introuvable sur le disque";
	exit;
}

$mimeType = dol_mimetype($ecmfile->filename);

top_httphead($mimeType);
header('Content-Disposition: inline; filename="'.dol_sanitizeFileName($ecmfile->filename).'"');
header('Content-Length: '.filesize($fullPath));

readfile($fullPath);
exit;
