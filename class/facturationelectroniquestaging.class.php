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

require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';

/**
 * CRUD de la table de staging llx_facturation_electronique_staging (voir SPEC.md
 * section 9), suivant le pattern CommonObject habituel de Dolibarr. Chaque facture
 * entrante y transite avant (et après) validation humaine, indépendamment des tables
 * métier Dolibarr existantes (llx_facture_fourn).
 */
class FacturationElectroniqueStaging extends CommonObject
{
	public $element = 'facturationelectroniquestaging';
	public $table_element = 'facturation_electronique_staging';
	public $picto = 'docclibarr@docclibarr';

	/**
	 * Valeurs possibles de match_status (voir SPEC.md section 9).
	 */
	const STATUS_QUARANTINE = 'quarantine';
	const STATUS_PENDING = 'pending';
	const STATUS_AUTO_MATCHED = 'auto_matched';
	const STATUS_UNMATCHED = 'unmatched';
	const STATUS_VALIDATED = 'validated';
	const STATUS_REJECTED = 'rejected';

	public $fields = array(
		'rowid' => array('type' => 'integer', 'label' => 'TechnicalID', 'enabled' => 1, 'visible' => -2, 'notnull' => 1, 'index' => 1, 'position' => 1, 'comment' => 'Id'),
		'entity' => array('type' => 'integer', 'label' => 'Entity', 'enabled' => 1, 'visible' => 0, 'default' => '1', 'notnull' => 1, 'index' => 1, 'position' => 5),
		'email_message_id' => array('type' => 'varchar(255)', 'label' => 'EmailMessageId', 'enabled' => 1, 'visible' => -2, 'notnull' => 1, 'index' => 1, 'searchall' => 1, 'position' => 10),
		'email_received_at' => array('type' => 'datetime', 'label' => 'EmailReceivedAt', 'enabled' => 1, 'visible' => 1, 'notnull' => 1, 'position' => 15),
		'sender_domain' => array('type' => 'varchar(255)', 'label' => 'SenderDomain', 'enabled' => 1, 'visible' => -1, 'notnull' => 1, 'position' => 20),
		'origin_verified' => array('type' => 'integer', 'label' => 'OriginVerified', 'enabled' => 1, 'visible' => 1, 'notnull' => 1, 'default' => '0', 'position' => 25),
		'platform_name' => array('type' => 'varchar(64)', 'label' => 'PlatformName', 'enabled' => 1, 'visible' => -1, 'notnull' => 1, 'default' => 'doccle', 'position' => 30),
		'document_type' => array('type' => 'varchar(16)', 'label' => 'DocumentType', 'enabled' => 1, 'visible' => 1, 'notnull' => 1, 'default' => 'invoice', 'position' => 32),
		'eml_ecm_file_id' => array('type' => 'integer', 'label' => 'EmlEcmFileId', 'enabled' => 1, 'visible' => -2, 'position' => 35),
		'pdf_ecm_file_id' => array('type' => 'integer', 'label' => 'PdfEcmFileId', 'enabled' => 1, 'visible' => -2, 'position' => 40),
		'xml_ecm_file_id' => array('type' => 'integer', 'label' => 'XmlEcmFileId', 'enabled' => 1, 'visible' => -2, 'position' => 45),
		'supplier_vat' => array('type' => 'varchar(32)', 'label' => 'SupplierVat', 'enabled' => 1, 'visible' => 1, 'index' => 1, 'searchall' => 1, 'position' => 50),
		'supplier_name' => array('type' => 'varchar(255)', 'label' => 'SupplierName', 'enabled' => 1, 'visible' => 1, 'searchall' => 1, 'position' => 55),
		// Adresse postale du fournisseur (cac:PostalAddress du XML), ajoutée le 2026-09-07
		// pour pré-remplir la création du tiers Dolibarr depuis card.php.
		'supplier_address' => array('type' => 'varchar(255)', 'label' => 'SupplierAddress', 'enabled' => 1, 'visible' => -1, 'position' => 56),
		'supplier_zip' => array('type' => 'varchar(10)', 'label' => 'SupplierZip', 'enabled' => 1, 'visible' => -1, 'position' => 57),
		'supplier_town' => array('type' => 'varchar(128)', 'label' => 'SupplierTown', 'enabled' => 1, 'visible' => -1, 'position' => 58),
		'supplier_country_code' => array('type' => 'varchar(2)', 'label' => 'SupplierCountryCode', 'enabled' => 1, 'visible' => -1, 'position' => 59),
		'customer_vat' => array('type' => 'varchar(32)', 'label' => 'CustomerVat', 'enabled' => 1, 'visible' => -1, 'position' => 60),
		'invoice_number' => array('type' => 'varchar(64)', 'label' => 'InvoiceNumber', 'enabled' => 1, 'visible' => 1, 'searchall' => 1, 'position' => 65),
		'issue_date' => array('type' => 'date', 'label' => 'IssueDate', 'enabled' => 1, 'visible' => -1, 'position' => 70),
		'due_date' => array('type' => 'date', 'label' => 'DueDate', 'enabled' => 1, 'visible' => -1, 'position' => 75),
		'amount_ht' => array('type' => 'price', 'label' => 'AmountHT', 'enabled' => 1, 'visible' => -1, 'position' => 80),
		'amount_ttc' => array('type' => 'price', 'label' => 'AmountTTC', 'enabled' => 1, 'visible' => 1, 'position' => 85),
		'currency' => array('type' => 'varchar(8)', 'label' => 'Currency', 'enabled' => 1, 'visible' => -1, 'default' => 'EUR', 'position' => 90),
		'payment_ref_raw' => array('type' => 'varchar(64)', 'label' => 'PaymentRefRaw', 'enabled' => 1, 'visible' => -2, 'position' => 95),
		'payment_ref_normalized' => array('type' => 'varchar(32)', 'label' => 'PaymentRefNormalized', 'enabled' => 1, 'visible' => -1, 'index' => 1, 'position' => 100),
		'payee_iban' => array('type' => 'varchar(34)', 'label' => 'PayeeIban', 'enabled' => 1, 'visible' => -1, 'position' => 105),
		'match_status' => array('type' => 'varchar(16)', 'label' => 'MatchStatus', 'enabled' => 1, 'visible' => 1, 'notnull' => 1, 'default' => self::STATUS_PENDING, 'index' => 1, 'position' => 110),
		'match_confidence' => array('type' => 'varchar(8)', 'label' => 'MatchConfidence', 'enabled' => 1, 'visible' => 1, 'position' => 115),
		'matched_object_type' => array('type' => 'varchar(32)', 'label' => 'MatchedObjectType', 'enabled' => 1, 'visible' => -1, 'position' => 120),
		'matched_object_id' => array('type' => 'integer', 'label' => 'MatchedObjectId', 'enabled' => 1, 'visible' => -1, 'position' => 125),
		'validated_by' => array('type' => 'integer', 'label' => 'ValidatedBy', 'enabled' => 1, 'visible' => -1, 'position' => 130),
		'validated_at' => array('type' => 'datetime', 'label' => 'ValidatedAt', 'enabled' => 1, 'visible' => -1, 'position' => 135),
		'rejection_reason' => array('type' => 'varchar(255)', 'label' => 'RejectionReason', 'enabled' => 1, 'visible' => -1, 'position' => 140),
		'date_creation' => array('type' => 'datetime', 'label' => 'DateCreation', 'enabled' => 1, 'visible' => -2, 'notnull' => 1, 'position' => 500),
		'tms' => array('type' => 'timestamp', 'label' => 'DateModification', 'enabled' => 1, 'visible' => -2, 'notnull' => 1, 'position' => 501),
	);

	public $rowid;
	public $entity;
	public $email_message_id;
	public $email_received_at;
	public $sender_domain;
	public $origin_verified;
	public $platform_name;
	public $document_type;
	public $eml_ecm_file_id;
	public $pdf_ecm_file_id;
	public $xml_ecm_file_id;
	public $supplier_vat;
	public $supplier_name;
	public $supplier_address;
	public $supplier_zip;
	public $supplier_town;
	public $supplier_country_code;
	public $customer_vat;
	public $invoice_number;
	public $issue_date;
	public $due_date;
	public $amount_ht;
	public $amount_ttc;
	public $currency;
	public $payment_ref_raw;
	public $payment_ref_normalized;
	public $payee_iban;
	public $match_status;
	public $match_confidence;
	public $matched_object_type;
	public $matched_object_id;
	public $validated_by;
	public $validated_at;
	public $rejection_reason;
	public $date_creation;
	public $tms;

	/**
	 * @var string[] Journal texte du dernier appel à attachDocumentsToSupplierInvoiceFolder()
	 *                (via relinkEcmFiles()), pas une colonne de la table : diagnostic
	 *                affiché par le bouton de rattrapage d'admin/setup.php, voir la raison
	 *                d'être de ce champ sur cette méthode.
	 */
	public $lastAttachDocumentsDebug = array();

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * @param User $user Utilisateur créant l'enregistrement
	 * @param bool $notrigger Désactive les triggers
	 * @return int Id créé, <0 si erreur
	 */
	public function create(User $user, $notrigger = false)
	{
		$result = $this->createCommon($user, $notrigger);
		if ($result > 0) {
			// createCommon() (CommonObject) renseigne $this->id lui-même, mais pas $this->rowid
			// (propriété propre à cette classe, voir plus bas pourquoi fetch() doit faire
			// l'inverse). Gardés synchronisés dans les deux sens par précaution.
			$this->rowid = $this->id;
		}

		return $result;
	}

	/**
	 * SQL brut plutôt que fetchCommon() : même famille de méthodes CommonObject que
	 * fetchAllCommon(), qui s'est révélée peu fiable sur cette instance (voir fetchAll()
	 * ci-dessous). Les valeurs sont castées explicitement (int/null) pour que les
	 * comparaisons strictes (===, in_array(..., true)) fonctionnent correctement chez les
	 * appelants (voir document.php), une base de données renvoie tout en chaîne par défaut.
	 *
	 * @param int         $id  Id à charger
	 * @param string|null $ref Non utilisé (pas de référence métier sur cette table)
	 * @return int 1 si trouvé, 0 si absent, <0 si erreur
	 */
	public function fetch($id, $ref = null)
	{
		$id = (int) $id;
		if ($id <= 0) {
			return 0;
		}

		$fieldNames = array_keys($this->fields);

		$sql = "SELECT ".implode(', ', $fieldNames);
		$sql .= " FROM ".MAIN_DB_PREFIX.$this->table_element;
		$sql .= " WHERE rowid = ".$id;

		$resql = $this->db->query($sql);
		if (!$resql) {
			return -1;
		}
		if ($this->db->num_rows($resql) === 0) {
			return 0;
		}

		$obj = $this->db->fetch_object($resql);

		$intFields = array('rowid', 'entity', 'origin_verified', 'eml_ecm_file_id', 'pdf_ecm_file_id', 'xml_ecm_file_id', 'matched_object_id', 'validated_by');

		foreach ($fieldNames as $field) {
			$value = property_exists($obj, $field) ? $obj->$field : null;
			if ($value !== null && in_array($field, $intFields, true)) {
				$value = (int) $value;
			}
			$this->$field = $value;
		}

		// BUG RÉEL trouvé le 2026-09-07 : ce fetch() maison ne renseignait jamais $this->id
		// (seulement $this->rowid, propre à cette classe). Or update()/updateCommon()
		// (CommonObject) construit sa clause WHERE sur $this->id, jamais sur ->rowid. Sans
		// cette ligne, un update() après fetch() faisait un "UPDATE ... WHERE rowid = 0"
		// silencieux : 0 ligne affectée, mais SANS erreur SQL, donc update() rapportait quand
		// même un succès. C'est très probablement la cause du "Rejeter ne fonctionne pas"
		// jamais diagnostiqué (voir TODO.md), et du même symptôme sur "Valider cette
		// proposition" (markValidated) : le message de confirmation s'affiche, mais rien
		// n'est réellement écrit en base.
		$this->id = $this->rowid;

		return 1;
	}

	/**
	 * @param User $user Utilisateur modifiant l'enregistrement
	 * @param bool $notrigger Désactive les triggers
	 * @return int >0 si OK, <0 si erreur
	 */
	public function update(User $user, $notrigger = false)
	{
		return $this->updateCommon($user, $notrigger);
	}

	/**
	 * @param User $user Utilisateur supprimant l'enregistrement
	 * @param bool $notrigger Désactive les triggers
	 * @return int >0 si OK, <0 si erreur
	 */
	public function delete(User $user, $notrigger = false)
	{
		return $this->deleteCommon($user, $notrigger);
	}

	/**
	 * SQL brut plutôt que fetchAllCommon() : trouvé en conditions réelles que
	 * fetchAllCommon() provoque une erreur fatale non rattrapable même par
	 * catch (\Throwable), sur cette instance précise. Même approche déjà utilisée avec
	 * succès ailleurs dans le module (stagingRecordExists(), InvoiceMatcher::findCandidates()).
	 *
	 * @param string $sortorder  Sens de tri ('ASC' ou 'DESC')
	 * @param string $sortfield  Champ de tri (jamais une valeur utilisateur dans ce module,
	 *                            toujours un nom de colonne codé en dur côté appelant)
	 * @param int    $limit      Limite (0 = pas de limite)
	 * @param int    $offset     Offset
	 * @param array  $filter     Filtres d'égalité, ex: array('match_status' => 'pending'),
	 *                            ou d'appartenance si la valeur est un tableau, ex:
	 *                            array('match_status' => array('pending', 'unmatched'))
	 *                            (voir list.php, tableau "à traiter" filtré sur plusieurs
	 *                            statuts à la fois, ajouté le 2026-09-18)
	 * @param string $filtermode Mode de combinaison des filtres ('AND' ou 'OR')
	 * @return array<int, self>|int Tableau d'objets, ou <0 si erreur
	 */
	public function fetchAll($sortorder = '', $sortfield = '', $limit = 0, $offset = 0, array $filter = array(), $filtermode = 'AND')
	{
		$fieldNames = array_keys($this->fields);

		$sql = "SELECT ".implode(', ', $fieldNames);
		$sql .= " FROM ".MAIN_DB_PREFIX.$this->table_element;

		$where = array();
		foreach ($filter as $field => $value) {
			if (!in_array($field, $fieldNames, true)) {
				continue;
			}
			if (is_array($value)) {
				if (empty($value)) {
					continue;
				}
				$escapedValues = array_map(function ($v) {
					return "'".$this->db->escape($v)."'";
				}, $value);
				$where[] = $field." IN (".implode(', ', $escapedValues).")";
			} else {
				$where[] = $field." = '".$this->db->escape($value)."'";
			}
		}
		if (!empty($where)) {
			$sql .= " WHERE ".implode(' '.($filtermode === 'OR' ? 'OR' : 'AND').' ', $where);
		}

		if ($sortfield !== '' && in_array($sortfield, $fieldNames, true)) {
			$sql .= " ORDER BY ".$sortfield." ".($sortorder === 'ASC' ? 'ASC' : 'DESC');
		}

		if ($limit > 0) {
			$sql .= $this->db->plimit($limit, $offset);
		}

		$resql = $this->db->query($sql);
		if (!$resql) {
			return -1;
		}

		$records = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$record = new self($this->db);
			foreach ($fieldNames as $field) {
				$record->$field = property_exists($obj, $field) ? $obj->$field : null;
			}
			// Même correctif que fetch() : $this->id doit être renseigné pour que update()
			// (CommonObject) fonctionne sur un objet issu de cette liste, voir fetch() pour
			// le détail du bug que ça évite.
			$record->id = $record->rowid;
			$records[] = $record;
		}

		return $records;
	}

	/**
	 * Enregistre une validation humaine explicite (voir SPEC.md section 8 et 12 : jamais
	 * appelé automatiquement, quel que soit le niveau de confiance du matching proposé).
	 *
	 * @param User   $user             Utilisateur qui valide
	 * @param string $matchedObjectType Type de l'objet Dolibarr rattaché (ex: 'invoice_supplier')
	 * @param int    $matchedObjectId   Id de l'objet Dolibarr rattaché
	 * @return int >0 si OK, <0 si erreur
	 */
	public function markValidated(User $user, $matchedObjectType, $matchedObjectId)
	{
		$this->match_status = self::STATUS_VALIDATED;
		$this->matched_object_type = $matchedObjectType;
		$this->matched_object_id = $matchedObjectId;
		$this->validated_by = $user->id;
		$this->validated_at = dol_now();

		return $this->update($user);
	}

	/**
	 * Réinitialise cet enregistrement à l'état "non rapproché", pour permettre un nouveau
	 * traitement (rattachement manuel, nouveau brouillon...). Sert au cas où l'objet
	 * Dolibarr précédemment validé (une facture fournisseur, typiquement) a depuis été
	 * supprimé côté Dolibarr, laissant l'entrée coincée sur "déjà traité" sans aucune
	 * action possible alors que rien de valide ne pointe plus nulle part (cas réel
	 * rencontré le 2026-09-07 : brouillon supprimé après validation). Ne touche à rien
	 * d'autre dans Dolibarr, uniquement à cet enregistrement de staging.
	 *
	 * @param User $user
	 * @return int >0 si OK, <0 si erreur
	 */
	public function resetToUnmatched(User $user)
	{
		$this->match_status = self::STATUS_UNMATCHED;
		$this->matched_object_type = null;
		$this->matched_object_id = null;
		$this->validated_by = null;
		$this->validated_at = null;
		$this->rejection_reason = null;

		return $this->update($user);
	}

	/**
	 * Enregistre un rejet humain explicite, avec motif (voir SPEC.md section 11).
	 *
	 * @param User   $user   Utilisateur qui rejette
	 * @param string $reason Motif du rejet
	 * @return int >0 si OK, <0 si erreur
	 */
	public function markRejected(User $user, $reason)
	{
		$this->match_status = self::STATUS_REJECTED;
		$this->rejection_reason = $reason;
		$this->validated_by = $user->id;
		$this->validated_at = dol_now();

		return $this->update($user);
	}

	/**
	 * Recherche une facture fournisseur Dolibarr déjà existante avec la même référence
	 * fournisseur que celle qui serait utilisée par un brouillon créé depuis cet
	 * enregistrement (voir card.php, action 'create_draft'), et si possible le même tiers
	 * via la TVA. Sert à éviter de créer un doublon quand la facture a déjà été saisie à
	 * la main dans Dolibarr avant que Docclibarr ne la reçoive (cas réel rencontré le
	 * 2026-09-07), utilisée aussi bien avant validation (masquer le bouton "créer un
	 * brouillon", afficher un lien à la place) qu'à titre d'indice dans list.php.
	 *
	 * SQL brut plutôt qu'une méthode CommonObject, même raison que fetchAll() ci-dessus.
	 *
	 * @return int|null Id de la facture fournisseur existante, null si aucune
	 */
	public function findExistingSupplierInvoiceId()
	{
		$refSupplier = $this->payment_ref_raw !== null ? $this->payment_ref_raw : $this->invoice_number;
		if (empty($refSupplier)) {
			return null;
		}

		$sql = "SELECT f.rowid FROM ".MAIN_DB_PREFIX."facture_fourn as f";
		if (!empty($this->supplier_vat)) {
			$sql .= " INNER JOIN ".MAIN_DB_PREFIX."societe as s ON s.rowid = f.fk_soc";
		}
		$sql .= " WHERE f.ref_supplier = '".$this->db->escape($refSupplier)."'";
		if (!empty($this->supplier_vat)) {
			$sql .= " AND s.tva_intra = '".$this->db->escape($this->supplier_vat)."'";
		}
		$sql .= $this->db->plimit(1);

		$resql = $this->db->query($sql);
		if (!$resql || $this->db->num_rows($resql) === 0) {
			return null;
		}

		$obj = $this->db->fetch_object($resql);

		return (int) $obj->rowid;
	}

	/**
	 * Crée un brouillon de facture fournisseur à partir des données extraites du XML pour
	 * le tiers donné, marque cet enregistrement validé, et re-rattache les documents ECM.
	 * Logique partagée entre card.php (action 'create_draft', tiers choisi à la main) et
	 * list.php (action 'quick_process', tiers résolu automatiquement par
	 * resolveQuickAction() quand il n'y a aucune ambiguïté) : centralisée ici pour ne pas
	 * la dupliquer dans les deux fichiers.
	 *
	 * @param User $user
	 * @param int  $thirdPartyId Id du tiers Dolibarr, déjà résolu et vérifié par l'appelant
	 * @return int Id de la facture créée, <0 si erreur (voir $this->errors)
	 */
	public function createDraftInvoice(User $user, $thirdPartyId)
	{
		require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.facture.class.php';

		$newInvoice = new FactureFournisseur($this->db);
		$newInvoice->socid = $thirdPartyId;
		$newInvoice->ref_supplier = $this->payment_ref_raw !== null ? $this->payment_ref_raw : $this->invoice_number;
		$newInvoice->date = $this->issue_date !== null ? strtotime($this->issue_date) : dol_now();
		if ($this->due_date !== null) {
			$newInvoice->date_echeance = strtotime($this->due_date);
		}
		$newInvoice->label = "Facture ".$this->supplier_name." n°".$this->invoice_number;

		$newInvoiceId = $newInvoice->create($user);
		if ($newInvoiceId <= 0) {
			$this->errors = $newInvoice->errors;
			return -1;
		}

		// Une facture sans ligne n'a aucun montant : ajoute une ligne unique avec le HT
		// extrait et le taux de TVA déduit de HT/TTC (une seule ligne, une seule TVA,
		// laissé au brouillon à corriger à la main si la vraie facture a plusieurs lignes
		// ou plusieurs taux, voir SPEC.md section 10 : le brouillon est pré-rempli, pas
		// figé). L'échec d'ajout de ligne n'empêche pas la validation de l'enregistrement
		// de staging : la facture existe déjà côté Dolibarr, il ne faut pas la reproposer.
		$vatRate = 0;
		if (!empty($this->amount_ht) && $this->amount_ttc !== null) {
			$vatRate = round((($this->amount_ttc / $this->amount_ht) - 1) * 100, 2);
		}
		$lineDesc = $this->invoice_number !== null ? "Facture ".$this->invoice_number : $this->supplier_name;
		$lineResult = $newInvoice->addline($lineDesc, $this->amount_ht, $vatRate, 0, 0, 1);
		if ($lineResult <= 0) {
			$this->errors[] = "Brouillon créé mais échec de l'ajout de la ligne : ".implode(' ; ', $newInvoice->errors);
		}

		$result = $this->markValidated($user, 'invoice_supplier', $newInvoiceId);
		if ($result <= 0) {
			// markValidated() a déjà rempli $this->errors dans ce cas.
			return -1;
		}
		$this->relinkEcmFiles($user, 'invoice_supplier', $newInvoiceId);

		return $newInvoiceId;
	}

	/**
	 * Crée un avoir fournisseur autonome (FactureFournisseur::TYPE_CREDIT_NOTE) pour le
	 * tiers donné, marque cet enregistrement validé, et re-rattache les documents ECM.
	 * Ajouté le 2026-09-15 : jusqu'ici une note de crédit ne pouvait être QUE rattachée
	 * manuellement à une facture déjà existante (voir SPEC.md section 6), en supposant
	 * qu'elle corrige toujours une facture précise. Cas réel rencontré : une note de
	 * crédit peut être un crédit générique sur le compte fournisseur (ex: remboursement
	 * partiel après résiliation d'un contrat), sans facture source à désigner. Dolibarr
	 * accepte ce cas nativement (`fk_facture_source` reste vide/null), voir
	 * fourn/class/fournisseur.facture.class.php::create().
	 *
	 * @param User $user
	 * @param int  $thirdPartyId Id du tiers Dolibarr, déjà résolu et vérifié par l'appelant
	 * @return int Id de l'avoir créé, <0 si erreur (voir $this->errors)
	 */
	public function createCreditNote(User $user, $thirdPartyId)
	{
		require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.facture.class.php';

		$newCreditNote = new FactureFournisseur($this->db);
		// Le type doit être posé AVANT create() : addline() s'appuie dessus pour rendre les
		// montants négatifs elle-même (les montants passés à addline() restent positifs,
		// voir plus bas, jamais mis en négatif à la main ici).
		$newCreditNote->type = FactureFournisseur::TYPE_CREDIT_NOTE;
		$newCreditNote->socid = $thirdPartyId;
		$newCreditNote->ref_supplier = $this->payment_ref_raw !== null ? $this->payment_ref_raw : $this->invoice_number;
		$newCreditNote->date = $this->issue_date !== null ? strtotime($this->issue_date) : dol_now();
		$newCreditNote->label = "Note de crédit ".$this->supplier_name." n°".$this->invoice_number;

		$newCreditNoteId = $newCreditNote->create($user);
		if ($newCreditNoteId <= 0) {
			$this->errors = $newCreditNote->errors;
			return -1;
		}

		// Même logique de ligne unique que createDraftInvoice() (voir son commentaire) :
		// une seule ligne HT + taux de TVA déduit de HT/TTC, montant positif (Dolibarr le
		// rend négatif lui-même via TYPE_CREDIT_NOTE, voir plus haut).
		$vatRate = 0;
		if (!empty($this->amount_ht) && $this->amount_ttc !== null) {
			$vatRate = round((($this->amount_ttc / $this->amount_ht) - 1) * 100, 2);
		}
		$lineDesc = $this->invoice_number !== null ? "Note de crédit ".$this->invoice_number : $this->supplier_name;
		$lineResult = $newCreditNote->addline($lineDesc, $this->amount_ht, $vatRate, 0, 0, 1);
		if ($lineResult <= 0) {
			$this->errors[] = "Avoir créé mais échec de l'ajout de la ligne : ".implode(' ; ', $newCreditNote->errors);
		}

		$result = $this->markValidated($user, 'invoice_supplier', $newCreditNoteId);
		if ($result <= 0) {
			// markValidated() a déjà rempli $this->errors dans ce cas.
			return -1;
		}
		$this->relinkEcmFiles($user, 'invoice_supplier', $newCreditNoteId);

		return $newCreditNoteId;
	}

	/**
	 * Détermine ce qu'un clic unique "sûr" (le bouton vert de list.php) doit faire pour cet
	 * enregistrement, sans jamais rien appliquer lui-même (uniquement calculé). Le clic sur
	 * le bouton EST la confirmation humaine explicite exigée par SPEC.md section 8 et 12,
	 * mais seulement dans les cas où il n'y a strictement aucune ambiguïté à trancher :
	 * dans tous les autres cas, retourne null pour forcer un passage par la fiche détail
	 * (rattachement manuel, création de tiers, choix entre plusieurs candidats...).
	 *
	 * Ajoutée le 2026-09-07 en remplacement du seul cas "valider la proposition" que
	 * couvrait l'ancienne action 'quick_validate' de list.php.
	 *
	 * @return array{mode: string, third_party_id?: int}|null
	 *         ['mode' => 'validate_proposal'] si une proposition automatique existe déjà
	 *         (voir InvoiceMatcher) ; ['mode' => 'create_draft', 'third_party_id' => int]
	 *         si aucune proposition mais un unique tiers fournisseur correspond à la TVA
	 *         extraite du XML et qu'aucune facture avec cette référence n'existe déjà ;
	 *         null si aucun de ces deux cas ne s'applique.
	 */
	public function resolveQuickAction()
	{
		// Jamais de création automatique depuis une note de crédit, même raison que
		// card.php (annule une facture existante, ne se traite que par rattachement manuel).
		if ($this->document_type === 'credit_note') {
			return null;
		}

		if ($this->match_status === self::STATUS_AUTO_MATCHED && !empty($this->matched_object_id)) {
			return array('mode' => 'validate_proposal');
		}

		// Une facture avec cette référence existe déjà : ambigu de décider seul si c'est un
		// vrai doublon à rattacher ou une coïncidence, laissé à la fiche détail (qui affiche
		// déjà un lien vers cette facture, voir card.php et findExistingSupplierInvoiceId()).
		if ($this->findExistingSupplierInvoiceId() !== null) {
			return null;
		}

		if (empty($this->supplier_vat) || empty($this->supplier_name)) {
			return null;
		}

		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."societe WHERE tva_intra = '".$this->db->escape($this->supplier_vat)."' AND fournisseur = 1";
		$sql .= $this->db->plimit(2);
		$resql = $this->db->query($sql);
		if (!$resql || $this->db->num_rows($resql) !== 1) {
			// Aucun tiers, ou plusieurs (TVA en doublon côté Dolibarr) : pas assez sûr pour
			// un clic unique, laisser choisir depuis la fiche détail.
			return null;
		}

		$obj = $this->db->fetch_object($resql);

		return array('mode' => 'create_draft', 'third_party_id' => (int) $obj->rowid);
	}

	/**
	 * Re-rattache les documents ECM (PDF/XML) de cet enregistrement à l'objet Dolibarr
	 * validé (voir SPEC.md section 7 et 10 : le rattachement définitif ne se fait
	 * qu'après validation humaine, jamais avant). Méthode partagée entre card.php et
	 * list.php (actions rapides), plutôt que dupliquée dans chacun.
	 *
	 * @param User   $user
	 * @param string $objectType Ex: 'invoice_supplier'
	 * @param int    $objectId
	 */
	public function relinkEcmFiles(User $user, $objectType, $objectId)
	{
		require_once DOL_DOCUMENT_ROOT.'/ecm/class/ecmfiles.class.php';

		foreach (array($this->pdf_ecm_file_id, $this->xml_ecm_file_id) as $ecmFileId) {
			if (empty($ecmFileId)) {
				continue;
			}

			$ecmfile = new EcmFiles($this->db);
			if ($ecmfile->fetch($ecmFileId) <= 0) {
				continue;
			}

			$ecmfile->src_object_type = $objectType;
			$ecmfile->src_object_id = $objectId;
			$ecmfile->update($user);
		}

		// Ce qui précède ne fait que ré-étiqueter la fiche documentaire du module (les
		// fichiers restent physiquement dans docclibarr/YYYY/MM/, voir
		// IngestionWorker::storeEcmFile()) : ça ne suffit PAS à les faire apparaître dans
		// l'onglet "Documents joints" natif de la facture Dolibarr, qui s'appuie sur le
		// dossier documentaire propre à l'objet. Ajouté le 2026-09-07, à la demande
		// explicite de l'utilisateur : copie physique dans ce dossier en plus du
		// ré-étiquetage ci-dessus.
		if ($objectType === 'invoice_supplier') {
			$this->attachDocumentsToSupplierInvoiceFolder($user, $objectId);
		}
	}

	/**
	 * Copie le PDF et le XML reçus dans le dossier documentaire natif de la facture
	 * fournisseur Dolibarr (pas un déplacement : les fichiers d'origine du module restent
	 * en place, document.php continue de s'appuyer dessus pour la prévisualisation). Sert
	 * uniquement à ce que ces documents apparaissent dans l'onglet "Documents joints" de
	 * la fiche facture elle-même, pas seulement dans Docclibarr.
	 *
	 * AVERTISSEMENT : $conf->fournisseur->facture->dir_output est la convention Dolibarr
	 * standard pour ce module cœur, mais comme le reste des écritures de ce module (voir
	 * SPEC.md section 14), n'a pas pu être vérifiée contre une instance réelle. Échec
	 * traité comme non bloquant à dessein : la facture et les documents d'origine du
	 * module restent corrects même si cette copie échoue, seul l'onglet "Documents
	 * joints" natif de la facture reste alors vide.
	 *
	 * @param User $user
	 * @param int  $invoiceId
	 */
	protected function attachDocumentsToSupplierInvoiceFolder(User $user, $invoiceId)
	{
		global $conf;

		// Journal texte de ce qui s'est passé (ou pas), consultable via le bouton de
		// rattrapage d'admin/setup.php : la version précédente échouait totalement
		// silencieusement à chaque point de sortie anticipée, impossible à diagnostiquer
		// sans ça (rencontré en conditions réelles le 2026-09-07 : copie jamais faite,
		// aucune trace de pourquoi).
		$this->lastAttachDocumentsDebug = array();

		// Deux conventions Dolibarr possibles selon la version pour le dossier
		// documentaire d'un module : dir_output direct (mono-société), ou
		// multidir_output[entity] (convention multi-société plus récente). Essayées dans
		// l'ordre plutôt que de parier sur une seule, non vérifiée contre cette instance.
		$targetBaseDir = null;
		if (!empty($conf->fournisseur->facture->dir_output)) {
			$targetBaseDir = $conf->fournisseur->facture->dir_output;
		} elseif (!empty($conf->fournisseur->facture->multidir_output[$conf->entity])) {
			$targetBaseDir = $conf->fournisseur->facture->multidir_output[$conf->entity];
		}

		if ($targetBaseDir === null) {
			$this->lastAttachDocumentsDebug[] = "Dossier documentaire des factures fournisseur introuvable (\$conf->fournisseur->facture->dir_output et ->multidir_output[entity] tous deux vides)";
			return;
		}

		require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.facture.class.php';
		require_once DOL_DOCUMENT_ROOT.'/ecm/class/ecmfiles.class.php';
		require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';

		$invoice = new FactureFournisseur($this->db);
		if ($invoice->fetch($invoiceId) <= 0) {
			$this->lastAttachDocumentsDebug[] = "Facture fournisseur id ".$invoiceId." introuvable (fetch() a échoué)";
			return;
		}
		if (empty($invoice->ref)) {
			$this->lastAttachDocumentsDebug[] = "Facture fournisseur id ".$invoiceId." trouvée mais ->ref est vide";
			return;
		}

		// Sous-dossiers numériques (ex: "3/1/") en plus du sous-dossier par référence :
		// convention Dolibarr trouvée en conditions réelles le 2026-09-07 en comparant avec
		// un fichier uploadé à la main via Dolibarr lui-même (voir admin/setup.php, outil de
		// diagnostic). get_exdir() est la fonction cœur Dolibarr qui calcule ce découpage,
		// réutilisée telle quelle plutôt que recalculée à la main pour rester correcte quelle
		// que soit la version. Repli sur aucun sous-découpage si la fonction est absente.
		//
		// Le paramètre $modulepart doit valoir 'invoice_supplier' ICI (get_exdir() avec
		// 'facture_fourn' donne un résultat différent et faux, "SI2609-0006/" au lieu de
		// "3/1/", confirmé le 2026-09-07 via l'outil de diagnostic) : contre-intuitif vu que
		// src_object_type (juste en dessous) doit lui valoir 'facture_fourn', mais ce sont
		// deux usages Dolibarr distincts qui n'utilisent pas la même valeur de référence.
		$exdir = function_exists('get_exdir') ? get_exdir($invoice->id, 2, 0, 0, $invoice, 'invoice_supplier') : '';
		$this->lastAttachDocumentsDebug[] = "get_exdir() = '".$exdir."'";

		$targetRelativeDir = 'fournisseur/facture/'.$exdir.dol_sanitizeFileName($invoice->ref);
		$targetDir = $targetBaseDir.'/'.$exdir.dol_sanitizeFileName($invoice->ref);
		dol_mkdir($targetDir);
		if (!is_dir($targetDir)) {
			$this->lastAttachDocumentsDebug[] = "Échec de création du dossier ".$targetDir;
			return;
		}

		foreach (array($this->pdf_ecm_file_id, $this->xml_ecm_file_id) as $ecmFileId) {
			if (empty($ecmFileId)) {
				continue;
			}

			$sourceFile = new EcmFiles($this->db);
			if ($sourceFile->fetch($ecmFileId) <= 0) {
				$this->lastAttachDocumentsDebug[] = "Fiche ECM id ".$ecmFileId." introuvable";
				continue;
			}

			$sourcePath = DOL_DATA_ROOT.'/'.$sourceFile->filepath.'/'.$sourceFile->filename;
			if (!is_file($sourcePath)) {
				$this->lastAttachDocumentsDebug[] = "Fichier source introuvable sur le disque : ".$sourcePath;
				continue;
			}

			$targetPath = $targetDir.'/'.$sourceFile->filename;

			// Idempotence du fichier PHYSIQUE et de la fiche ECM vérifiées séparément l'une
			// de l'autre (bug réel trouvé le 2026-09-07) : la version précédente ne vérifiait
			// que le fichier disque, donc si la création de la fiche ECM avait échoué une
			// première fois, elle n'était jamais retentée ensuite alors que le fichier était
			// bien là. Résultat : fichier présent sur le disque au bon endroit, mais absent
			// de l'onglet "Documents joints" de la facture, qui s'appuie sur la fiche ECM.
			if (is_file($targetPath)) {
				$this->lastAttachDocumentsDebug[] = "Déjà copié sur le disque : ".$targetPath;
			} elseif (!copy($sourcePath, $targetPath)) {
				$this->lastAttachDocumentsDebug[] = "Échec de copy() vers ".$targetPath;
				continue;
			} else {
				$this->lastAttachDocumentsDebug[] = "Copié sur le disque : ".$targetPath;
			}

			$sqlExistingEcm = "SELECT rowid FROM ".MAIN_DB_PREFIX."ecm_files";
			$sqlExistingEcm .= " WHERE filepath = '".$this->db->escape($targetRelativeDir)."'";
			$sqlExistingEcm .= " AND filename = '".$this->db->escape($sourceFile->filename)."'";
			$resqlExistingEcm = $this->db->query($sqlExistingEcm);
			if ($resqlExistingEcm && $this->db->num_rows($resqlExistingEcm) > 0) {
				$this->lastAttachDocumentsDebug[] = "Fiche ECM déjà existante pour ".$targetPath;
				continue;
			}

			// gen_or_uploaded = 'uploaded' et src_object_type = 'facture_fourn' : valeurs
			// exactes observées sur une fiche créée par l'upload natif Dolibarr (voir
			// admin/setup.php, outil de diagnostic, 2026-09-07), pas une convention interne
			// à ce module comme le 'invoice_supplier' utilisé ailleurs (matched_object_type
			// de FacturationElectroniqueStaging) : ici il faut vraiment coller à ce que
			// Dolibarr attend lui-même pour que sa propre UI reconnaisse le fichier.
			$newEcmFile = new EcmFiles($this->db);
			$newEcmFile->filepath = $targetRelativeDir;
			$newEcmFile->filename = $sourceFile->filename;
			$newEcmFile->fullpath_orig = $targetPath;
			$newEcmFile->label = md5_file($targetPath);
			$newEcmFile->gen_or_uploaded = 'uploaded';
			$newEcmFile->description = '';
			$newEcmFile->keywords = '';
			$newEcmFile->entity = $conf->entity;
			$newEcmFile->src_object_type = 'facture_fourn';
			$newEcmFile->src_object_id = $invoiceId;
			$newEcmFileResult = $newEcmFile->create($user);
			if ($newEcmFileResult <= 0) {
				$this->lastAttachDocumentsDebug[] = "Fiche ECM NON créée pour ".$targetPath." : ".implode(' ; ', (array) $newEcmFile->errors);
			} else {
				$this->lastAttachDocumentsDebug[] = "Fiche ECM créée pour ".$targetPath;
			}
		}
	}
}
