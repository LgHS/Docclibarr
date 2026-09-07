# TODO

Suivi des points en attente, décidés mais pas encore implémentés (ou signalés mais pas
encore diagnostiqués). Contrairement au CHANGELOG.md, qui journalise ce qui a été fait,
ce fichier ne garde que ce qui reste à faire : un point traité doit être retiré d'ici et
noté dans le CHANGELOG à la place.

## Liste (list.php)

- **Gérer factures traitées / pas encore traitées.** Aujourd'hui la liste affiche tout
  en vrac (à traiter, en quarantaine, validé, rejeté) sans distinction visuelle forte ni
  filtre par défaut. Redemandé le 2026-09-07 ; déjà noté après la toute première session
  (2026-09-02/03) sans avoir été fait depuis. Pistes à trancher :
  - Masquer par défaut les entrées déjà validées/rejetées (garder le filtre par statut
    existant pour les faire réapparaître à la demande).
  - Et/ou séparer visuellement en deux blocs (à traiter / déjà traité) plutôt qu'un
    filtre qui cache une partie de la liste.

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
