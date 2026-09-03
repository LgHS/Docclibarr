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
 * Dashboard de validation (voir SPEC.md section 11) : liste des factures électroniques
 * en staging, avec filtre par statut et lien vers la fiche détail (docclibarr/card.php)
 * où se font les actions de validation/rattachement/rejet.
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

require_once __DIR__.'/class/facturationelectroniquestaging.class.php';

global $langs, $user, $conf, $db;

$langs->loadLangs(array('docclibarr@docclibarr'));

if (!$user->rights->docclibarr->read) {
	accessforbidden();
}

// Actions rapides directement depuis la liste (valider une proposition automatique,
// rejeter avec motif), sans passer par la fiche détail. Traitées avant fetchAll() plus
// bas pour que la liste reflète l'état à jour dès ce chargement.
$listAction = GETPOST('action', 'aZ09');

if ($listAction === 'quick_validate') {
	if (!$user->rights->docclibarr->validate) {
		accessforbidden();
	}
	$quickId = (int) GETPOST('id', 'int');
	$quickStaging = new FacturationElectroniqueStaging($db);
	if ($quickId > 0 && $quickStaging->fetch($quickId) > 0 && !empty($quickStaging->matched_object_id) && !empty($quickStaging->matched_object_type)) {
		$quickResult = $quickStaging->markValidated($user, $quickStaging->matched_object_type, $quickStaging->matched_object_id);
		if ($quickResult > 0) {
			$quickStaging->relinkEcmFiles($user, $quickStaging->matched_object_type, $quickStaging->matched_object_id);
			setEventMessages($langs->trans("RecordSaved"), null);
		} else {
			setEventMessages(implode(' ; ', $quickStaging->errors), null, 'errors');
		}
	}
} elseif ($listAction === 'quick_reject') {
	if (!$user->rights->docclibarr->validate) {
		accessforbidden();
	}
	$quickId = (int) GETPOST('id', 'int');
	$quickReason = GETPOST('reason', 'restricthtml');
	$quickStaging = new FacturationElectroniqueStaging($db);
	if ($quickId > 0 && $quickStaging->fetch($quickId) > 0) {
		$quickResult = $quickStaging->markRejected($user, $quickReason);
		if ($quickResult > 0) {
			setEventMessages($langs->trans("RecordSaved"), null);
		} else {
			setEventMessages(implode(' ; ', $quickStaging->errors), null, 'errors');
		}
	}
}

$statusFilter = GETPOST('match_status', 'alpha');

$staging = new FacturationElectroniqueStaging($db);

$filter = array();
if ($statusFilter !== '') {
	$filter['match_status'] = $statusFilter;
}

// try/catch(\Throwable) : fetchAllCommon() (CommonObject) n'avait jamais pu être testée
// contre une vraie instance jusqu'ici, contrairement au reste du pipeline d'ingestion.
// Affiche l'erreur exacte au lieu d'un 500 générique si elle échoue, même filet de
// sécurité déjà utile sur admin/setup.php.
try {
	$records = $staging->fetchAll('DESC', 'email_received_at', 0, 0, $filter);
} catch (\Throwable $e) {
	llxHeader('', $langs->trans("DocclibarrArea"));
	print '<div class="error"><b>Erreur fatale : '.get_class($e).' : '.dol_escape_htmltag($e->getMessage()).'</b>';
	print '<br>Fichier : '.dol_escape_htmltag($e->getFile()).' ligne '.((int) $e->getLine());
	print '</div>';
	llxFooter();
	exit;
}

// Mapping explicite plutôt qu'une transformation de chaîne du statut : plus robuste et
// plus lisible que de reconstruire une clé de langue à la volée (bug déjà rencontré ici
// une première fois, cette table est la seule source de vérité maintenant).
$matchStatusLangKeys = array(
	FacturationElectroniqueStaging::STATUS_QUARANTINE => 'DocclibarrMatchStatusQuarantine',
	FacturationElectroniqueStaging::STATUS_PENDING => 'DocclibarrMatchStatusPending',
	FacturationElectroniqueStaging::STATUS_AUTO_MATCHED => 'DocclibarrMatchStatusAutoMatched',
	FacturationElectroniqueStaging::STATUS_UNMATCHED => 'DocclibarrMatchStatusUnmatched',
	FacturationElectroniqueStaging::STATUS_VALIDATED => 'DocclibarrMatchStatusValidated',
	FacturationElectroniqueStaging::STATUS_REJECTED => 'DocclibarrMatchStatusRejected',
);

// Même principe pour la confiance : 'high'/'medium'/'suspect' (voir InvoiceMatcher et
// IngestionWorker), jamais de valeur affichée brute.
$matchConfidenceLangKeys = array(
	'high' => 'DocclibarrMatchConfidenceHigh',
	'medium' => 'DocclibarrMatchConfidenceMedium',
	'suspect' => 'DocclibarrMatchConfidenceSuspect',
);

// Idem pour le type de document (facture ou note de crédit, voir UblInvoiceParser).
$documentTypeLangKeys = array(
	'invoice' => 'DocclibarrDocumentTypeInvoice',
	'credit_note' => 'DocclibarrDocumentTypeCreditNote',
);

llxHeader('', $langs->trans("DocclibarrArea"));

print load_fiche_titre($langs->trans("DocclibarrArea"), '', 'docclibarr@docclibarr');

// Filtre par statut, voir SPEC.md section 11 : "à traiter, en quarantaine, validé, rejeté"
print '<form method="GET" action="'.$_SERVER["PHP_SELF"].'">';
print '<select name="match_status" onchange="this.form.submit()">';
print '<option value="">'.$langs->trans("All").'</option>';
foreach ($matchStatusLangKeys as $statusValue => $langKey) {
	$selected = ($statusFilter === $statusValue) ? ' selected' : '';
	print '<option value="'.$statusValue.'"'.$selected.'>'.$langs->trans($langKey).'</option>';
}
print '</select>';
print '</form>';

print '<table class="liste centpercent">';
print '<tr class="liste_titre">';
print '<td>'.$langs->trans("DocclibarrDocumentType").'</td>';
print '<td>'.$langs->trans("DocclibarrSupplier").'</td>';
print '<td>'.$langs->trans("DocclibarrInvoiceNumber").'</td>';
print '<td class="right">'.$langs->trans("DocclibarrAmountTTC").'</td>';
print '<td>'.$langs->trans("DocclibarrOriginStatus").'</td>';
print '<td>'.$langs->trans("DocclibarrMatchStatus").'</td>';
print '<td>'.$langs->trans("DocclibarrMatchConfidence").'</td>';
print '<td></td>';
print '</tr>';

if (is_array($records) && count($records) > 0) {
	foreach ($records as $record) {
		$statusLabel = isset($matchStatusLangKeys[$record->match_status])
			? $langs->trans($matchStatusLangKeys[$record->match_status])
			: dol_escape_htmltag($record->match_status);

		$confidenceLabel = isset($matchConfidenceLangKeys[$record->match_confidence])
			? $langs->trans($matchConfidenceLangKeys[$record->match_confidence])
			: $langs->trans("DocclibarrMatchConfidenceNone");

		$documentTypeLabel = isset($documentTypeLangKeys[$record->document_type])
			? $langs->trans($documentTypeLangKeys[$record->document_type])
			: dol_escape_htmltag($record->document_type);

		print '<tr class="oddeven">';
		print '<td>'.$documentTypeLabel.'</td>';
		print '<td>'.dol_escape_htmltag($record->supplier_name).'</td>';
		print '<td>'.dol_escape_htmltag($record->invoice_number).'</td>';
		print '<td class="right">'.($record->amount_ttc !== null ? price($record->amount_ttc) : '').'</td>';
		print '<td>'.($record->origin_verified ? img_picto('', 'tick').' '.$langs->trans("DocclibarrOriginVerified") : img_warning().' '.$langs->trans("DocclibarrOriginQuarantine")).'</td>';
		print '<td>'.$statusLabel.'</td>';
		print '<td>'.$confidenceLabel.'</td>';
		$rowProcessed = in_array($record->match_status, array(
			FacturationElectroniqueStaging::STATUS_VALIDATED,
			FacturationElectroniqueStaging::STATUS_REJECTED,
		), true);

		print '<td>';
		print '<a href="'.dol_buildpath('/docclibarr/card.php', 1).'?id='.((int) $record->rowid).'">'.img_picto($langs->trans("Show"), 'view').'</a>';

		if (!$rowProcessed && $user->rights->docclibarr->validate) {
			// Valider : seulement si une proposition automatique existe déjà (niveau 1/2
			// du matching), sinon rien à valider directement depuis la liste, il faut
			// passer par la fiche pour rattacher manuellement ou créer un brouillon.
			if ($record->match_status === FacturationElectroniqueStaging::STATUS_AUTO_MATCHED && !empty($record->matched_object_id)) {
				print ' <form method="POST" action="'.$_SERVER["PHP_SELF"].'" style="display:inline">';
				print '<input type="hidden" name="token" value="'.newToken().'">';
				print '<input type="hidden" name="action" value="quick_validate">';
				print '<input type="hidden" name="id" value="'.((int) $record->rowid).'">';
				print '<input type="submit" class="button smallpaddingimp" value="'.$langs->trans("DocclibarrValidate").'">';
				print '</form>';
			}

			// Rejeter : motif dans un petit champ texte plutôt qu'une invite JS, plus
			// transparent et sans dépendance JS.
			print ' <form method="POST" action="'.$_SERVER["PHP_SELF"].'" style="display:inline">';
			print '<input type="hidden" name="token" value="'.newToken().'">';
			print '<input type="hidden" name="action" value="quick_reject">';
			print '<input type="hidden" name="id" value="'.((int) $record->rowid).'">';
			print '<input type="text" name="reason" size="12" placeholder="'.$langs->trans("DocclibarrRejectionReason").'">';
			print '<input type="submit" class="button button-cancel smallpaddingimp" value="'.$langs->trans("DocclibarrReject").'">';
			print '</form>';
		}

		print '</td>';
		print '</tr>';
	}
} else {
	print '<tr><td colspan="8">'.$langs->trans("None").'</td></tr>';
}

print '</table>';

llxFooter();
