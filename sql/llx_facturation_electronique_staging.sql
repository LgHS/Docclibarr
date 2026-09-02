-- Copyright (C) 2026 iooner.io for Liège Hackerspace
-- Ce fichier est chargé automatiquement par init() du module (voir _load_tables)
-- au format attendu par Dolibarr : un seul CREATE TABLE, moteur InnoDB explicite,
-- pas de point-virgule final sur certaines lignes selon le parseur Dolibarr, on
-- garde ici une syntaxe SQL standard, Dolibarr sait la digérer.

CREATE TABLE llx_facturation_electronique_staging (
	rowid                   INTEGER AUTO_INCREMENT PRIMARY KEY,
	entity                  INTEGER DEFAULT 1 NOT NULL,
	email_message_id        VARCHAR(255) NOT NULL,
	email_received_at       DATETIME NOT NULL,
	sender_domain           VARCHAR(255) NOT NULL,
	origin_verified         TINYINT DEFAULT 0 NOT NULL,
	platform_name           VARCHAR(64) DEFAULT 'doccle' NOT NULL,
	document_type           VARCHAR(16) DEFAULT 'invoice' NOT NULL,
	eml_ecm_file_id         INTEGER,
	pdf_ecm_file_id         INTEGER,
	xml_ecm_file_id         INTEGER,
	supplier_vat            VARCHAR(32),
	supplier_name           VARCHAR(255),
	customer_vat            VARCHAR(32),
	invoice_number          VARCHAR(64),
	issue_date              DATE,
	due_date                DATE,
	amount_ht               DECIMAL(18,2),
	amount_ttc               DECIMAL(18,2),
	currency                 VARCHAR(8) DEFAULT 'EUR',
	payment_ref_raw          VARCHAR(64),
	payment_ref_normalized   VARCHAR(32),
	payee_iban               VARCHAR(34),
	match_status              VARCHAR(16) DEFAULT 'pending' NOT NULL,
	match_confidence          VARCHAR(8),
	matched_object_type       VARCHAR(32),
	matched_object_id         INTEGER,
	validated_by              INTEGER,
	validated_at               DATETIME,
	rejection_reason           VARCHAR(255),
	date_creation               DATETIME NOT NULL,
	tms                          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
