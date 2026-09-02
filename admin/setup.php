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
 * Page de configuration du module Docclibarr : adresse de la boîte mail dédiée et
 * identifiants OAuth Gmail (voir SPEC.md section 4 et 16). Rien de tout ça ne doit
 * jamais être codé en dur, uniquement stocké via dolibarr_set_const().
 */

// Chemin d'inclusion du main.inc.php variable selon la profondeur de déploiement, voir
// le gotcha déjà rencontré sur DoliFius (main.inc.php introuvable si le dossier racine
// ne s'appelle pas exactement "docclibarr").
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

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once __DIR__.'/../class/gmailmailboxreader.class.php';
// IngestionWorker n'est PAS chargé ici mais seulement au moment de l'action
// test_ingestion (voir plus bas) : son propre require_once vers EcmFiles peut échouer
// sur un chemin Dolibarr incorrect, ce qui ferait planter cette page entière à chaque
// chargement, sans même passer par la moindre action. Ne charger que ce qui est
// nécessaire, seulement quand c'est nécessaire.

global $langs, $user, $conf;

$langs->loadLangs(array('admin', 'docclibarr@docclibarr'));

if (!$user->admin) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');

if ($action === 'update') {
	dolibarr_set_const($db, 'DOCCLIBARR_MAILBOX_ADDRESS', GETPOST('mailbox_address', 'alpha'), 'chaine', 0, '', $conf->entity);
	dolibarr_set_const($db, 'DOCCLIBARR_GMAIL_CLIENT_ID', GETPOST('gmail_client_id', 'alpha'), 'chaine', 0, '', $conf->entity);
	dolibarr_set_const($db, 'DOCCLIBARR_GMAIL_CLIENT_SECRET', GETPOST('gmail_client_secret', 'alpha'), 'chaine', 0, '', $conf->entity);
	// Refresh token : seulement mis à jour s'il est renseigné, pour ne pas l'écraser par
	// une valeur vide si le champ n'est pas retapé à chaque sauvegarde de formulaire.
	$refreshToken = GETPOST('gmail_refresh_token', 'alpha');
	if ($refreshToken !== '') {
		dolibarr_set_const($db, 'DOCCLIBARR_GMAIL_REFRESH_TOKEN', $refreshToken, 'chaine', 0, '', $conf->entity);
	}

	setEventMessages($langs->trans("SetupSaved"), null);
}

// Rempli seulement par action=test_connection ci-dessous, affiché dans un bloc <pre>
// après le formulaire (voir plus bas) : plus fiable qu'un message d'alerte Dolibarr pour
// lire/copier un détail de diagnostic potentiellement long, notamment sans accès complet
// aux logs serveur.
$testConnectionDebug = null;

if ($action === 'test_connection') {
	// Teste toujours la configuration réellement enregistrée en base (voir la mise à
	// jour ci-dessus), pas les valeurs éventuellement retapées dans le formulaire sans
	// avoir cliqué "Enregistrer" : évite toute ambiguïté sur ce qui est réellement testé.
	$clientId = $conf->global->DOCCLIBARR_GMAIL_CLIENT_ID ?? '';
	$clientSecret = $conf->global->DOCCLIBARR_GMAIL_CLIENT_SECRET ?? '';
	$refreshToken = $conf->global->DOCCLIBARR_GMAIL_REFRESH_TOKEN ?? '';

	if ($clientId === '' || $clientSecret === '' || $refreshToken === '') {
		setEventMessages($langs->trans("DocclibarrTestConnectionMissingConfig"), null, 'errors');
	} else {
		try {
			$reader = new GmailMailboxReader($clientId, $clientSecret, $refreshToken);
			$result = $reader->testConnection();
		} catch (Exception $e) {
			// Le constructeur peut lever une exception si le rafraîchissement du token
			// échoue (voir GmailMailboxReader), à traiter comme un échec de connexion au
			// même titre qu'un échec de testConnection() elle-même.
			$result = array('success' => false, 'error' => get_class($e).' : '.$e->getMessage());
		}

		$testConnectionDebug = $result;

		if ($result['success']) {
			setEventMessages(sprintf($langs->trans("DocclibarrTestConnectionSuccess"), $result['email'], $result['messagesTotal']), null);
		} else {
			setEventMessages(sprintf($langs->trans("DocclibarrTestConnectionFailure"), $result['error']), null, 'errors');
		}
	}
}

// Même principe que $testConnectionDebug ci-dessus, pour le run manuel de l'ingestion
// complète. Volontairement lancé directement dans une page normale plutôt que via l'URL
// du cron (cron/list.php) : ça permet de capturer même une erreur fatale PHP (via
// catch (\Throwable), qui attrape aussi les erreurs de classe/fichier manquant, pas
// seulement les Exception) et de l'afficher ici, sans avoir besoin d'accès aux logs
// serveur ni de comprendre le mécanisme interne d'exécution du cron Dolibarr.
$testIngestionDebug = null;

// Chemins Dolibarr/module dont dépend IngestionWorker (directement ou via les classes
// qu'il charge). Vérifiés avec file_exists() AVANT tout require_once : un require_once
// sur un fichier absent est un échec fatal PHP non rattrapable par un try/catch, même
// avec \Throwable, contrairement à une classe manquante via autoload. C'est ce qui a fait
// planter cette page entière une première fois (voir plus haut, IngestionWorker chargé
// en haut de fichier), et ce qui aurait aussi fait planter uniquement ce bouton sinon.
// Calculé systématiquement (pas seulement sur action=test_ingestion), affiché plus bas
// dans un bloc toujours visible : donne un diagnostic exploitable même sans rien cliquer.
$requiredPaths = array(
	'ecm/class/ecmfiles.class.php (Dolibarr)' => DOL_DOCUMENT_ROOT.'/ecm/class/ecmfiles.class.php',
	'core/class/commonobject.class.php (Dolibarr)' => DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php',
	'fourn/class/fournisseur.facture.class.php (Dolibarr, utilisé par card.php)' => DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.facture.class.php',
	'societe/class/societe.class.php (Dolibarr, utilisé par card.php)' => DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php',
	'vendor/autoload.php (composer)' => __DIR__.'/../vendor/autoload.php',
	'class/gmailmailboxreader.class.php (module)' => __DIR__.'/../class/gmailmailboxreader.class.php',
	'class/originverifier.class.php (module)' => __DIR__.'/../class/originverifier.class.php',
	'class/mimeattachmentextractor.class.php (module)' => __DIR__.'/../class/mimeattachmentextractor.class.php',
	'class/ublinvoiceparser.class.php (module)' => __DIR__.'/../class/ublinvoiceparser.class.php',
	'class/facturationelectroniquestaging.class.php (module)' => __DIR__.'/../class/facturationelectroniquestaging.class.php',
	'class/invoicematcher.class.php (module)' => __DIR__.'/../class/invoicematcher.class.php',
	'class/ingestionworker.class.php (module)' => __DIR__.'/../class/ingestionworker.class.php',
);

$missingPaths = array();
foreach ($requiredPaths as $label => $path) {
	if (!file_exists($path)) {
		$missingPaths[$label] = $path;
	}
}

if ($action === 'test_ingestion') {
	if (!empty($missingPaths)) {
		$testIngestionDebug = array('missing_files' => $missingPaths);
		setEventMessages("Fichier(s) manquant(s), voir le détail ci-dessous, l'ingestion n'a pas été lancée", null, 'errors');
	} else {
		try {
			require_once __DIR__.'/../class/ingestionworker.class.php';
			$worker = new IngestionWorker($db);
			$runResult = $worker->run();

			$testIngestionDebug = array(
				'runResult' => $runResult,
				'processedCount' => $worker->processedCount,
				'skippedDuplicatesCount' => $worker->skippedDuplicatesCount,
				'ignoredCount' => $worker->ignoredCount,
				'errors' => $worker->errors,
			);

			if ($runResult === 1 && empty($worker->errors)) {
				setEventMessages("Ingestion terminée sans erreur, voir le détail ci-dessous", null);
			} else {
				setEventMessages("Ingestion terminée avec des erreurs, voir le détail ci-dessous", null, 'errors');
			}
		} catch (\Throwable $e) {
			// \Throwable, pas juste Exception : capture aussi les erreurs fatales PHP
			// modernes (classe introuvable, appel de méthode inexistante, etc.), qui
			// n'étendent pas Exception mais Error depuis PHP 7. Utile pour tout ce qui
			// reste une erreur APRÈS le chargement des fichiers (déjà vérifiés exister
			// ci-dessus), par exemple une méthode Dolibarr appelée avec la mauvaise
			// signature.
			$testIngestionDebug = array(
				'fatal_error' => get_class($e).' : '.$e->getMessage(),
				'file' => $e->getFile(),
				'line' => $e->getLine(),
			);
			setEventMessages("Erreur fatale pendant l'ingestion, voir le détail ci-dessous", null, 'errors');
		}
	}
}

/**
 * Vide les données de test Docclibarr : uniquement la table de staging du module et les
 * documents ECM stockés sous filepath = 'docclibarr/...', jamais rien d'autre dans
 * Dolibarr. Pratique pour retester l'ingestion depuis un état propre sans repasser par
 * phpMyAdmin à chaque fois.
 *
 * @param DoliDB $db
 * @return array{staging: int, ecm: int, files: int}
 */
function docclibarr_flush_test_data($db)
{
	$counts = array('staging' => 0, 'ecm' => 0, 'files' => 0);

	$resql = $db->query("DELETE FROM ".MAIN_DB_PREFIX."facturation_electronique_staging");
	if ($resql) {
		$counts['staging'] = $db->affected_rows($resql);
	}

	$resql = $db->query("DELETE FROM ".MAIN_DB_PREFIX."ecm_files WHERE filepath LIKE 'docclibarr/%'");
	if ($resql) {
		$counts['ecm'] = $db->affected_rows($resql);
	}

	$dir = DOL_DATA_ROOT.'/docclibarr';
	if (is_dir($dir)) {
		$counts['files'] = docclibarr_delete_directory_recursive($dir);
	}

	return $counts;
}

/**
 * @param string $dir
 * @return int Nombre de fichiers supprimés
 */
function docclibarr_delete_directory_recursive($dir)
{
	$count = 0;
	$items = scandir($dir);
	if ($items === false) {
		return 0;
	}

	foreach ($items as $item) {
		if ($item === '.' || $item === '..') {
			continue;
		}
		$path = $dir.'/'.$item;
		if (is_dir($path)) {
			$count += docclibarr_delete_directory_recursive($path);
			@rmdir($path);
		} else {
			if (@unlink($path)) {
				$count++;
			}
		}
	}

	return $count;
}

$testFlushDebug = null;

if ($action === 'flush_test_data') {
	if (!$user->admin) {
		accessforbidden();
	}
	try {
		$testFlushDebug = docclibarr_flush_test_data($db);
		setEventMessages("Données de test vidées, voir le détail ci-dessous", null);
	} catch (\Throwable $e) {
		$testFlushDebug = array('fatal_error' => get_class($e).' : '.$e->getMessage());
		setEventMessages("Erreur pendant le vidage, voir le détail ci-dessous", null, 'errors');
	}
}

// Diagnostic : reproduit exactement ce que fait docclibarr/list.php (instancier
// FacturationElectroniqueStaging, appeler fetchAll()), mais depuis cette page dont on
// sait qu'elle fonctionne. Si ça passe ici mais que list.php plante en 500 muet, le
// problème est spécifique au fichier/déploiement de list.php, pas à la logique elle-même.
$testFetchAllDebug = null;

if ($action === 'test_fetch_all') {
	try {
		require_once __DIR__.'/../class/facturationelectroniquestaging.class.php';
		$stagingTest = new FacturationElectroniqueStaging($db);
		$recordsTest = $stagingTest->fetchAll('DESC', 'email_received_at', 0, 0, array());
		$testFetchAllDebug = array(
			'result_type' => gettype($recordsTest),
			'count' => is_array($recordsTest) ? count($recordsTest) : $recordsTest,
		);
		setEventMessages("fetchAll() a réussi, voir le détail ci-dessous", null);
	} catch (\Throwable $e) {
		$testFetchAllDebug = array(
			'fatal_error' => get_class($e).' : '.$e->getMessage(),
			'file' => $e->getFile(),
			'line' => $e->getLine(),
		);
		setEventMessages("fetchAll() a échoué, voir le détail ci-dessous", null, 'errors');
	}
}

$page_name = $langs->trans("DocclibarrSetupPage");
llxHeader('', $page_name);

print load_fiche_titre($page_name, '', 'docclibarr@docclibarr');

// Diagnostic fichiers, toujours visible, sans risque de plantage (uniquement des
// file_exists()) : premier endroit à regarder en cas de 500 sur cette page ou sur le
// cron, ça pointe directement vers le chemin Dolibarr incorrect s'il y en a un.
print '<div class="marginTopOnly">';
if (empty($missingPaths)) {
	print '<div class="ok">Diagnostic fichiers : tous les fichiers requis sont trouvés sur le serveur.</div>';
} else {
	print '<div class="error"><b>Diagnostic fichiers : '.count($missingPaths).' fichier(s) introuvable(s) :</b>';
	print '<pre style="white-space:pre-wrap;word-break:break-all;background:#fff0f0;padding:10px;border:1px solid #e0b0b0;">';
	foreach ($missingPaths as $label => $path) {
		print dol_escape_htmltag($label.' : '.$path)."\n";
	}
	print '</pre></div>';
}
print '</div>';

print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="update">';

print '<table class="noborder centpercent">';

print '<tr class="liste_titre"><td>'.$langs->trans("Parameter").'</td><td>'.$langs->trans("Value").'</td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans("DocclibarrMailboxAddress").'</td>';
print '<td><input type="text" class="minwidth300" name="mailbox_address" value="'.dol_escape_htmltag($conf->global->DOCCLIBARR_MAILBOX_ADDRESS ?? '').'"></td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans("DocclibarrGmailClientId").'</td>';
print '<td><input type="text" class="minwidth300" name="gmail_client_id" value="'.dol_escape_htmltag($conf->global->DOCCLIBARR_GMAIL_CLIENT_ID ?? '').'"></td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans("DocclibarrGmailClientSecret").'</td>';
print '<td><input type="password" class="minwidth300" name="gmail_client_secret" value="'.dol_escape_htmltag($conf->global->DOCCLIBARR_GMAIL_CLIENT_SECRET ?? '').'"></td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans("DocclibarrGmailRefreshToken").'</td>';
print '<td><input type="password" class="minwidth300" name="gmail_refresh_token" value="" placeholder="'.($conf->global->DOCCLIBARR_GMAIL_REFRESH_TOKEN ?? '' !== '' ? '••••••••' : '').'"></td></tr>';

print '</table>';

print '<div class="center"><input type="submit" class="button" value="'.$langs->trans("Save").'"></div>';

print '</form>';

// Formulaire séparé, volontaire : teste toujours la config déjà enregistrée en base
// (voir action=test_connection ci-dessus), pas ce qui traîne dans le formulaire au-dessus
// sans avoir été sauvegardé.
print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="test_connection">';
print '<div class="center marginTopOnly"><input type="submit" class="button" value="'.$langs->trans("DocclibarrTestConnection").'"></div>';
print '</form>';

// Détail brut du dernier test, affiché tel quel : plus simple à copier/coller que le
// message d'alerte au-dessus, notamment quand on n'a pas la main sur les logs serveur.
if ($testConnectionDebug !== null) {
	print '<div class="marginTopOnly">';
	print '<b>Détail du dernier test de connexion :</b>';
	print '<pre style="white-space:pre-wrap;word-break:break-all;background:#f5f5f5;padding:10px;border:1px solid #ccc;">';
	print dol_escape_htmltag(print_r($testConnectionDebug, true));
	print '</pre>';
	print '</div>';
}

// Test d'ingestion manuel : lance IngestionWorker directement dans cette page plutôt que
// via le cron, pour capturer une éventuelle erreur fatale sans dépendre des logs serveur
// (voir action=test_ingestion ci-dessus).
print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="test_ingestion">';
print '<div class="center marginTopOnly"><input type="submit" class="button" value="Lancer un test d\'ingestion manuel"></div>';
print '</form>';

if ($testIngestionDebug !== null) {
	print '<div class="marginTopOnly">';
	print '<b>Détail du dernier test d\'ingestion :</b>';
	print '<pre style="white-space:pre-wrap;word-break:break-all;background:#f5f5f5;padding:10px;border:1px solid #ccc;">';
	print dol_escape_htmltag(print_r($testIngestionDebug, true));
	print '</pre>';
	print '</div>';
}

// Purge des données de test (voir action=flush_test_data ci-dessus) : uniquement les
// données Docclibarr (staging + documents ECM sous docclibarr/), jamais rien d'autre dans
// Dolibarr. Confirmation JS puisque c'est une suppression, même limitée à nos propres
// données de test.
print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'" onsubmit="return confirm(\'Vider toutes les données de test Docclibarr (staging + documents ECM du module) ? Irréversible.\');">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="flush_test_data">';
print '<div class="center marginTopOnly"><input type="submit" class="button button-cancel" value="Vider les données de test Docclibarr"></div>';
print '</form>';

if ($testFlushDebug !== null) {
	print '<div class="marginTopOnly">';
	print '<b>Détail du dernier vidage :</b>';
	print '<pre style="white-space:pre-wrap;word-break:break-all;background:#f5f5f5;padding:10px;border:1px solid #ccc;">';
	print dol_escape_htmltag(print_r($testFlushDebug, true));
	print '</pre>';
	print '</div>';
}

// Diagnostic isolant list.php (voir action=test_fetch_all ci-dessus).
print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="test_fetch_all">';
print '<div class="center marginTopOnly"><input type="submit" class="button" value="Tester fetchAll() (diagnostic list.php)"></div>';
print '</form>';

if ($testFetchAllDebug !== null) {
	print '<div class="marginTopOnly">';
	print '<b>Détail du test fetchAll() :</b>';
	print '<pre style="white-space:pre-wrap;word-break:break-all;background:#f5f5f5;padding:10px;border:1px solid #ccc;">';
	print dol_escape_htmltag(print_r($testFetchAllDebug, true));
	print '</pre>';
	print '</div>';
}

llxFooter();
