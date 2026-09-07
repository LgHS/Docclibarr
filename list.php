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

// Vérifié avec file_exists() avant require_once, même précaution que card.php (un chemin
// Dolibarr incorrect est un échec fatal PHP non rattrapable).
if (file_exists(DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.facture.class.php')) {
	require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.facture.class.php';
}
if (file_exists(DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php')) {
	require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
}

global $langs, $user, $conf, $db;

$langs->loadLangs(array('docclibarr@docclibarr'));

if (!$user->rights->docclibarr->read) {
	accessforbidden();
}

// Actions rapides directement depuis la liste (traiter en un clic quand c'est sûr,
// rejeter avec motif), sans passer par la fiche détail. Traitées avant fetchAll() plus
// bas pour que la liste reflète l'état à jour dès ce chargement.
$listAction = GETPOST('action', 'aZ09');

if ($listAction === 'quick_process') {
	if (!$user->rights->docclibarr->validate) {
		accessforbidden();
	}
	$quickId = (int) GETPOST('id', 'int');
	$quickStaging = new FacturationElectroniqueStaging($db);
	if ($quickId > 0 && $quickStaging->fetch($quickId) > 0) {
		// Revérifié ici côté serveur, pas seulement décidé côté affichage (voir plus bas) :
		// résolution du même geste que le bouton "fiche" aurait demandé de faire à la main,
		// voir FacturationElectroniqueStaging::resolveQuickAction() pour les conditions
		// exactes qui rendent ce clic unique "sûr".
		$quickResolution = $quickStaging->resolveQuickAction();

		if ($quickResolution === null) {
			setEventMessages("Impossible de traiter automatiquement cette entrée, ouvrez la fiche pour la traiter à la main", null, 'errors');
		} elseif ($quickResolution['mode'] === 'validate_proposal') {
			$quickResult = $quickStaging->markValidated($user, $quickStaging->matched_object_type, $quickStaging->matched_object_id);
			if ($quickResult > 0) {
				$quickStaging->relinkEcmFiles($user, $quickStaging->matched_object_type, $quickStaging->matched_object_id);
				setEventMessages($langs->trans("RecordSaved"), null);
			} else {
				setEventMessages(implode(' ; ', $quickStaging->errors), null, 'errors');
			}
		} elseif ($quickResolution['mode'] === 'create_draft') {
			$quickNewInvoiceId = $quickStaging->createDraftInvoice($user, $quickResolution['third_party_id']);
			if ($quickNewInvoiceId <= 0) {
				setEventMessages(implode(' ; ', $quickStaging->errors), null, 'errors');
			} else {
				setEventMessages($langs->trans("RecordSaved"), null);
			}
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
} elseif ($listAction === 'quick_reset_broken_link') {
	// Même logique que card.php action 'reset_broken_link' : revérifié côté serveur que
	// l'objet Dolibarr lié n'existe vraiment plus, jamais seulement décidé côté affichage.
	if (!$user->rights->docclibarr->validate) {
		accessforbidden();
	}
	$quickId = (int) GETPOST('id', 'int');
	$quickStaging = new FacturationElectroniqueStaging($db);
	if ($quickId > 0 && $quickStaging->fetch($quickId) > 0
		&& $quickStaging->match_status === FacturationElectroniqueStaging::STATUS_VALIDATED
		&& $quickStaging->matched_object_type === 'invoice_supplier'
		&& !empty($quickStaging->matched_object_id)
		&& class_exists('FactureFournisseur')
	) {
		$quickBrokenLinkCheck = new FactureFournisseur($db);
		if ($quickBrokenLinkCheck->fetch($quickStaging->matched_object_id) > 0) {
			setEventMessages("La facture liée existe toujours dans Dolibarr, réinitialisation refusée", null, 'errors');
		} else {
			$quickResult = $quickStaging->resetToUnmatched($user);
			if ($quickResult > 0) {
				setEventMessages($langs->trans("RecordSaved"), null);
			} else {
				setEventMessages(implode(' ; ', $quickStaging->errors), null, 'errors');
			}
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

// Police réduite dans le tableau (demande explicite, la liste peut contenir beaucoup de
// lignes) et boutons ronds vert/rouge à la place des boutons texte "Valider"/"Rejeter"
// d'origine, plus compacts. Style inline plutôt qu'un fichier CSS séparé à déclarer dans
// module_parts (voir modDocclibarr.class.php) pour une seule page.
print '<style>
.docclibarr-list-table, .docclibarr-list-table th, .docclibarr-list-table td { font-size: 0.9em; }
.docclibarr-quick-btn {
	display: inline-block;
	width: 1.6em;
	height: 1.6em;
	line-height: 1.6em;
	text-align: center;
	border-radius: 50%;
	font-weight: bold;
	cursor: pointer;
	border: none;
	color: #fff;
	padding: 0;
	margin: 0 2px;
}
.docclibarr-quick-validate { background: #4caf50; }
.docclibarr-quick-validate:hover { background: #3d8b40; }
.docclibarr-quick-reject { background: #e53935; }
.docclibarr-quick-reject:hover { background: #b71c1c; }
#docclibarr-reject-modal-backdrop {
	position: fixed; top: 0; left: 0; right: 0; bottom: 0;
	background: rgba(0, 0, 0, 0.4);
	z-index: 1000;
}
#docclibarr-reject-modal-box {
	background: #fff;
	max-width: 420px;
	margin: 12% auto;
	padding: 20px;
	border-radius: 4px;
}
</style>';

// Une seule modale partagée par toutes les lignes (plutôt qu'un formulaire par ligne) :
// docclibarrOpenRejectModal() y injecte juste l\'id de la ligne concernée avant affichage.
print '<div id="docclibarr-reject-modal-backdrop" hidden>';
print '<div id="docclibarr-reject-modal-box">';
print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="quick_reject">';
print '<input type="hidden" name="id" id="docclibarr-reject-id" value="">';
print '<p>'.$langs->trans("DocclibarrRejectionReason").'</p>';
print '<input type="text" name="reason" id="docclibarr-reject-reason" size="40" class="minwidth300">';
print '<div class="marginTopOnly">';
print '<input type="submit" class="button button-cancel smallpaddingimp" value="'.$langs->trans("DocclibarrReject").'">';
print ' <button type="button" class="button smallpaddingimp" onclick="docclibarrCloseRejectModal()">'.$langs->trans("Cancel").'</button>';
print '</div>';
print '</form>';
print '</div>';
print '</div>';

print '<script>
function docclibarrOpenRejectModal(id) {
	document.getElementById("docclibarr-reject-id").value = id;
	document.getElementById("docclibarr-reject-reason").value = "";
	document.getElementById("docclibarr-reject-modal-backdrop").hidden = false;
}
function docclibarrCloseRejectModal() {
	document.getElementById("docclibarr-reject-modal-backdrop").hidden = true;
}
</script>';

print '<table class="liste centpercent docclibarr-list-table">';
print '<tr class="liste_titre">';
print '<td>'.$langs->trans("DocclibarrDocumentType").'</td>';
print '<td>'.$langs->trans("DocclibarrSupplier").'</td>';
print '<td>'.$langs->trans("DocclibarrInvoiceNumber").'</td>';
print '<td class="right">'.$langs->trans("DocclibarrAmountTTC").'</td>';
print '<td>'.$langs->trans("DocclibarrOriginStatus").'</td>';
print '<td>'.$langs->trans("DocclibarrMatchStatus").'</td>';
print '<td>'.$langs->trans("DocclibarrMatchConfidence").'</td>';
print '<td>'.$langs->trans("DocclibarrLinkedInvoice").'</td>';
print '<td></td>';
print '</tr>';

// Pour l'info-bulle stylée Dolibarr sur le motif de rejet (Form::textwithpicto()), voir
// plus bas dans la boucle, plutôt que le tooltip brut du navigateur (title="...").
$listForm = class_exists('Form') ? new Form($db) : null;

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
		// Info-bulle stylée Dolibarr (Form::textwithpicto(), la même que sur les infobulles
		// natives Dolibarr, ex: numéro de facture) avec le motif du rejet et son auteur,
		// plutôt que le tooltip brut du navigateur (title="...", moins lisible et pas dans
		// le style de Dolibarr, demande explicite du 2026-09-07). User est une classe cœur
		// Dolibarr toujours chargée, pas besoin de require_once supplémentaire.
		$statusTitle = '';
		if ($record->match_status === FacturationElectroniqueStaging::STATUS_REJECTED) {
			$statusTitleParts = array();
			if (!empty($record->rejection_reason)) {
				$statusTitleParts[] = '<b>'.$langs->trans("DocclibarrRejectionReason").'</b> : '.dol_escape_htmltag($record->rejection_reason);
			}
			if (!empty($record->validated_by)) {
				$rejectedByUser = new User($db);
				if ($rejectedByUser->fetch($record->validated_by) > 0 && method_exists($rejectedByUser, 'getFullName')) {
					$statusTitleParts[] = '<b>'.$langs->trans("DocclibarrRejectedBy").'</b> : '.dol_escape_htmltag($rejectedByUser->getFullName($langs));
				}
			}
			$statusTitle = implode('<br>', $statusTitleParts);
		}
		print '<td>'.($statusTitle !== '' && $listForm !== null ? $listForm->textwithpicto($statusLabel, $statusTitle) : $statusLabel).'</td>';
		print '<td>'.$confidenceLabel.'</td>';

		// Facture Dolibarr liée : soit déjà validée/rattachée (matched_object_id, voir
		// card.php), soit pas encore validée mais une facture avec la même référence
		// fournisseur existe déjà (voir FacturationElectroniqueStaging::findExistingSupplierInvoiceId(),
		// même lien affiché dans les deux cas plutôt que de ne le montrer qu'une fois
		// validé : sans ça, aucun moyen de repérer un doublon potentiel depuis la liste
		// avant d'ouvrir la fiche).
		print '<td>';
		$listLinkedInvoiceId = null;
		if ($record->matched_object_type === 'invoice_supplier' && !empty($record->matched_object_id)) {
			$listLinkedInvoiceId = $record->matched_object_id;
		} else {
			$listLinkedInvoiceId = $record->findExistingSupplierInvoiceId();
		}

		// Lien cassé : entrée validée et rattachée à une facture qui n'existe plus (supprimée
		// côté Dolibarr après coup, voir card.php action 'reset_broken_link'). Distingué du
		// simple indice de doublon (findExistingSupplierInvoiceId(), pas encore validé) :
		// seul le premier cas mérite une alerte, pas le second.
		$listLinkedInvoiceBroken = false;

		if ($listLinkedInvoiceId !== null && class_exists('FactureFournisseur')) {
			$linkedInvoice = new FactureFournisseur($db);
			if ($linkedInvoice->fetch($listLinkedInvoiceId) > 0) {
				$linkedInvoiceStatusLabel = method_exists($linkedInvoice, 'getLibStatut') ? $linkedInvoice->getLibStatut(3) : '';
				// Repli si ->ref ressort vide après fetch() (rencontré en conditions réelles
				// sur cette instance, cause exacte non identifiée) : jamais un lien sans texte.
				$linkedInvoiceRefDisplay = !empty($linkedInvoice->ref) ? $linkedInvoice->ref : (!empty($linkedInvoice->ref_supplier) ? $linkedInvoice->ref_supplier : '#'.$linkedInvoice->id);
				print '<a href="'.dol_buildpath('/fourn/facture/card.php', 1).'?id='.((int) $linkedInvoice->id).'">'.dol_escape_htmltag($linkedInvoiceRefDisplay).'</a>';
				if ($linkedInvoiceStatusLabel !== '') {
					print ' '.$linkedInvoiceStatusLabel;
				}
			} elseif ($record->matched_object_type === 'invoice_supplier' && $record->match_status === FacturationElectroniqueStaging::STATUS_VALIDATED) {
				$listLinkedInvoiceBroken = true;
				print img_warning().' <span style="color:#a94442">'.$langs->trans("DocclibarrLinkedInvoiceNotFound", (string) $listLinkedInvoiceId).'</span>';
			} else {
				print '-';
			}
		} else {
			print '-';
		}
		print '</td>';

		$rowProcessed = in_array($record->match_status, array(
			FacturationElectroniqueStaging::STATUS_VALIDATED,
			FacturationElectroniqueStaging::STATUS_REJECTED,
		), true);

		print '<td class="nowraponall">';
		print '<a href="'.dol_buildpath('/docclibarr/card.php', 1).'?id='.((int) $record->rowid).'">'.img_picto($langs->trans("Show"), 'view').'</a>';

		if (!$rowProcessed && $user->rights->docclibarr->validate) {
			// V vert : uniquement si resolveQuickAction() est sûr de ce qu'il faut faire
			// (proposition automatique existante, ou un unique tiers non ambigu pour créer
			// le brouillon directement). Dans tous les autres cas, pas de bouton ici : il
			// faut ouvrir la fiche (bouton "oeil" ci-dessus, qui reste toujours affiché) pour
			// rattacher manuellement, créer le tiers, ou choisir entre plusieurs candidats.
			$quickAction = $record->resolveQuickAction();
			if ($quickAction !== null) {
				print ' <form method="POST" action="'.$_SERVER["PHP_SELF"].'" style="display:inline">';
				print '<input type="hidden" name="token" value="'.newToken().'">';
				print '<input type="hidden" name="action" value="quick_process">';
				print '<input type="hidden" name="id" value="'.((int) $record->rowid).'">';
				print '<button type="submit" class="docclibarr-quick-btn docclibarr-quick-validate" title="'.$langs->trans("DocclibarrValidate").'">✓</button>';
				print '</form>';
			}

			// X rouge : toujours disponible (rejeter ne dépend d'aucune ambiguïté), ouvre la
			// modale partagée plutôt qu'un champ texte par ligne (voir en haut de page).
			print ' <button type="button" class="docclibarr-quick-btn docclibarr-quick-reject" title="'.$langs->trans("DocclibarrReject").'" onclick="docclibarrOpenRejectModal('.((int) $record->rowid).')">✕</button>';
		}

		// Volontairement en dehors du "!$rowProcessed" ci-dessus : cette entrée EST déjà
		// traitée (validée), c'est justement ce cas-là (facture liée supprimée depuis) qui
		// est traité ici. Voir card.php action 'reset_broken_link', même logique.
		if ($listLinkedInvoiceBroken && $user->rights->docclibarr->validate) {
			print ' <form method="POST" action="'.$_SERVER["PHP_SELF"].'" style="display:inline">';
			print '<input type="hidden" name="token" value="'.newToken().'">';
			print '<input type="hidden" name="action" value="quick_reset_broken_link">';
			print '<input type="hidden" name="id" value="'.((int) $record->rowid).'">';
			print '<button type="submit" class="docclibarr-quick-btn" style="background:#a94442" title="'.$langs->trans("DocclibarrResetBrokenLink").'">↺</button>';
			print '</form>';
		}

		print '</td>';
		print '</tr>';
	}
} else {
	print '<tr><td colspan="9">'.$langs->trans("None").'</td></tr>';
}

print '</table>';

// Légende des statuts, repliée par défaut (voir <details>, natif HTML, pas de JS
// nécessaire) pour ne pas encombrer la page : explique ce que veut dire chaque valeur du
// filtre ci-dessus et quoi faire dans chaque cas, pour ne pas avoir à redemander à chaque
// fois. Ajoutée le 2026-09-07 à la demande de l'utilisateur.
print '<details class="marginTopOnly">';
print '<summary style="cursor:pointer">'.$langs->trans("DocclibarrStatusLegendTitle").'</summary>';
print '<table class="border centpercent" style="margin-top:10px">';
$statusLegend = array(
	'DocclibarrMatchStatusQuarantine' => 'DocclibarrMatchStatusQuarantineHelp',
	'DocclibarrMatchStatusPending' => 'DocclibarrMatchStatusPendingHelp',
	'DocclibarrMatchStatusAutoMatched' => 'DocclibarrMatchStatusAutoMatchedHelp',
	'DocclibarrMatchStatusUnmatched' => 'DocclibarrMatchStatusUnmatchedHelp',
	'DocclibarrMatchStatusValidated' => 'DocclibarrMatchStatusValidatedHelp',
	'DocclibarrMatchStatusRejected' => 'DocclibarrMatchStatusRejectedHelp',
);
foreach ($statusLegend as $labelKey => $helpKey) {
	print '<tr><td class="titlefield">'.$langs->trans($labelKey).'</td><td>'.$langs->trans($helpKey).'</td></tr>';
}
// Confiance "Suspect" : pas un statut à part entière (voir $matchConfidenceLangKeys plus
// haut), mais une valeur de confiance sur un "Non rapproché", assez importante pour
// figurer dans la légende à côté des vrais statuts.
print '<tr><td class="titlefield">'.$langs->trans("DocclibarrMatchConfidenceSuspect").'</td><td>'.$langs->trans("DocclibarrMatchStatusSuspectHelp").'</td></tr>';
print '</table>';
print '</details>';

llxFooter();
