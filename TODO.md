# TODO

Suivi des points en attente, décidés mais pas encore implémentés (ou signalés mais pas
encore diagnostiqués). Contrairement au CHANGELOG.md, qui journalise ce qui a été fait,
ce fichier ne garde que ce qui reste à faire : un point traité doit être retiré d'ici et
noté dans le CHANGELOG à la place.

## Sécurité

- **Revoir les vérifications de droits sur les boutons d'admin/setup.php.** Constat du
  2026-09-18 : toute la page est déjà protégée en bloc par `!$user->admin` tout en haut
  (ligne 52), mais certaines actions (`backfill_documents`, `test_doc_folder`,
  `flush_test_data`) revérifient quand même `$user->admin` individuellement, alors que
  d'autres (`test_connection`, `test_ingestion`, `test_fetch_all`, `update`) ne le font
  pas. Pas une faille (la garde en haut de page couvre tout le monde), mais une
  incohérence : à trancher une bonne fois, soit retirer les vérifications redondantes
  partout, soit les ajouter partout pour la défense en profondeur.

## Fiabilité

- **Tester à fond la copie de documents sur la facture Dolibarr** (0.5.0 à 0.5.9,
  `attachDocumentsToSupplierInvoiceFolder()`) : le chemin exact (`get_exdir()` +
  `src_object_type = 'facture_fourn'`) n'a été confirmé que sur UN seul cas réel
  (SI2609-0006, facture payée, id 13). À vérifier sur plusieurs cas avant de faire
  confiance à l'automatisme : un brouillon fraîchement créé (ref encore `(PROV...)`,
  peut-être un découpage `get_exdir()` différent avant validation Dolibarr), une facture
  avec id à 1 ou 2 chiffres (le découpage "3/1/" observé pourrait se comporter autrement
  aux limites), et confirmer que le fichier apparaît bien dans "Fichiers joints" à chaque
  fois, pas seulement en base/sur le disque.
- **Cron réel non testé** : `DocclibarrIngestionWorker` n'a été exercé que via le bouton
  "Exécuter maintenant" d'admin/setup.php, jamais via un vrai déclenchement crontab
  système périodique.

## Fonctionnalités discutées, pas implémentées

- **Avertissement "déjà réglé selon le XML"** sur la fiche détail, pour les factures où
  le XML indique un montant à payer à 0 (prélèvement automatique, voir
  UblInvoiceParser). Nécessite une nouvelle colonne en base (staging).
- **Lignes détaillées du brouillon** plutôt qu'une seule ligne HT/TVA agrégée : les
  vraies factures Doccle ont souvent plusieurs `cac:InvoiceLine`/`cac:TaxSubtotal`
  (taux de TVA différents par ligne). Actuellement `createDraftInvoice()` ne récupère
  que les totaux globaux. Voir discussion du 2026-09-07 : identifié comme le plus gros
  point de friction restant pour l'encodage.
- **Rapprochement bancaire automatique** (proposition, jamais appliqué seul) une fois la
  facture validée, sur le modèle du moteur de matching facture déjà en place. Couvrirait
  tout le cycle réception → brouillon → paiement pointé, pas juste la première moitié.
- **Refonte visuelle** du dashboard (list.php/card.php) : fonctionnel mais pas soigné,
  discuté le 2026-09-07, idées pas encore posées.
