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
 * Fiche détail d'un enregistrement de staging (voir SPEC.md section 11) : affiche la
 * proposition de rattachement du moteur de matching et expose les quatre actions
 * possibles, toutes soumises à une confirmation humaine explicite (voir SPEC.md
 * section 8 et 12, rien n'est jamais appliqué automatiquement, y compris niveau 1) :
 * valider la proposition telle quelle, rattacher manuellement un autre objet, créer un
 * brouillon de facture fournisseur, ou rejeter avec motif.
 *
 * AVERTISSEMENT : la création de facture fournisseur (FactureFournisseur::create()) et
 * le re-rattachement des documents ECM à l'objet validé n'ont pas pu être vérifiés
 * contre une instance Dolibarr réelle, voir SPEC.md section 14 (couche 3). À tester
 * prioritairement avec des tiers et montants factices avant tout usage réel.
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

// Vérifiés avec file_exists() avant tout require_once : un require_once sur un chemin
// Dolibarr incorrect est un échec fatal PHP non rattrapable (déjà rencontré une fois
// avec ecm/class/ecmfiles.class.php, voir SPEC.md et admin/setup.php qui fait le même
// diagnostic). Affiche une page d'erreur lisible plutôt qu'un 500 générique.
$cardRequiredPaths = array(
	DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.facture.class.php',
	DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php',
	DOL_DOCUMENT_ROOT.'/ecm/class/ecmfiles.class.php',
	DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php',
);
$cardMissingPaths = array_filter($cardRequiredPaths, function ($path) {
	return !file_exists($path);
});
if (!empty($cardMissingPaths)) {
	print "Fichier(s) Dolibarr introuvable(s) : ".implode(', ', $cardMissingPaths).". Voir le diagnostic complet sur admin/setup.php.";
	exit;
}

require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/ecm/class/ecmfiles.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';

global $langs, $user, $conf, $db;

$langs->loadLangs(array('docclibarr@docclibarr', 'bills', 'companies'));

if (!$user->rights->docclibarr->read) {
	accessforbidden();
}

// Cast explicite : GETPOST('...', 'int') ne garantit pas un vrai type int en sortie sur
// cette instance (trouvé en conditions réelles sur document.php, même remarque ici).
$id = (int) GETPOST('id', 'int');
$action = GETPOST('action', 'aZ09');

$staging = new FacturationElectroniqueStaging($db);
if ($id <= 0 || $staging->fetch($id) <= 0) {
	llxHeader('', $langs->trans("DocclibarrArea"));
	print '<div class="error">'.$langs->trans("ErrorRecordNotFound").'</div>';
	llxFooter();
	exit;
}

$alreadyProcessed = in_array($staging->match_status, array(
	FacturationElectroniqueStaging::STATUS_VALIDATED,
	FacturationElectroniqueStaging::STATUS_REJECTED,
), true);

// Le re-rattachement ECM vit maintenant sur FacturationElectroniqueStaging::relinkEcmFiles(),
// partagé avec les actions rapides de list.php plutôt que dupliqué ici.

if ($action === 'validate_proposal' && !$alreadyProcessed) {
	if (!$user->rights->docclibarr->validate) {
		accessforbidden();
	}
	if (empty($staging->matched_object_id) || empty($staging->matched_object_type)) {
		setEventMessages("Aucune proposition à valider", null, 'errors');
	} else {
		$result = $staging->markValidated($user, $staging->matched_object_type, $staging->matched_object_id);
		if ($result > 0) {
			$staging->relinkEcmFiles($user, $staging->matched_object_type, $staging->matched_object_id);
			setEventMessages($langs->trans("RecordSaved"), null);
		} else {
			setEventMessages(implode(' ; ', $staging->errors), null, 'errors');
		}
	}
} elseif ($action === 'manual_attach' && !$alreadyProcessed) {
	if (!$user->rights->docclibarr->validate) {
		accessforbidden();
	}
	$manualId = (int) GETPOST('supplier_invoice_id', 'int');
	$targetInvoice = new FactureFournisseur($db);
	if ($manualId <= 0 || $targetInvoice->fetch($manualId) <= 0) {
		setEventMessages("Facture fournisseur introuvable (id ".((int) $manualId).")", null, 'errors');
	} else {
		$result = $staging->markValidated($user, 'invoice_supplier', $manualId);
		if ($result > 0) {
			$staging->relinkEcmFiles($user, 'invoice_supplier', $manualId);
			setEventMessages($langs->trans("RecordSaved"), null);
		} else {
			setEventMessages(implode(' ; ', $staging->errors), null, 'errors');
		}
	}
} elseif ($action === 'create_draft' && !$alreadyProcessed) {
	if (!$user->rights->docclibarr->validate) {
		accessforbidden();
	}

	if ($staging->document_type === 'credit_note') {
		// Défense en profondeur : le bouton est déjà masqué pour une note de crédit, mais
		// on refuse aussi l'action côté serveur si elle est soumise quand même.
		setEventMessages("Impossible de créer un brouillon de facture depuis une note de crédit", null, 'errors');
	} else {
		$thirdPartyId = (int) GETPOST('third_party_id', 'int');
		$thirdParty = new Societe($db);

		if ($thirdPartyId <= 0 || $thirdParty->fetch($thirdPartyId) <= 0) {
			setEventMessages($langs->trans("DocclibarrCreateDraftMissingThirdParty"), null, 'errors');
		} else {
			$newInvoice = new FactureFournisseur($db);
			$newInvoice->socid = $thirdParty->id;
			$newInvoice->ref_supplier = $staging->payment_ref_raw !== null ? $staging->payment_ref_raw : $staging->invoice_number;
			$newInvoice->date = $staging->issue_date !== null ? strtotime($staging->issue_date) : dol_now();
			if ($staging->due_date !== null) {
				$newInvoice->date_echeance = strtotime($staging->due_date);
			}
			$newInvoice->label = "Facture ".$staging->supplier_name." n°".$staging->invoice_number;

			$newInvoiceId = $newInvoice->create($user);

			if ($newInvoiceId <= 0) {
				setEventMessages(implode(' ; ', $newInvoice->errors), null, 'errors');
			} else {
				// Une facture sans ligne n'a aucun montant : ajoute une ligne unique avec
				// le HT extrait et le taux de TVA déduit de HT/TTC (une seule ligne, une
				// seule TVA, laissé au brouillon à corriger à la main si la vraie facture
				// a plusieurs lignes ou plusieurs taux, voir SPEC.md section 10 : le
				// brouillon est pré-rempli, pas figé).
				$vatRate = 0;
				if (!empty($staging->amount_ht) && $staging->amount_ttc !== null) {
					$vatRate = round((($staging->amount_ttc / $staging->amount_ht) - 1) * 100, 2);
				}
				$lineDesc = $staging->invoice_number !== null ? "Facture ".$staging->invoice_number : $staging->supplier_name;
				$lineResult = $newInvoice->addline($lineDesc, $staging->amount_ht, $vatRate, 0, 0, 1);

				if ($lineResult <= 0) {
					setEventMessages("Brouillon créé mais échec de l'ajout de la ligne : ".implode(' ; ', $newInvoice->errors), null, 'errors');
				}

				$result = $staging->markValidated($user, 'invoice_supplier', $newInvoiceId);
				if ($result > 0) {
					$staging->relinkEcmFiles($user, 'invoice_supplier', $newInvoiceId);
					setEventMessages($langs->trans("RecordSaved"), null);
				} else {
					setEventMessages(implode(' ; ', $staging->errors), null, 'errors');
				}
			}
		}
	}
} elseif ($action === 'reject' && !$alreadyProcessed) {
	if (!$user->rights->docclibarr->validate) {
		accessforbidden();
	}
	$reason = GETPOST('rejection_reason', 'restricthtml');
	$result = $staging->markRejected($user, $reason);
	if ($result > 0) {
		setEventMessages($langs->trans("RecordSaved"), null);
	} else {
		setEventMessages(implode(' ; ', $staging->errors), null, 'errors');
	}
}

// Recharge après une action éventuelle, pour afficher l'état à jour plutôt que celui
// lu en tout début de page.
if ($action !== '') {
	$staging->fetch($id);
	$alreadyProcessed = in_array($staging->match_status, array(
		FacturationElectroniqueStaging::STATUS_VALIDATED,
		FacturationElectroniqueStaging::STATUS_REJECTED,
	), true);
}

llxHeader('', $langs->trans("DocclibarrArea"));

print '<a href="'.dol_buildpath('/docclibarr/list.php', 1).'">'.$langs->trans("DocclibarrBackToList").'</a>';

print load_fiche_titre($staging->supplier_name.' - '.$staging->invoice_number, '', 'docclibarr@docclibarr');

if ($alreadyProcessed) {
	print '<div class="info">'.sprintf($langs->trans("DocclibarrAlreadyProcessed"), $langs->trans('DocclibarrMatchStatus'.ucfirst($staging->match_status === 'validated' ? 'Validated' : 'Rejected'))).'</div>';
}

$documentTypeLangKeys = array(
	'invoice' => 'DocclibarrDocumentTypeInvoice',
	'credit_note' => 'DocclibarrDocumentTypeCreditNote',
);
$isCreditNote = ($staging->document_type === 'credit_note');

print '<table class="border centpercent">';
print '<tr><td class="titlefield">'.$langs->trans("DocclibarrDocumentType").'</td><td>'.(isset($documentTypeLangKeys[$staging->document_type]) ? $langs->trans($documentTypeLangKeys[$staging->document_type]) : dol_escape_htmltag($staging->document_type)).'</td></tr>';
print '<tr><td>'.$langs->trans("DocclibarrSupplier").'</td><td>'.dol_escape_htmltag($staging->supplier_name).' ('.dol_escape_htmltag($staging->supplier_vat).')</td></tr>';
print '<tr><td>'.$langs->trans("DocclibarrInvoiceNumber").'</td><td>'.dol_escape_htmltag($staging->invoice_number).'</td></tr>';
print '<tr><td>'.$langs->trans("DocclibarrAmountTTC").'</td><td>'.($staging->amount_ttc !== null ? price($staging->amount_ttc) : '').' '.dol_escape_htmltag($staging->currency).'</td></tr>';
print '<tr><td>Communication</td><td>'.dol_escape_htmltag($staging->payment_ref_raw).'</td></tr>';
print '<tr><td>IBAN</td><td>'.dol_escape_htmltag($staging->payee_iban).'</td></tr>';
print '<tr><td>'.$langs->trans("DocclibarrOriginStatus").'</td><td>'.($staging->origin_verified ? $langs->trans("DocclibarrOriginVerified") : $langs->trans("DocclibarrOriginQuarantine")).'</td></tr>';
$cardMatchConfidenceLangKeys = array(
	'high' => 'DocclibarrMatchConfidenceHigh',
	'medium' => 'DocclibarrMatchConfidenceMedium',
	'suspect' => 'DocclibarrMatchConfidenceSuspect',
);
$cardConfidenceLabel = isset($cardMatchConfidenceLangKeys[$staging->match_confidence])
	? $langs->trans($cardMatchConfidenceLangKeys[$staging->match_confidence])
	: $langs->trans("DocclibarrMatchConfidenceNone");
print '<tr><td>'.$langs->trans("DocclibarrMatchConfidence").'</td><td>'.$cardConfidenceLabel.'</td></tr>';
print '</table>';

// Prévisualisation (voir SPEC.md section 11)
print '<div class="marginTopOnly">';
if (!empty($staging->pdf_ecm_file_id)) {
	print '<a class="button" href="'.dol_buildpath('/docclibarr/document.php', 1).'?id='.((int) $staging->pdf_ecm_file_id).'&staging_id='.$id.'" target="_blank">'.$langs->trans("DocclibarrDownloadPdf").'</a> ';
} else {
	print $langs->trans("DocclibarrNoPdf").' ';
}
if (!empty($staging->xml_ecm_file_id)) {
	print '<a class="button" href="'.dol_buildpath('/docclibarr/document.php', 1).'?id='.((int) $staging->xml_ecm_file_id).'&staging_id='.$id.'" target="_blank">'.$langs->trans("DocclibarrDownloadXml").'</a>';
}
print '</div>';

if (!$alreadyProcessed && $user->rights->docclibarr->validate) {
	$form = new Form($db);

	// Action 1 : valider la proposition telle quelle
	print '<div class="marginTopOnly"><h3>'.$langs->trans("DocclibarrProposedMatch").'</h3>';
	if (!empty($staging->matched_object_id)) {
		$proposed = new FactureFournisseur($db);
		if ($proposed->fetch($staging->matched_object_id) > 0) {
			print '<p>'.dol_escape_htmltag($proposed->ref).' ('.dol_escape_htmltag($proposed->ref_supplier).', '.price($proposed->total_ttc).')</p>';
		}
		print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'?id='.$id.'">';
		print '<input type="hidden" name="token" value="'.newToken().'">';
		print '<input type="hidden" name="action" value="validate_proposal">';
		print '<input type="submit" class="button" value="'.$langs->trans("DocclibarrValidateProposal").'">';
		print '</form>';
	} else {
		print '<p>'.$langs->trans("DocclibarrNoProposal").'</p>';
	}
	print '</div>';

	// Action 2 : rattacher manuellement à une facture fournisseur déjà existante dans
	// Dolibarr. Liste déroulante plutôt qu'un id à deviner/taper : d'abord les factures
	// du même tiers (même TVA fournisseur que l'extraction XML) si elles existent, sinon
	// les plus récentes toutes tiers confondus, pour ne jamais laisser le champ vide.
	print '<div class="marginTopOnly"><h3>'.$langs->trans("DocclibarrManualAttach").'</h3>';

	$candidateInvoices = array();
	if (!empty($staging->supplier_vat)) {
		$sqlCandidates = "SELECT f.rowid, f.ref, f.ref_supplier, f.total_ttc, s.nom as supplier_name";
		$sqlCandidates .= " FROM ".MAIN_DB_PREFIX."facture_fourn as f";
		$sqlCandidates .= " INNER JOIN ".MAIN_DB_PREFIX."societe as s ON s.rowid = f.fk_soc";
		$sqlCandidates .= " WHERE s.tva_intra = '".$db->escape($staging->supplier_vat)."'";
		$sqlCandidates .= " ORDER BY f.datef DESC";
		$sqlCandidates .= $db->plimit(20);
		$resqlCandidates = $db->query($sqlCandidates);
		if ($resqlCandidates) {
			while ($objCandidate = $db->fetch_object($resqlCandidates)) {
				$candidateInvoices[] = $objCandidate;
			}
		}
	}

	if (empty($candidateInvoices)) {
		// Aucune facture du même tiers (ou TVA non extraite) : repli sur les plus
		// récentes toutes tiers confondus, mieux que rien pour chercher visuellement.
		$sqlCandidates = "SELECT f.rowid, f.ref, f.ref_supplier, f.total_ttc, s.nom as supplier_name";
		$sqlCandidates .= " FROM ".MAIN_DB_PREFIX."facture_fourn as f";
		$sqlCandidates .= " INNER JOIN ".MAIN_DB_PREFIX."societe as s ON s.rowid = f.fk_soc";
		$sqlCandidates .= " ORDER BY f.datef DESC";
		$sqlCandidates .= $db->plimit(20);
		$resqlCandidates = $db->query($sqlCandidates);
		if ($resqlCandidates) {
			while ($objCandidate = $db->fetch_object($resqlCandidates)) {
				$candidateInvoices[] = $objCandidate;
			}
		}
	}

	print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'?id='.$id.'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="manual_attach">';
	print $langs->trans("DocclibarrSupplierInvoiceId").' ';

	if (empty($candidateInvoices)) {
		print '(aucune facture fournisseur trouvée dans Dolibarr)';
	} else {
		$invoiceOptions = array();
		foreach ($candidateInvoices as $candidate) {
			$invoiceOptions[$candidate->rowid] = $candidate->ref.' ('.$candidate->supplier_name.($candidate->ref_supplier !== null ? ', réf. fourn. '.$candidate->ref_supplier : '').', '.price($candidate->total_ttc).')';
		}
		print $form->selectarray('supplier_invoice_id', $invoiceOptions, '', 1, 0, 0, '', 0, 0, 0, '', 'minwidth300');
		print ' <input type="submit" class="button" value="'.$langs->trans("DocclibarrAttach").'">';
	}

	print '</form></div>';

	// Action 3 : créer un brouillon (n'a pas de sens pour une note de crédit, qui annule
	// une facture existante plutôt que d'en représenter une nouvelle, voir SPEC.md
	// section 6 : seul le rattachement manuel à la facture originale s'applique dans ce cas).
	if (!$isCreditNote) {
		print '<div class="marginTopOnly"><h3>'.$langs->trans("DocclibarrCreateDraft").'</h3>';
		print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'?id='.$id.'">';
		print '<input type="hidden" name="token" value="'.newToken().'">';
		print '<input type="hidden" name="action" value="create_draft">';
		print $langs->trans("DocclibarrThirdPartyId").' ';

		// Pré-sélection si un tiers existant correspond déjà à la TVA extraite du XML,
		// simple confort, l'utilisateur reste libre de choisir un autre tiers dans la liste.
		$preselectedThirdPartyId = 0;
		if (!empty($staging->supplier_vat)) {
			$sqlThirdParty = "SELECT rowid FROM ".MAIN_DB_PREFIX."societe WHERE tva_intra = '".$db->escape($staging->supplier_vat)."'";
			$resqlThirdParty = $db->query($sqlThirdParty);
			if ($resqlThirdParty && $db->num_rows($resqlThirdParty) > 0) {
				$objThirdParty = $db->fetch_object($resqlThirdParty);
				$preselectedThirdPartyId = (int) $objThirdParty->rowid;
			}
		}

		// Filtré aux tiers marqués fournisseurs (s.fournisseur=1), cohérent avec l'objet
		// créé (une facture fournisseur).
		print $form->select_company($preselectedThirdPartyId, 'third_party_id', 's.fournisseur=1', 1, 0, 0, array(), 0, 'minwidth300');

		print ' <input type="submit" class="button" value="'.$langs->trans("DocclibarrCreate").'">';
		print '</form></div>';
	}

	// Action 4 : rejeter avec motif
	print '<div class="marginTopOnly"><h3>'.$langs->trans("DocclibarrRejectAction").'</h3>';
	print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'?id='.$id.'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="reject">';
	print $langs->trans("DocclibarrRejectionReason").' <input type="text" name="rejection_reason" size="40">';
	print ' <input type="submit" class="button button-cancel" value="'.$langs->trans("DocclibarrReject").'">';
	print '</form></div>';
}

llxFooter();
