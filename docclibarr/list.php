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
 * Dashboard de validation, phase 1 : liste en lecture seule des factures électroniques
 * en staging (voir SPEC.md section 11 et 15). Aucune action de validation/rejet/
 * rattachement dans cette première version, ça arrive en phase 2 avec le moteur de
 * matching.
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

require_once __DIR__.'/../class/facturationelectroniquestaging.class.php';

global $langs, $user, $conf, $db;

$langs->loadLangs(array('docclibarr@docclibarr'));

if (!$user->rights->docclibarr->read) {
	accessforbidden();
}

$statusFilter = GETPOST('match_status', 'alpha');

$staging = new FacturationElectroniqueStaging($db);

$filter = array();
if ($statusFilter !== '') {
	$filter['match_status'] = $statusFilter;
}

$records = $staging->fetchAll('DESC', 'email_received_at', 0, 0, $filter);

// Mapping explicite plutôt qu'une transformation de chaîne du statut : plus robuste et
// plus lisible que de reconstruire une clé de langue à la volée.
$matchStatusLangKeys = array(
	FacturationElectroniqueStaging::STATUS_QUARANTINE => 'DocclibarrMatchStatusQuarantine',
	FacturationElectroniqueStaging::STATUS_PENDING => 'DocclibarrMatchStatusPending',
	FacturationElectroniqueStaging::STATUS_AUTO_MATCHED => 'DocclibarrMatchStatusAutoMatched',
	FacturationElectroniqueStaging::STATUS_UNMATCHED => 'DocclibarrMatchStatusUnmatched',
	FacturationElectroniqueStaging::STATUS_VALIDATED => 'DocclibarrMatchStatusValidated',
	FacturationElectroniqueStaging::STATUS_REJECTED => 'DocclibarrMatchStatusRejected',
);

llxHeader('', $langs->trans("DocclibarrArea"));

print load_fiche_titre($langs->trans("DocclibarrArea"), '', 'docclibarr@docclibarr');

// Filtre par statut, voir SPEC.md section 11 : "à traiter, en quarantaine, validé, rejeté"
print '<form method="GET" action="'.$_SERVER["PHP_SELF"].'">';
print '<select name="match_status" onchange="this.form.submit()">';
print '<option value="">'.$langs->trans("All").'</option>';
foreach (array(
	FacturationElectroniqueStaging::STATUS_QUARANTINE,
	FacturationElectroniqueStaging::STATUS_PENDING,
	FacturationElectroniqueStaging::STATUS_AUTO_MATCHED,
	FacturationElectroniqueStaging::STATUS_UNMATCHED,
	FacturationElectroniqueStaging::STATUS_VALIDATED,
	FacturationElectroniqueStaging::STATUS_REJECTED,
) as $statusValue) {
	$selected = ($statusFilter === $statusValue) ? ' selected' : '';
	print '<option value="'.$statusValue.'"'.$selected.'>'.$langs->trans('DocclibarrMatchStatus'.ucfirst(str_replace('_', '', $statusValue))).'</option>';
}
print '</select>';
print '</form>';

print '<table class="liste centpercent">';
print '<tr class="liste_titre">';
print '<td>'.$langs->trans("DocclibarrSupplier").'</td>';
print '<td>'.$langs->trans("DocclibarrInvoiceNumber").'</td>';
print '<td class="right">'.$langs->trans("DocclibarrAmountTTC").'</td>';
print '<td>'.$langs->trans("DocclibarrOriginStatus").'</td>';
print '<td>'.$langs->trans("DocclibarrMatchStatus").'</td>';
print '<td>'.$langs->trans("DocclibarrMatchConfidence").'</td>';
print '</tr>';

if (is_array($records)) {
	foreach ($records as $record) {
		print '<tr class="oddeven">';
		print '<td>'.dol_escape_htmltag($record->supplier_name).'</td>';
		print '<td>'.dol_escape_htmltag($record->invoice_number).'</td>';
		print '<td class="right">'.($record->amount_ttc !== null ? price($record->amount_ttc) : '').'</td>';
		print '<td>'.($record->origin_verified ? img_picto('', 'tick').' '.$langs->trans("DocclibarrOriginVerified") : img_warning().' '.$langs->trans("DocclibarrOriginQuarantine")).'</td>';
		print '<td>'.dol_escape_htmltag($langs->trans('DocclibarrMatchStatus'.ucfirst(str_replace('_', '', $record->match_status)))).'</td>';
		print '<td>'.dol_escape_htmltag($record->match_confidence).'</td>';
		print '</tr>';
	}
} else {
	print '<tr><td colspan="6">'.$langs->trans("None").'</td></tr>';
}

print '</table>';

llxFooter();
