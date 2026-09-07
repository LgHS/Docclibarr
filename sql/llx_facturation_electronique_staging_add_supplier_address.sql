-- Copyright (C) 2026 iooner.io for Liège Hackerspace
-- Migration ajoutée le 2026-09-07 : le module a déjà été activé sur l'instance réelle
-- avant l'ajout de ces colonnes, donc en plus de les ajouter au CREATE TABLE initial
-- (llx_facturation_electronique_staging.sql, pour qu'une toute nouvelle installation les
-- ait directement), ce fichier les ajoute aussi par ALTER TABLE pour une instance où la
-- table existe déjà sans elles. Chargé par _load_tables() comme le reste de sql/ : ne
-- s'applique réellement qu'en désactivant puis réactivant le module (voir README.md).
-- Sur une instance fraîchement installée, la colonne existe déjà via le CREATE TABLE :
-- l'ADD COLUMN échoue alors en doublon, mais Dolibarr tolère ce cas, comme pour les
-- index de llx_facturation_electronique_staging.key.sql.

ALTER TABLE llx_facturation_electronique_staging ADD COLUMN supplier_address VARCHAR(255) AFTER supplier_name;
ALTER TABLE llx_facturation_electronique_staging ADD COLUMN supplier_zip VARCHAR(10) AFTER supplier_address;
ALTER TABLE llx_facturation_electronique_staging ADD COLUMN supplier_town VARCHAR(128) AFTER supplier_zip;
ALTER TABLE llx_facturation_electronique_staging ADD COLUMN supplier_country_code VARCHAR(2) AFTER supplier_town;
