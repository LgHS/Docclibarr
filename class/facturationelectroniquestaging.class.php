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
		return $this->createCommon($user, $notrigger);
	}

	/**
	 * @param int         $id  Id à charger
	 * @param string|null $ref Non utilisé (pas de référence métier sur cette table)
	 * @return int 1 si trouvé, 0 si absent, <0 si erreur
	 */
	public function fetch($id, $ref = null)
	{
		return $this->fetchCommon($id, $ref);
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
	 * @param string $sortorder  Sens de tri
	 * @param string $sortfield  Champ de tri
	 * @param int    $limit      Limite
	 * @param int    $offset     Offset
	 * @param array  $filter     Filtres, ex: array('match_status' => 'pending')
	 * @param string $filtermode Mode de combinaison des filtres
	 * @return array|int Tableau d'objets, ou <0 si erreur
	 */
	public function fetchAll($sortorder = '', $sortfield = '', $limit = 0, $offset = 0, array $filter = array(), $filtermode = 'AND')
	{
		return $this->fetchAllCommon($sortorder, $sortfield, $limit, $offset, $filter, $filtermode);
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
}
