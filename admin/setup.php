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

$page_name = $langs->trans("DocclibarrSetupPage");
llxHeader('', $page_name);

print load_fiche_titre($page_name, '', 'docclibarr@docclibarr');

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

llxFooter();
