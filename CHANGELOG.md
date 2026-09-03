# Changelog

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
