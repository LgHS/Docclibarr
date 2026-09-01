-- Copyright (C) 2026 iooner.io for Liège Hackerspace
-- Index et contraintes, chargés après llx_facturation_electronique_staging.sql par
-- init() (voir _load_tables, qui traite les fichiers .key.sql séparément et tolère
-- qu'ils échouent si l'index existe déjà, comportement standard Dolibarr).

-- Un même message ne doit jamais générer deux enregistrements (voir SPEC.md section 13).
ALTER TABLE llx_facturation_electronique_staging ADD UNIQUE INDEX uk_facturation_electronique_staging_message (email_message_id);

ALTER TABLE llx_facturation_electronique_staging ADD INDEX idx_facturation_electronique_staging_status (match_status);
ALTER TABLE llx_facturation_electronique_staging ADD INDEX idx_facturation_electronique_staging_supplier_vat (supplier_vat);
