# Changelog

## 0.4.4 (2026-09-07)

- Vrai correctif du lien vide signalé en 0.4.3 (le repli sur `ref_supplier`/l'id n'y changeait rien) : la cause réelle était que `$langs->trans()` substitue lui-même les `%s` de la chaîne de langue avec ses propres paramètres (vides par défaut), donc l'appel `sprintf()` ensuite ne trouvait plus jamais de `%s` à remplacer et affichait un trou. Corrigé partout dans le module (card.php, admin/setup.php) en passant les valeurs directement à `trans()` plutôt que via `sprintf()` sur son résultat. Ça corrige au passage le même trou potentiel sur le message "déjà traité", sur "facture introuvable", et sur les messages de test de connexion Gmail.

## 0.4.3 (2026-09-07)

- Texte d'explication ajouté sous chaque titre d'action sur la fiche détail (valider la proposition / rattacher manuellement / créer un brouillon), pour clarifier à quoi sert chacune et quand l'utiliser.
- Bug trouvé en conditions réelles : le lien vers une facture Dolibarr déjà existante (dédoublonnage du brouillon, colonne liste, ligne "facture liée") pouvait s'afficher vide quand `FactureFournisseur::ref` ressort vide après `fetch()`. Repli sur `ref_supplier`, puis sur l'id, pour ne plus jamais afficher un lien sans texte.

## 0.4.2 (2026-09-07)

- Dédoublonnage sur "Créer un brouillon de facture fournisseur" : si une facture avec la même référence (et le même tiers, si la TVA est connue) existe déjà dans Dolibarr, le bouton n'est plus proposé, un lien vers la facture existante s'affiche à la place (fiche détail), revérifié aussi côté serveur.
- La liste affiche désormais ce même lien avant même la validation de l'entrée, pas seulement une fois validée : un doublon potentiel est visible directement depuis la liste, sans ouvrir la fiche.

## 0.4.1 (2026-09-07)

- Une fois une entrée validée (proposition validée, rattachement manuel, ou brouillon créé), la fiche détail et la liste affichent maintenant la facture fournisseur Dolibarr réellement créée/rattachée (référence, statut, montant, lien direct), au lieu du message générique "déjà traité" sans aucune trace de ce qui a été fait.

## 0.4.0 (2026-09-07)

- Nouveau bouton sur la fiche détail (card.php) pour créer directement le tiers fournisseur Dolibarr à partir des infos extraites du XML (nom, TVA, adresse), sans repasser par le module Tiers. Affiché uniquement si aucun tiers ne correspond déjà à la TVA extraite (dédoublonnage), revérifié côté serveur avant création.
- Extraction de l'adresse postale du fournisseur depuis le XML (rue, code postal, ville, code pays), en plus du nom et de la TVA déjà extraits. Affichée sur la fiche détail et utilisée pour pré-remplir le nouveau tiers (pays déduit du XML, ou à défaut du préfixe de la TVA).
- Migration de base ajoutée (`sql/llx_facturation_electronique_staging_add_supplier_address.sql`) pour les instances où le module est déjà activé : nécessite de désactiver puis réactiver le module pour que les nouvelles colonnes soient créées.

## Jusqu'à 0.3.9 (2026-09-02 / 2026-09-03)

Première session de développement et de mise au point contre une vraie instance Dolibarr (`compta-preprod.lghs.be`). Regroupé par thème plutôt que version par version, vu le nombre d'itérations de correctifs.

### Fonctionnalités

- Ingestion complète : boîte Gmail dédiée, vérification d'origine (domaine/DKIM/DMARC), extraction PDF/XML, parsing UBL/Peppol (factures et notes de crédit), stockage documentaire, moteur de matching en cascade contre les factures fournisseur existantes.
- Dashboard avec filtre par statut, fiche détail par facture, prévisualisation PDF/XML.
- Quatre actions de validation humaine, jamais rien d'automatique : valider la proposition, rattacher manuellement (liste déroulante de vraies factures), créer un brouillon de facture fournisseur (avec sélecteur de tiers, ligne pré-remplie, montant HT et TVA déduite), rejeter avec motif.
- Actions rapides "Valider"/"Rejeter" directement depuis la liste, sans ouvrir la fiche.
- Page de configuration avec test de connexion Gmail et test d'ingestion manuel, pour diagnostiquer sans dépendre des logs serveur.
- Bouton de purge des données de test (staging + documents ECM du module uniquement, jamais les vraies données Dolibarr).

### Bugs réels trouvés et corrigés en testant contre l'instance

- Chemin Dolibarr incorrect pour la classe ECM (`ecm/class/`, pas `core/class/`).
- Structure du module simplifiée : les pages du dashboard vivent maintenant à la racine du module, plus dans un sous-dossier redondant qui cassait les liens de menu et internes.
- `fetchAllCommon()`/`fetchCommon()` (Dolibarr) provoquaient une erreur fatale non rattrapable sur cette instance, remplacées par du SQL direct.
- `GETPOST(..., 'int')` ne renvoie pas toujours un vrai entier sur cette instance, cassait une vérification de sécurité stricte sur le téléchargement des PDF/XML.
- Stockage des documents rendu idempotent (un enregistrement déjà en base n'est plus retenté, évite un plantage sur contrainte unique).
- Refresh token Gmail : son échec silencieux est maintenant détecté et affiché clairement.

### Corrections issues de la validation sur des vraies factures

Validé contre 10+ factures/notes de crédit réelles de 8 fournisseurs différents (banque, distribution, énergie, télécom, eau, PME).

- TVA client absente dans le XML n'est plus traitée à tort comme une tentative d'usurpation.
- Support des notes de crédit UBL (`CreditNote`), pas prévu au départ.
- Repli sur le montant TTC brut quand une facture est déjà réglée par prélèvement automatique (le champ "montant à payer" tombe alors à 0 dans le XML).

### Connu, en attente de test ou de décision

- L'action "Rejeter" signalée comme ne fonctionnant pas, pas encore diagnostiquée.
- Le vrai déclenchement périodique du cron (crontab système), testé seulement via le bouton manuel jusqu'ici.
- Affichage d'un avertissement "déjà réglé selon le XML" sur la fiche, discuté mais pas encore implémenté (nécessite une nouvelle colonne en base).
- Masquer par défaut les entrées déjà validées/rejetées dans la liste, discuté mais pas encore implémenté.
