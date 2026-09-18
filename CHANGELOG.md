# Changelog

## 0.7.2 (2026-09-18)

- Le dropdown du tableau "Traité" ne propose plus les 3 statuts déjà couverts par le tableau "À traiter" (en attente, proposition automatique, non rapproché) : ils ont leur propre tableau juste au-dessus, les avoir aussi ici faisait doublon. "Tous" dans ce dropdown ne réintroduit plus ces 3 statuts non plus (limité à quarantaine/validé/rejeté).

## 0.7.1 (2026-09-18)

- **Liste réorganisée en deux tableaux** (demande explicite) : "À traiter" en premier (en attente, proposition automatique, non rapproché), toujours affiché en entier sans filtre puisque tout doit y passer de toute façon. "Traité" en dessous (validé, rejeté, en quarantaine), avec le filtre par statut existant, sur "Validé" par défaut au premier chargement de la page.
- `FacturationElectroniqueStaging::fetchAll()` accepte maintenant un tableau de valeurs pour un même champ de filtre (clause SQL `IN`), utilisé pour le tableau "À traiter".
- Refactor : en-tête de colonnes, ligne de tableau et tableau complet extraits en fonctions dans list.php (`docclibarr_print_list_header()`, `docclibarr_print_list_row()`, `docclibarr_print_list_table()`), pour ne pas dupliquer ce code entre les deux tableaux.

## 0.7.0 (2026-09-15)

- **Nouvelle action "Créer un avoir fournisseur"** sur la fiche détail d'une note de crédit, à côté de "Rattacher manuellement". Jusqu'ici une note de crédit ne pouvait être QUE rattachée manuellement à une facture déjà existante dans Dolibarr, en supposant qu'elle corrige toujours une facture précise. Cas réel rencontré : une note de crédit peut être un crédit générique sur le compte fournisseur (ex: remboursement partiel après résiliation d'un contrat), sans facture précise à corriger, situation où aucune action n'était possible jusqu'ici. Crée un avoir fournisseur Dolibarr autonome (`FactureFournisseur::TYPE_CREDIT_NOTE`), sans facture source obligatoire (Dolibarr l'accepte nativement), pré-rempli avec les infos du XML. Même dédoublonnage que pour un brouillon de facture.

## 0.6.3 (2026-09-15)

- Bug corrigé : le bouton "Créer le tiers fournisseur à partir du XML" était masqué pour une note de crédit, imbriqué par erreur dans la même condition que "Créer un brouillon" (qui elle n'a effectivement pas de sens pour une note de crédit). Sorti de cette condition : créer le tiers reste utile même pour une note de crédit, pour pouvoir ensuite rattacher manuellement une future facture/avoir de ce fournisseur.

## 0.6.2 (2026-09-07)

- Fiche détail : "Créer un brouillon de facture fournisseur" remonté avant "Rattacher manuellement" (cas le plus fréquent en premier).
- Fiche détail : prévisualisation du PDF directement à droite du tableau sur grand écran (au-delà de 1200px de large), en plus du lien de téléchargement existant. Repasse en une seule colonne sous 1200px.

## 0.6.1 (2026-09-07)

- Liste : le hover sur le statut "Rejeté" utilise maintenant l'info-bulle stylée de Dolibarr (`Form::textwithpicto()`, la même que sur les infobulles natives) plutôt que le tooltip brut du navigateur.

## 0.6.0 (2026-09-07)

- Nouveau bouton "Réinitialiser cette entrée (annuler le rejet)" sur la fiche détail d'une entrée rejetée : repasse l'entrée en "Non rapproché" pour la retraiter (rejet fait par erreur, motif qui ne tient plus...), même principe que le bouton de réinitialisation déjà existant pour une facture liée supprimée. Confirmation en conditions réelles ce même jour que la copie de documents (0.5.0 à 0.5.9) fonctionne correctement de bout en bout.
- Motif du rejet et auteur affichés sur la fiche détail d'une entrée rejetée (manquaient totalement jusqu'ici, seul le statut "rejeté" était visible). Même info en hover sur le statut dans la liste, pour ne pas avoir à ouvrir la fiche juste pour ça.

## 0.5.9 (2026-09-07)

- Correctif du correctif 0.5.8 : `get_exdir()` avec `$modulepart = 'facture_fourn'` donne un résultat différent (et faux, `SI2609-0006/`) de `$modulepart = 'invoice_supplier'` (`3/1/`, confirmé correspondre exactement à ce qu'utilise Dolibarr). Les deux valeurs de `$modulepart` ne sont PAS interchangeables sur cet appel, contre-intuitif vu que `src_object_type` (un champ différent) doit lui rester `facture_fourn`. Le chemin cible utilise maintenant `get_exdir(..., 'invoice_supplier')`.

## 0.5.8 (2026-09-07)

- **Cause trouvée** en comparant avec une fiche créée par un upload natif Dolibarr (`ecm_files` rowid 103, voir échange du 2026-09-07) : deux écarts avec ce que mon code faisait. (1) Dolibarr sous-découpe le dossier documentaire en sous-dossiers numériques (`fournisseur/facture/3/1/<réf>`, pas juste `fournisseur/facture/<réf>`) via sa fonction cœur `get_exdir()`, maintenant réutilisée directement plutôt que recalculée à la main. (2) `src_object_type` doit valoir `facture_fourn`, pas `invoice_supplier` (qui reste la convention interne du module ailleurs, ex. `matched_object_type`, mais pas ce que Dolibarr attend sur cette colonne précise).
- `gen_or_uploaded` passé à `uploaded` (valeur observée sur la fiche native), au lieu de `unknown`.
- Outil de diagnostic (admin/setup.php) étendu pour afficher le résultat de `get_exdir()` avec plusieurs valeurs de `$modulepart`, en confirmation.
- Les copies déjà faites sous l'ancien chemin plat (`fournisseur/facture/<réf>/`, sans sous-dossier) restent sur le disque sans être nettoyées automatiquement : inoffensif, juste un peu de place perdue. Utiliser le bouton de rattrapage ou "Recopier le PDF/XML" pour refaire une copie au bon endroit.

## 0.5.7 (2026-09-07)

- Diagnostic dossier documents étendu : le fichier uploadé à la main via Dolibarr (`SI2609-0006-test.pdf`) n'apparaissait pas dans le sous-dossier par référence que je scannais, seulement renommé avec le préfixe de la référence. Ajout d'un scan du dossier parent (`dir_output` sans sous-dossier) et d'une recherche `ecm_files` élargie (par dossier parent ou par préfixe de nom de fichier), pour enfin localiser où Dolibarr range réellement ce fichier sur cette instance.

## 0.5.6 (2026-09-07)

- Diagnostic dossier documents : le fichier uploadé à la main sur la facture via Dolibarr lui-même a bien été renommé et accepté (preuve que l'upload natif fonctionne), mais la requête précédente du diagnostic ne filtrait que sur `src_object_type`/`src_object_id`, exactement les champs remplis par mon code : elle ne montrait donc jamais la fiche ECM créée par Dolibarr pour un upload natif. Requête élargie : toutes les colonnes, filtrées sur le dossier (`filepath`) plutôt que sur ces deux champs, pour comparer les deux côte à côte.

## 0.5.5 (2026-09-07)

- Nouvel outil de diagnostic sur admin/setup.php ("Diagnostiquer le dossier documents"), sans besoin d'accès SSH/FTP : entre l'id d'une facture fournisseur, affiche les valeurs `$conf` résolues (dir_output/multidir_output), ce que `dol_dir_list()` trouve réellement dans chaque dossier candidat, et ce que la base connaît en `ecm_files` pour cette facture. Le fichier et sa fiche ECM sont confirmés présents et corrects (voir 0.5.3), mais l'onglet "Documents joints" de Dolibarr reste vide sur cette instance : ce diagnostic sert à voir enfin quel dossier Dolibarr utilise réellement, plutôt que de continuer à deviner à l'aveugle.

## 0.5.4 (2026-09-07)

- Liste : alerte visible (icône + texte orange) quand une entrée validée est rattachée à une facture qui n'existe plus, au lieu d'un simple "-" muet. Nouveau bouton ↺ dans la colonne d'actions pour réinitialiser directement depuis la liste, sans ouvrir la fiche (même vérification côté serveur que sur la fiche : refusé si la facture existe encore).

## 0.5.3 (2026-09-07)

- Confirmé en conditions réelles : le bouton "Réinitialiser cette entrée" (0.5.2) fonctionne.
- Bug trouvé et corrigé : `attachDocumentsToSupplierInvoiceFolder()` vérifiait uniquement si le fichier était déjà présent sur le disque avant de créer la fiche `ecm_files` correspondante. Si la création de cette fiche avait échoué une première fois (silencieusement), elle n'était plus jamais retentée alors que le fichier était bien copié au bon endroit : résultat, fichier physiquement présent mais absent de l'onglet "Documents joints" de la facture, qui s'appuie sur cette fiche. Les deux vérifications sont maintenant faites séparément.
- Le message "Facture Dolibarr introuvable" sur la fiche détail est maintenant affiché comme une vraie alerte visuelle (icône + fond orange), plus juste du texte brut noyé dans le tableau.

## 0.5.2 (2026-09-07)

- Nouveau bouton "Réinitialiser cette entrée" sur la fiche détail, affiché quand la facture liée a été supprimée côté Dolibarr : sans ça, une entrée validée dont la facture n'existe plus restait bloquée sur "déjà traité", sans aucune action possible. Repasse l'entrée en "Non rapproché" pour permettre un nouveau rattachement/brouillon, revérifié côté serveur que la facture est vraiment introuvable avant d'autoriser la réinitialisation.
- `attachDocumentsToSupplierInvoiceFolder()` (copie du PDF/XML sur la facture, 0.5.0) échouait totalement silencieusement à chaque point de sortie anticipée, impossible à diagnostiquer. Ajout d'un journal détaillé (`$lastAttachDocumentsDebug`) affiché par le bouton de rattrapage d'admin/setup.php (désormais détail par entrée, pas juste un compteur) et par un nouveau bouton "Recopier le PDF/XML sur cette facture" directement sur la fiche détail, pour rejouer et diagnostiquer une seule entrée sans passer par l'admin.
- Essaie maintenant aussi `$conf->fournisseur->facture->multidir_output[entity]` en repli si `dir_output` est vide, au cas où ce serait la bonne convention sur cette instance/version.

## 0.5.1 (2026-09-07)

- Bouton "Recopier les documents des entrées déjà validées" sur la page de configuration (admin/setup.php) : la copie du PDF/XML dans le dossier natif de la facture (0.5.0) ne s'applique qu'au moment de la validation, jamais rétroactivement. Ce bouton rejoue `relinkEcmFiles()` sur toutes les entrées déjà validées, sans risque de le relancer plusieurs fois (copie idempotente).

## 0.5.0 (2026-09-07)

- Le PDF et le XML reçus sont maintenant copiés dans le dossier documentaire natif de la facture fournisseur Dolibarr lors de la validation (`FacturationElectroniqueStaging::attachDocumentsToSupplierInvoiceFolder()`), en plus du ré-étiquetage déjà fait sur la fiche documentaire du module. Avant ce correctif, `relinkEcmFiles()` ne faisait que ré-étiqueter les fichiers sans les rendre visibles dans l'onglet "Documents joints" natif de la facture.
- Choix assumé : copie plutôt que déplacement, malgré le doublon physique que ça crée, pour ne jamais risquer de casser la prévisualisation du module (qui reste sur l'original) si Dolibarr renomme le dossier de la facture plus tard (passage du brouillon `(PROV...)` à son numéro définitif). Échec de copie traité comme non bloquant : n'empêche jamais la validation elle-même.

## 0.4.9 (2026-09-07)

- Bug corrigé : le message "Cette entrée a déjà été traitée" affichait littéralement `Valid&eacute;` au lieu de "Validé". Cause : passer une chaîne déjà traduite (donc avec ses accents) en paramètre d'un second appel à `trans()` fait ressortir des entités HTML doubles sur cette instance. Remplacé par deux clés de langue figées (`DocclibarrAlreadyProcessedValidated`/`Rejected`), qui évitent toute substitution imbriquée.
- Liste : nouveau bloc "Que veulent dire les statuts ?" en bas de page, replié par défaut, qui explique chaque statut (quarantaine, en attente, proposition automatique, non rapproché, validé, rejeté) et le cas "Suspect", avec quoi faire dans chaque cas.

## 0.4.8 (2026-09-07)

- **Bug de fond corrigé** : `FacturationElectroniqueStaging::fetch()` (SQL brut, voir son commentaire) ne renseignait jamais `$this->id`, seulement `$this->rowid`. Or `update()`/`updateCommon()` (CommonObject, hérité, jamais réécrit) construit sa clause `WHERE` sur `$this->id`. Résultat : après un `fetch()`, tout `update()` (valider une proposition, rejeter, marquer validé après création de brouillon...) faisait un `UPDATE ... WHERE rowid = 0` silencieux, 0 ligne affectée, sans erreur SQL, donc un message de succès s'affichait quand même sans que rien ne soit réellement écrit en base. Explique très probablement le vieux point du TODO "l'action Rejeter ne fonctionne pas" (jamais diagnostiqué jusqu'ici) et le symptôme signalé le 2026-09-07 sur "Valider cette proposition" qui ne tenait pas après rechargement de la fiche.
- Même correctif appliqué à `create()` (garde `rowid` synchronisé avec `id`, que `createCommon()` renseigne lui-même) et à `fetchAll()` (les objets retournés par la liste ont maintenant `id` renseigné aussi).

## 0.4.7 (2026-09-07)

- Police du tableau liste : 0.9em (0.95em en 0.4.6 encore un brin trop grand).

## 0.4.6 (2026-09-07)

- Police du tableau liste remontée à 0.95em (0.82em en 0.4.5 était trop agressif).

## 0.4.5 (2026-09-07)

- Liste : police réduite dans le tableau, boutons "Valider"/"Rejeter" remplacés par un ✓ vert et un ✕ rouge, plus compacts.
- Rejeter depuis la liste ouvre maintenant une petite fenêtre modale demandant le motif, au lieu d'un champ texte affiché en permanence sur chaque ligne.
- Le ✓ vert traite l'entrée en un clic quand c'est sûr : proposition automatique déjà trouvée (comme avant), ou aucune proposition mais un unique tiers Dolibarr correspond sans ambiguïté à la TVA extraite (crée alors le brouillon directement, comme depuis la fiche). Dans tous les autres cas (aucun tiers, plusieurs candidats, doublon détecté...) le ✓ n'apparaît pas, seul le bouton "fiche" reste pour traiter à la main.
- Refactor : la création de brouillon (`create_draft`) est maintenant centralisée dans `FacturationElectroniqueStaging::createDraftInvoice()`, partagée entre card.php et la nouvelle action rapide de list.php plutôt que dupliquée.

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
