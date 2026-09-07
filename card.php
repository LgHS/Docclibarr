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
 * proposition de rattachement du moteur de matching et expose les actions possibles,
 * toutes soumises à une confirmation humaine explicite (voir SPEC.md section 8 et 12,
 * rien n'est jamais appliqué automatiquement, y compris niveau 1) : valider la
 * proposition telle quelle, rattacher manuellement un autre objet, créer le tiers
 * fournisseur à partir du XML, créer un brouillon de facture fournisseur, ou rejeter
 * avec motif.
 *
 * AVERTISSEMENT : la création de facture fournisseur (FactureFournisseur::create()), la
 * création de tiers (Societe::create()), le re-rattachement des documents ECM à l'objet
 * validé et la copie du PDF/XML dans le dossier documentaire natif de la facture (voir
 * FacturationElectroniqueStaging::attachDocumentsToSupplierInvoiceFolder()) n'ont pas pu
 * être vérifiés contre une instance Dolibarr réelle, voir SPEC.md section 14 (couche 3).
 * À tester prioritairement avec des tiers et montants factices avant tout usage réel.
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

// Rempli seulement par action=recopy_documents ci-dessous. Capturé dans une variable à
// part plutôt que relu sur $staging après coup : lastAttachDocumentsDebug n'est pas une
// colonne de la table, le fetch() de rechargement plus bas l'aurait remis à vide.
$recopyDocumentsDebug = null;

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

	$duplicateInvoiceId = $staging->findExistingSupplierInvoiceId();

	if ($staging->document_type === 'credit_note') {
		// Défense en profondeur : le bouton est déjà masqué pour une note de crédit, mais
		// on refuse aussi l'action côté serveur si elle est soumise quand même.
		setEventMessages("Impossible de créer un brouillon de facture depuis une note de crédit", null, 'errors');
	} elseif ($duplicateInvoiceId !== null) {
		// Idem : le bouton est déjà masqué si une facture avec cette référence existe déjà
		// (voir plus bas, section d'affichage), mais revérifié ici côté serveur.
		setEventMessages($langs->trans("DocclibarrDraftAlreadyExistsError"), null, 'errors');
	} else {
		$thirdPartyId = (int) GETPOST('third_party_id', 'int');
		$thirdParty = new Societe($db);

		if ($thirdPartyId <= 0 || $thirdParty->fetch($thirdPartyId) <= 0) {
			setEventMessages($langs->trans("DocclibarrCreateDraftMissingThirdParty"), null, 'errors');
		} else {
			// Logique de création partagée avec list.php (action 'quick_process'), voir
			// FacturationElectroniqueStaging::createDraftInvoice().
			$newInvoiceId = $staging->createDraftInvoice($user, $thirdParty->id);
			if ($newInvoiceId <= 0) {
				setEventMessages(implode(' ; ', $staging->errors), null, 'errors');
			} else {
				setEventMessages($langs->trans("RecordSaved"), null);
			}
		}
	}
} elseif ($action === 'create_third_party' && !$alreadyProcessed) {
	if (!$user->rights->docclibarr->validate) {
		accessforbidden();
	}

	// Dédoublonnage : uniquement possible si une TVA a été extraite du XML (clé fiable,
	// voir la présélection plus bas qui utilise le même critère). Revérifié ici côté
	// serveur, pas seulement en masquant le bouton, au cas où un autre onglet aurait
	// entretemps créé le tiers.
	if (empty($staging->supplier_vat) || empty($staging->supplier_name)) {
		setEventMessages("TVA ou nom fournisseur manquant dans le XML, impossible de créer le tiers automatiquement", null, 'errors');
	} else {
		$sqlDuplicateCheck = "SELECT rowid FROM ".MAIN_DB_PREFIX."societe WHERE tva_intra = '".$db->escape($staging->supplier_vat)."'";
		$resqlDuplicateCheck = $db->query($sqlDuplicateCheck);
		if ($resqlDuplicateCheck && $db->num_rows($resqlDuplicateCheck) > 0) {
			setEventMessages("Un tiers avec cette TVA existe déjà, sélectionnez-le plutôt que d'en créer un nouveau", null, 'errors');
		} else {
			global $mysoc;

			$newThirdParty = new Societe($db);
			$newThirdParty->name = $staging->supplier_name;
			$newThirdParty->tva_intra = $staging->supplier_vat;
			$newThirdParty->address = $staging->supplier_address !== null ? $staging->supplier_address : '';
			$newThirdParty->zip = $staging->supplier_zip !== null ? $staging->supplier_zip : '';
			$newThirdParty->town = $staging->supplier_town !== null ? $staging->supplier_town : '';
			$newThirdParty->fournisseur = 1;
			$newThirdParty->client = 0;
			$newThirdParty->code_client = -1;
			$newThirdParty->code_fournisseur = -1;

			// Pays : d'abord cac:Country/cbc:IdentificationCode du XML (supplier_country_code)
			// s'il a pu être extrait, sinon déduit du préfixe ISO à 2 lettres de la TVA
			// (convention intracommunautaire standard, ex: "BE0123456789"), avec repli final
			// sur le pays de notre propre société si aucun des deux n'est exploitable ou
			// reconnu dans la table c_country.
			$vatCountryCode = null;
			if (preg_match('/^([A-Za-z]{2})/', $staging->supplier_vat, $vatCountryMatches)) {
				$vatCountryCode = strtoupper($vatCountryMatches[1]);
			}
			$countryCode = $staging->supplier_country_code !== null ? strtoupper($staging->supplier_country_code) : $vatCountryCode;
			$newThirdParty->country_id = $countryCode !== null ? dol_getIdFromCode($db, $countryCode, 'c_country', 'code', 'rowid') : 0;
			if (empty($newThirdParty->country_id)) {
				$newThirdParty->country_id = $mysoc->country_id;
			}

			$newThirdPartyId = $newThirdParty->create($user);

			if ($newThirdPartyId <= 0) {
				setEventMessages(implode(' ; ', $newThirdParty->errors), null, 'errors');
			} else {
				setEventMessages($langs->trans("RecordSaved"), null);
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
} elseif ($action === 'reset_broken_link') {
	// Volontairement PAS gardé par "!$alreadyProcessed" : c'est justement le cas d'une
	// entrée déjà validée qu'on traite ici (voir FacturationElectroniqueStaging::resetToUnmatched()).
	if (!$user->rights->docclibarr->validate) {
		accessforbidden();
	}
	if ($staging->match_status !== FacturationElectroniqueStaging::STATUS_VALIDATED || $staging->matched_object_type !== 'invoice_supplier' || empty($staging->matched_object_id)) {
		setEventMessages("Rien à réinitialiser pour cette entrée", null, 'errors');
	} else {
		// Revérifié côté serveur que l'objet Dolibarr lié n'existe vraiment plus, pas
		// seulement décidé côté affichage (voir plus bas) : jamais possible de
		// "déverrouiller" une entrée dont la facture liée existe toujours.
		$brokenLinkCheck = new FactureFournisseur($db);
		if ($brokenLinkCheck->fetch($staging->matched_object_id) > 0) {
			setEventMessages("La facture liée existe toujours dans Dolibarr, réinitialisation refusée", null, 'errors');
		} else {
			$result = $staging->resetToUnmatched($user);
			if ($result > 0) {
				setEventMessages($langs->trans("RecordSaved"), null);
			} else {
				setEventMessages(implode(' ; ', $staging->errors), null, 'errors');
			}
		}
	}
} elseif ($action === 'reset_rejected') {
	// Volontairement PAS gardé par "!$alreadyProcessed" : c'est justement le cas d'une
	// entrée déjà rejetée qu'on traite ici (rejet fait par erreur, à retraiter).
	if (!$user->rights->docclibarr->validate) {
		accessforbidden();
	}
	if ($staging->match_status !== FacturationElectroniqueStaging::STATUS_REJECTED) {
		setEventMessages("Rien à réinitialiser pour cette entrée", null, 'errors');
	} else {
		$result = $staging->resetToUnmatched($user);
		if ($result > 0) {
			setEventMessages($langs->trans("RecordSaved"), null);
		} else {
			setEventMessages(implode(' ; ', $staging->errors), null, 'errors');
		}
	}
} elseif ($action === 'recopy_documents') {
	// Volontairement PAS gardé par "!$alreadyProcessed" : sert justement à relancer la
	// copie sur une entrée déjà validée, sans revalider quoi que ce soit d'autre.
	if (!$user->rights->docclibarr->validate) {
		accessforbidden();
	}
	if ($staging->matched_object_type !== 'invoice_supplier' || empty($staging->matched_object_id)) {
		setEventMessages("Aucune facture liée à recopier pour cette entrée", null, 'errors');
	} else {
		$staging->relinkEcmFiles($user, $staging->matched_object_type, $staging->matched_object_id);
		$recopyDocumentsDebug = $staging->lastAttachDocumentsDebug;
		setEventMessages("Recopie relancée, voir le détail ci-dessous", null);
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

// Prévisualisation du PDF à droite sur grand écran (demande explicite du 2026-09-07),
// repasse en une seule colonne en dessous de 1200px : la prévisualisation prendrait plus
// de place que de valeur sur un petit écran, mieux vaut le lien de téléchargement plein
// écran dans ce cas (voir plus bas, resté inchangé). Style inline plutôt qu'un fichier CSS
// séparé à déclarer dans module_parts pour une seule page, même choix que list.php.
print '<style>
.docclibarr-card-layout { display: flex; gap: 24px; align-items: flex-start; }
.docclibarr-card-main { flex: 1 1 50%; min-width: 0; }
.docclibarr-card-preview { flex: 1 1 50%; position: sticky; top: 10px; }
.docclibarr-card-preview iframe { width: 100%; height: 85vh; border: 1px solid #ccc; }
@media (max-width: 1200px) {
	.docclibarr-card-layout { display: block; }
	.docclibarr-card-preview { display: none; }
}
</style>';

print '<div class="docclibarr-card-layout">';
print '<div class="docclibarr-card-main">';

if ($alreadyProcessed) {
	// Deux clés de langue distinctes plutôt qu'un %s substitué par trans() : passer une
	// chaîne déjà traduite (donc potentiellement déjà porteuse d'accents) en paramètre
	// d'un second appel à trans() faisait ressortir des entités HTML doubles (ex: "Validé"
	// affiché littéralement "Valid&eacute;"), bug réel rencontré le 2026-09-07 sur cette
	// instance juste après le correctif du bug précédent (trans() vs sprintf()). Deux clés
	// figées évitent complètement toute substitution imbriquée.
	$alreadyProcessedKey = ($staging->match_status === 'validated') ? 'DocclibarrAlreadyProcessedValidated' : 'DocclibarrAlreadyProcessedRejected';
	print '<div class="info">'.$langs->trans($alreadyProcessedKey).'</div>';

	// Motif du rejet et auteur : manquaient jusqu'ici, seul le statut "rejeté" était visible
	// sans jamais dire pourquoi ni par qui, obligeant à aller chercher ailleurs (aucun autre
	// endroit ne les affiche). User est une classe cœur Dolibarr toujours chargée avant tout
	// code de module (comme $user lui-même), pas besoin de require_once supplémentaire.
	if ($staging->match_status === FacturationElectroniqueStaging::STATUS_REJECTED) {
		if (!empty($staging->rejection_reason)) {
			print '<div class="info"><b>'.$langs->trans("DocclibarrRejectionReason").'</b> : '.dol_escape_htmltag($staging->rejection_reason).'</div>';
		}
		if (!empty($staging->validated_by)) {
			$rejectedByUser = new User($db);
			$rejectedByLabel = ($rejectedByUser->fetch($staging->validated_by) > 0 && method_exists($rejectedByUser, 'getFullName'))
				? $rejectedByUser->getFullName($langs)
				: '#'.$staging->validated_by;
			print '<div class="info"><b>'.$langs->trans("DocclibarrRejectedBy").'</b> : '.dol_escape_htmltag($rejectedByLabel).'</div>';
		}
	}

	// Réinitialiser un rejet : rejeté par erreur, motif qui ne tient plus, etc. Même
	// principe que le bouton "réinitialiser" pour une facture liée supprimée (voir plus
	// bas), mais ici pas besoin de revérifier un objet Dolibarr externe : un rejet ne
	// pointe jamais vers une facture, rien d'autre à valider avant de réinitialiser.
	if ($staging->match_status === FacturationElectroniqueStaging::STATUS_REJECTED && $user->rights->docclibarr->validate) {
		print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'?id='.$id.'" class="marginTopOnly">';
		print '<input type="hidden" name="token" value="'.newToken().'">';
		print '<input type="hidden" name="action" value="reset_rejected">';
		print '<input type="submit" class="button button-cancel smallpaddingimp" value="'.$langs->trans("DocclibarrResetRejected").'">';
		print '</form>';
	}
}

$documentTypeLangKeys = array(
	'invoice' => 'DocclibarrDocumentTypeInvoice',
	'credit_note' => 'DocclibarrDocumentTypeCreditNote',
);
$isCreditNote = ($staging->document_type === 'credit_note');

print '<table class="border centpercent">';
print '<tr><td class="titlefield">'.$langs->trans("DocclibarrDocumentType").'</td><td>'.(isset($documentTypeLangKeys[$staging->document_type]) ? $langs->trans($documentTypeLangKeys[$staging->document_type]) : dol_escape_htmltag($staging->document_type)).'</td></tr>';
print '<tr><td>'.$langs->trans("DocclibarrSupplier").'</td><td>'.dol_escape_htmltag($staging->supplier_name).' ('.dol_escape_htmltag($staging->supplier_vat).')</td></tr>';
$supplierAddressParts = array_filter(array($staging->supplier_address, $staging->supplier_zip, $staging->supplier_town, $staging->supplier_country_code));
print '<tr><td>Adresse</td><td>'.dol_escape_htmltag(implode(' ', $supplierAddressParts)).'</td></tr>';
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

// Facture Dolibarr liée (créée ou rattachée après validation) : sans ça, une fois l'entrée
// validée, plus aucun moyen depuis cette fiche de retrouver quelle facture réelle a été
// créée ou rattachée, seul le message générique "déjà traité" était affiché jusqu'ici.
if ($staging->matched_object_type === 'invoice_supplier' && !empty($staging->matched_object_id)) {
	$linkedInvoice = new FactureFournisseur($db);
	print '<tr><td>'.$langs->trans("DocclibarrLinkedInvoice").'</td><td>';
	if ($linkedInvoice->fetch($staging->matched_object_id) > 0) {
		$linkedInvoiceStatusLabel = method_exists($linkedInvoice, 'getLibStatut') ? $linkedInvoice->getLibStatut(1) : '';
		// Repli si ->ref ressort vide après fetch() (rencontré en conditions réelles sur
		// cette instance, cause exacte non identifiée) : jamais un lien sans texte.
		$linkedInvoiceRefDisplay = !empty($linkedInvoice->ref) ? $linkedInvoice->ref : (!empty($linkedInvoice->ref_supplier) ? $linkedInvoice->ref_supplier : '#'.$linkedInvoice->id);
		print '<a href="'.dol_buildpath('/fourn/facture/card.php', 1).'?id='.((int) $linkedInvoice->id).'">'.dol_escape_htmltag($linkedInvoiceRefDisplay).'</a>';
		if ($linkedInvoiceStatusLabel !== '') {
			print ' - '.$linkedInvoiceStatusLabel;
		}
		print ' ('.price($linkedInvoice->total_ttc).')';

		// Relance juste la copie PDF/XML vers le dossier documentaire de CETTE facture
		// précise (voir FacturationElectroniqueStaging::relinkEcmFiles()), sans revalider
		// quoi que ce soit d'autre. Ajouté le 2026-09-07 : équivalent du bouton de
		// rattrapage global d'admin/setup.php, mais ciblé sur une seule entrée, avec le
		// détail affiché directement ici plutôt que d'aller chercher dans l'admin.
		// Uniquement sur une entrée déjà validée (pas sur une simple proposition pas
		// encore confirmée, où matched_object_id est aussi renseigné).
		if ($staging->match_status === FacturationElectroniqueStaging::STATUS_VALIDATED && $user->rights->docclibarr->validate) {
			print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'?id='.$id.'" class="marginTopOnly">';
			print '<input type="hidden" name="token" value="'.newToken().'">';
			print '<input type="hidden" name="action" value="recopy_documents">';
			print '<input type="submit" class="button smallpaddingimp" value="'.$langs->trans("DocclibarrRecopyDocuments").'">';
			print '</form>';
		}

		if ($recopyDocumentsDebug !== null) {
			print '<pre style="white-space:pre-wrap;word-break:break-all;background:#f5f5f5;padding:8px;border:1px solid #ccc;margin-top:6px">';
			print dol_escape_htmltag(empty($recopyDocumentsDebug) ? '(rien à signaler, aucun fichier PDF/XML sur cette entrée)' : implode("\n", $recopyDocumentsDebug));
			print '</pre>';
		}
	} else {
		// Alerte visuelle (icône + fond orange), pas juste du texte brut : ce cas mérite de
		// sauter aux yeux plutôt que de se fondre dans le reste du tableau, demande explicite
		// du 2026-09-07.
		print '<div class="warning">'.img_warning().' '.$langs->trans("DocclibarrLinkedInvoiceNotFound", (string) ((int) $staging->matched_object_id)).'</div>';
		// La facture liée n'existe plus (supprimée côté Dolibarr après validation, cas réel
		// rencontré le 2026-09-07) : l'entrée était bloquée sur "déjà traité" sans plus
		// aucune action possible. Permet de la réinitialiser pour la retraiter.
		if ($staging->match_status === FacturationElectroniqueStaging::STATUS_VALIDATED && $user->rights->docclibarr->validate) {
			print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'?id='.$id.'" class="marginTopOnly">';
			print '<input type="hidden" name="token" value="'.newToken().'">';
			print '<input type="hidden" name="action" value="reset_broken_link">';
			print '<input type="submit" class="button button-cancel smallpaddingimp" value="'.$langs->trans("DocclibarrResetBrokenLink").'">';
			print '</form>';
		}
	}
	print '</td></tr>';
}
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
	print '<p class="opacitymedium">'.$langs->trans("DocclibarrProposedMatchHelp").'</p>';
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

	// Action 2 : créer un brouillon (n'a pas de sens pour une note de crédit, qui annule
	// une facture existante plutôt que d'en représenter une nouvelle, voir SPEC.md
	// section 6 : seul le rattachement manuel à la facture originale s'applique dans ce cas).
	// Remontée avant "rattacher manuellement" le 2026-09-07 (demande explicite) : c'est le
	// cas le plus fréquent (aucune facture existante à rattacher), autant le proposer en
	// premier plutôt qu'après une liste déroulante qui ne sert souvent à rien.
	if (!$isCreditNote) {
		print '<div class="marginTopOnly"><h3>'.$langs->trans("DocclibarrCreateDraft").'</h3>';
		print '<p class="opacitymedium">'.$langs->trans("DocclibarrCreateDraftHelp").'</p>';

		// Dédoublonnage : si une facture fournisseur avec cette référence (et ce tiers, si
		// la TVA est connue) existe déjà dans Dolibarr, ne pas proposer d'en créer une
		// autre, juste un lien vers l'existante (cas réel : facture déjà saisie à la main
		// avant que Docclibarr ne la reçoive, voir FacturationElectroniqueStaging::findExistingSupplierInvoiceId()).
		$duplicateInvoiceForDisplay = $staging->findExistingSupplierInvoiceId();

		if ($duplicateInvoiceForDisplay !== null) {
			$duplicateInvoice = new FactureFournisseur($db);
			if ($duplicateInvoice->fetch($duplicateInvoiceForDisplay) > 0) {
				$duplicateInvoiceStatusLabel = method_exists($duplicateInvoice, 'getLibStatut') ? $duplicateInvoice->getLibStatut(1) : '';
				// Repli si ->ref ressort vide après fetch() (rencontré en conditions réelles
				// sur cette instance, cause exacte non identifiée) : jamais un lien sans texte.
				$duplicateInvoiceRefDisplay = !empty($duplicateInvoice->ref) ? $duplicateInvoice->ref : (!empty($duplicateInvoice->ref_supplier) ? $duplicateInvoice->ref_supplier : '#'.$duplicateInvoice->id);
				$duplicateInvoiceLink = '<a href="'.dol_buildpath('/fourn/facture/card.php', 1).'?id='.((int) $duplicateInvoice->id).'">'.dol_escape_htmltag($duplicateInvoiceRefDisplay).'</a>';
				if ($duplicateInvoiceStatusLabel !== '') {
					$duplicateInvoiceLink .= ' - '.$duplicateInvoiceStatusLabel;
				}
				print '<p>'.$langs->trans("DocclibarrDraftAlreadyExists", $duplicateInvoiceLink).'</p>';
			}
		} else {
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
			print '</form>';

			// Créer directement le tiers fournisseur à partir des infos extraites du XML (nom +
			// TVA), pour éviter l'aller-retour manuel dans le module Tiers avant de pouvoir créer
			// le brouillon. Uniquement proposé si aucun tiers ne correspond déjà à cette TVA
			// (voir $preselectedThirdPartyId ci-dessus), pour ne jamais créer de doublon.
			if ($preselectedThirdPartyId <= 0 && !empty($staging->supplier_vat) && !empty($staging->supplier_name)) {
				print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'?id='.$id.'" class="marginTopOnly">';
				print '<input type="hidden" name="token" value="'.newToken().'">';
				print '<input type="hidden" name="action" value="create_third_party">';
				print '<input type="submit" class="button" value="'.$langs->trans("DocclibarrCreateThirdParty").'">';
				print '</form>';
			}
		}

		print '</div>';
	}

	// Action 3 : rattacher manuellement à une facture fournisseur déjà existante dans
	// Dolibarr. Liste déroulante plutôt qu'un id à deviner/taper : d'abord les factures
	// du même tiers (même TVA fournisseur que l'extraction XML) si elles existent, sinon
	// les plus récentes toutes tiers confondus, pour ne jamais laisser le champ vide.
	print '<div class="marginTopOnly"><h3>'.$langs->trans("DocclibarrManualAttach").'</h3>';
	print '<p class="opacitymedium">'.$langs->trans("DocclibarrManualAttachHelp").'</p>';

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

	// Action 4 : rejeter avec motif
	print '<div class="marginTopOnly"><h3>'.$langs->trans("DocclibarrRejectAction").'</h3>';
	print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'?id='.$id.'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="reject">';
	print $langs->trans("DocclibarrRejectionReason").' <input type="text" name="rejection_reason" size="40">';
	print ' <input type="submit" class="button button-cancel" value="'.$langs->trans("DocclibarrReject").'">';
	print '</form></div>';
}

print '</div>';

// Colonne de droite (masquée sous 1200px, voir le <style> plus haut) : le PDF directement
// dans un iframe, pas juste un lien à ouvrir dans un nouvel onglet. document.php sert déjà
// le fichier en "Content-Disposition: inline", donc le navigateur l'affiche tel quel dans
// l'iframe sans rien à changer côté serveur.
if (!empty($staging->pdf_ecm_file_id)) {
	print '<div class="docclibarr-card-preview">';
	print '<iframe src="'.dol_buildpath('/docclibarr/document.php', 1).'?id='.((int) $staging->pdf_ecm_file_id).'&staging_id='.$id.'" title="'.dol_escape_htmltag($langs->trans("DocclibarrDownloadPdf")).'"></iframe>';
	print '</div>';
}

print '</div>';

llxFooter();
