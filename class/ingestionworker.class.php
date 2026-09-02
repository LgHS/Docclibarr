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

require_once DOL_DOCUMENT_ROOT.'/core/class/ecmfiles.class.php';
require_once __DIR__.'/gmailmailboxreader.class.php';
require_once __DIR__.'/originverifier.class.php';
require_once __DIR__.'/mimeattachmentextractor.class.php';
require_once __DIR__.'/ublinvoiceparser.class.php';
require_once __DIR__.'/facturationelectroniquestaging.class.php';

/**
 * Orchestre l'ingestion complète (voir SPEC.md section 3 et 4) : appelée par le cron de
 * secours (voir modDocclibarr.class.php, $this->cronjobs) ou par la notification Pub/Sub
 * quand elle sera implémentée. Enchaîne Gmail -> OriginVerifier -> MimeAttachmentExtractor
 * -> UblInvoiceParser -> stockage ECM + enregistrement de staging.
 *
 * AVERTISSEMENT : le stockage ECM (createEcmFile) suit le pattern Dolibarr standard
 * (EcmFiles::create()) mais n'a pas pu être vérifié contre une instance Dolibarr réelle
 * (DoliFius ne fait pas ce genre de stockage). À tester en priorité en couche 3 (voir
 * SPEC.md section 14) avant tout usage sur de vraies factures.
 *
 * Phase 1 uniquement : aucune logique de matching ici (voir SPEC.md section 15), chaque
 * message accepté est simplement mis en staging avec le statut "pending". Le moteur de
 * matching (phase 2) travaillera sur ces enregistrements séparément.
 */
class IngestionWorker
{
	/** @var DoliDB */
	protected $db;

	/** @var string[] */
	public $errors = array();

	/** @var int Nombre de messages traités (staging créé, quelle que soit l'issue) */
	public $processedCount = 0;

	/** @var int Nombre de messages ignorés car déjà en staging (email_message_id déjà vu) */
	public $skippedDuplicatesCount = 0;

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Point d'entrée appelé par le cron Dolibarr (voir modDocclibarr.class.php).
	 *
	 * @return int 1 si OK (même si certains messages individuels ont échoué, voir
	 *              $this->errors), 0 en cas d'échec bloquant (config manquante, etc.)
	 */
	public function run()
	{
		global $conf;

		$this->errors = array();
		$this->processedCount = 0;
		$this->skippedDuplicatesCount = 0;

		$mailboxAddress = $conf->global->DOCCLIBARR_MAILBOX_ADDRESS ?? '';
		$clientId = $conf->global->DOCCLIBARR_GMAIL_CLIENT_ID ?? '';
		$clientSecret = $conf->global->DOCCLIBARR_GMAIL_CLIENT_SECRET ?? '';
		$refreshToken = $conf->global->DOCCLIBARR_GMAIL_REFRESH_TOKEN ?? '';

		if ($mailboxAddress === '' || $clientId === '' || $clientSecret === '' || $refreshToken === '') {
			$this->errors[] = "Configuration Gmail incomplète (voir admin/setup.php)";
			return 0;
		}

		// Requête volontairement large (voir SPEC.md section 4) : elle ne sert qu'à
		// limiter le volume à traiter, jamais de contrôle de sécurité, la vérification
		// d'authenticité réelle se fait toujours après sur chaque message individuellement.
		$query = 'from:community@doccle.be has:attachment';

		$reader = new GmailMailboxReader($clientId, $clientSecret, $refreshToken);
		$rawMessages = $reader->fetchRawMessages($query);

		if (!empty($reader->errors)) {
			$this->errors = array_merge($this->errors, $reader->errors);
		}

		foreach ($rawMessages as $message) {
			$this->processMessage($message['message_id'], $message['raw']);
		}

		return 1;
	}

	/**
	 * Traite un message brut individuel : idempotence, vérification d'origine, extraction
	 * des pièces jointes, parsing XML, création de l'enregistrement de staging.
	 *
	 * @param string $messageId Id Gmail du message (utilisé comme email_message_id)
	 * @param string $rawEml    Contenu brut du .eml
	 */
	protected function processMessage($messageId, $rawEml)
	{
		global $user;

		// Idempotence (voir SPEC.md section 4 et 13) : un même message ne doit jamais
		// générer deux enregistrements, garanti aussi par la contrainte UNIQUE en base,
		// mais on vérifie ici d'abord pour éviter un aller-retour DB inutile en cas de
		// nouvelle notification sur un message déjà traité.
		if ($this->stagingRecordExists($messageId)) {
			$this->skippedDuplicatesCount++;
			return;
		}

		$staging = new FacturationElectroniqueStaging($this->db);
		$staging->email_message_id = $messageId;
		$staging->email_received_at = dol_now();
		$staging->platform_name = 'doccle';

		$fromDomain = $this->extractFromDomainForStorage($rawEml);
		$staging->sender_domain = $fromDomain !== null ? $fromDomain : '';

		// Conserve le .eml brut complet pour audit, y compris pour les messages rejetés
		// en quarantaine (voir SPEC.md section 4).
		$emlEcmFileId = $this->storeEcmFile($rawEml, $messageId.'.eml', 'message/rfc822');
		$staging->eml_ecm_file_id = $emlEcmFileId;

		$verifier = new OriginVerifier();
		$originVerified = $verifier->verify($rawEml, 'doccle.be');
		$staging->origin_verified = $originVerified ? 1 : 0;

		if (!$originVerified) {
			$staging->match_status = FacturationElectroniqueStaging::STATUS_QUARANTINE;
			$this->createStagingRecord($staging, $user);
			return;
		}

		$extractor = new MimeAttachmentExtractor();
		$attachments = $extractor->extractAttachments($rawEml);

		$xmlAttachment = null;
		$pdfAttachment = null;
		foreach ($attachments as $attachment) {
			if ($attachment['mimeType'] === 'application/xml' && $xmlAttachment === null) {
				$xmlAttachment = $attachment;
			} elseif ($attachment['mimeType'] === 'application/pdf' && $pdfAttachment === null) {
				$pdfAttachment = $attachment;
			}
		}

		if ($pdfAttachment !== null) {
			$staging->pdf_ecm_file_id = $this->storeEcmFile($pdfAttachment['content'], $messageId.'-piece.pdf', 'application/pdf');
		}

		if ($xmlAttachment === null) {
			// Message accepté sur l'origine mais sans XML exploitable : file manuelle
			// plutôt qu'échec silencieux (voir SPEC.md section 13).
			$staging->match_status = FacturationElectroniqueStaging::STATUS_PENDING;
			$this->createStagingRecord($staging, $user);
			return;
		}

		$staging->xml_ecm_file_id = $this->storeEcmFile($xmlAttachment['content'], $messageId.'.xml', 'application/xml');

		$parser = new UblInvoiceParser();
		$data = $parser->parse($xmlAttachment['content']);

		if ($data === null) {
			// XML non reconnu comme Peppol BIS Billing 3.0 conforme : file manuelle
			// plutôt que parsé à l'aveugle (voir SPEC.md section 2 et 6).
			$staging->match_status = FacturationElectroniqueStaging::STATUS_PENDING;
			$this->createStagingRecord($staging, $user);
			return;
		}

		$staging->supplier_vat = $data['supplier_vat'];
		$staging->supplier_name = $data['supplier_name'];
		$staging->customer_vat = $data['customer_vat'];
		$staging->invoice_number = $data['invoice_number'];
		$staging->issue_date = $data['issue_date'];
		$staging->due_date = $data['due_date'];
		$staging->amount_ht = $data['amount_ht'];
		$staging->amount_ttc = $data['amount_ttc'];
		$staging->currency = $data['currency'] !== null ? $data['currency'] : 'EUR';
		$staging->payment_ref_raw = $data['payment_ref_raw'];
		$staging->payment_ref_normalized = $data['payment_ref_normalized'];
		$staging->payee_iban = $data['payee_iban'];

		// La TVA client est stockée telle quelle : la comparaison avec le numéro de TVA
		// de l'entreprise (garde-fou anti-usurpation, voir SPEC.md section 6, 12 et 13)
		// est laissée au moteur de matching de la phase 2, absent en phase 1 (voir
		// SPEC.md section 15). Pas de logique de rapprochement ici volontairement.
		$staging->match_status = FacturationElectroniqueStaging::STATUS_PENDING;

		$this->createStagingRecord($staging, $user);
	}

	/**
	 * @param FacturationElectroniqueStaging $staging
	 * @param User|null $user Utilisateur du contexte cron (souvent un utilisateur système)
	 */
	protected function createStagingRecord(FacturationElectroniqueStaging $staging, $user)
	{
		$result = $staging->create($user);

		if ($result <= 0) {
			$this->errors[] = "Échec de création du staging pour le message ".$staging->email_message_id." : ".implode(' ; ', $staging->errors);
			return;
		}

		$this->processedCount++;
	}

	/**
	 * @param string $messageId
	 * @return bool
	 */
	protected function stagingRecordExists($messageId)
	{
		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."facturation_electronique_staging";
		$sql .= " WHERE email_message_id = '".$this->db->escape($messageId)."'";

		$resql = $this->db->query($sql);
		if (!$resql) {
			return false;
		}

		return $this->db->num_rows($resql) > 0;
	}

	/**
	 * @param string $rawEml
	 * @return string|null
	 */
	protected function extractFromDomainForStorage($rawEml)
	{
		$headerBlock = substr($rawEml, 0, strpos($rawEml, "\r\n\r\n") ?: strlen($rawEml));

		if (preg_match('/^from:.*@([a-z0-9.-]+\.[a-z]{2,})/mi', $headerBlock, $m)) {
			return strtolower(rtrim($m[1], '>'));
		}

		return null;
	}

	/**
	 * Stocke un contenu binaire comme document ECM Dolibarr, rattaché au sous-répertoire
	 * du module plutôt qu'à un objet métier définitif (voir SPEC.md section 7 : le
	 * rattachement à la facture fournisseur ne devient définitif qu'après validation
	 * humaine).
	 *
	 * @param string $content  Contenu binaire brut
	 * @param string $filename Nom de fichier stable (voir SPEC.md section 7 : jamais basé
	 *                          sur le nom de fichier d'origine de la pièce jointe)
	 * @param string $mimeType
	 * @return int|null Id de l'enregistrement ECM créé, null en cas d'échec
	 */
	protected function storeEcmFile($content, $filename, $mimeType)
	{
		global $conf;

		$relativeDir = 'docclibarr/'.dol_print_date(dol_now(), '%Y/%m');
		$fullDir = DOL_DATA_ROOT.'/'.$relativeDir;

		if (!dol_is_dir($fullDir)) {
			dol_mkdir($fullDir);
		}

		$filename = dol_sanitizeFileName($filename);
		$fullPath = $fullDir.'/'.$filename;

		$written = file_put_contents($fullPath, $content);
		if ($written === false) {
			$this->errors[] = "Échec d'écriture du fichier ".$filename;
			return null;
		}

		$ecmfile = new EcmFiles($this->db);
		$ecmfile->filepath = $relativeDir;
		$ecmfile->filename = $filename;
		$ecmfile->fullpath_orig = $fullPath;
		$ecmfile->label = md5_file($fullPath);
		$ecmfile->gen_or_uploaded = 'unknown';
		$ecmfile->description = '';
		$ecmfile->keywords = '';

		$result = $ecmfile->create($GLOBALS['user'] ?? null);
		if ($result <= 0) {
			$this->errors[] = "Échec d'indexation ECM du fichier ".$filename;
			return null;
		}

		return $ecmfile->id;
	}
}
