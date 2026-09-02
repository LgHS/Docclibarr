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

require_once DOL_DOCUMENT_ROOT.'/core/class/ecmfiles.class.php';

global $user, $db;

if (!$user->rights->docclibarr->read) {
	accessforbidden();
}

$id = GETPOST('id', 'int');

$ecmfile = new EcmFiles($db);
if ($id <= 0 || $ecmfile->fetch($id) <= 0) {
	http_response_code(404);
	print "Document introuvable";
	exit;
}

$fullPath = DOL_DATA_ROOT.'/'.$ecmfile->filepath.'/'.$ecmfile->filename;

if (!dol_is_file($fullPath)) {
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
