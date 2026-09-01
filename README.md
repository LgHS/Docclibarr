# Docclibarr

**Module Dolibarr pour ingérer automatiquement les factures électroniques Doccle (Peppol / UBL) reçues par email, et proposer leur rattachement aux factures fournisseurs, toujours avec validation humaine avant toute écriture.**

Doccle ne propose aucune API pour récupérer les factures reçues par ce canal : le seul moyen de les obtenir est l'email que le réseau Peppol envoie automatiquement à réception d'une facture destinée à l'entreprise, avec deux pièces jointes, le PDF de la facture et son équivalent structuré en XML (UBL 2.1 / Peppol BIS Billing 3.0). Docclibarr automatise ce qui serait sinon un classement manuel de cette boîte mail : il lit la boîte, vérifie que chaque message provient authentiquement de Doccle (DKIM/DMARC), extrait les données du XML, et propose un rattachement à la facture fournisseur Dolibarr correspondante, sans jamais créer ni lier quoi que ce soit en base sans confirmation explicite d'un humain.

_Une API Doccle aurait réglé ça en un `GET /invoices` et ce module n'existerait pas. Faute de mieux (côté Doccle comme côté logiciels de compta abordables qui proposeraient nativement l'ingestion Peppol), Docclibarr sort l'artillerie lourde (vérification DKIM/DMARC en règle, parsing XPath namespace-aware, moteur de matching en cascade) pour faire à coups de regex et d'en-têtes email ce qu'un vrai point d'accès aurait fait en une requête. Doccle, j'aime votre solution, mais par pitié, une API..._

Docclibarr est un module à part entière, distinct du module d'**import bancaire** existant [DoliFius](https://github.com/LgHS/DoliFius) : il s'appuie sur les mêmes conventions de code, mais ne modifie pas et ne dépend pas de son code. Les deux modules répondent à la même contrainte de fond (un fournisseur externe sans API, seulement un flux à parser) appliquée à deux sources différentes : les extraits Belfius en CSV pour DoliFius, les factures Peppol/Doccle en email pour Docclibarr.

## Statut

🚧 **En développement, aucun code écrit à ce stade.** Ce dépôt contient pour l'instant la spécification technique complète (`SPEC.md`, non commité) et ce README. Voir la roadmap ci-dessous pour le détail des phases.

## Fonctionnement prévu

- Surveillance de la boîte Gmail dédiée (notification push Pub/Sub + cron de secours), identification idempotente par `Message-ID`.
- Vérification stricte de l'origine sur les en-têtes bruts du message : domaine `From` = `doccle.be`, DKIM `dkim=pass header.i=@doccle.be`, DMARC `dmarc=pass header.from=doccle.be`. Un message qui ne remplit pas ces trois conditions part en quarantaine, jamais traité automatiquement.
- Extraction des données de facture depuis le XML (numéro, dates, TVA fournisseur, montants HTVA/TTC, communication structurée, IBAN bénéficiaire) par parsing DOM/XPath conscient des namespaces.
- Proposition de rattachement à une facture fournisseur Dolibarr existante, scorée en cascade : communication structurée exacte, puis TVA + montant + fenêtre de date, puis aucune correspondance (traitement manuel).
- Dashboard de validation : chaque facture entrante est présentée avec sa proposition de rattachement, le PDF prévisualisable, et les actions valider / rattacher manuellement / créer un brouillon de facture / rejeter avec motif.

## Prérequis (prévisionnel)

- Dolibarr 22.x.
- PHP 7.2 ou supérieur.
- Un compte Gmail/Google Workspace dédié avec l'API Gmail activée (OAuth2 utilisateur classique, scope lecture seule). Un support IMAP pourrait être envisagé plus tard, en alternative ou en complément, pour couvrir une boîte mail hébergée ailleurs que sur Google Workspace.
- Composer, pour la librairie cliente Gmail officielle (`google/apiclient`). Contrairement à DoliFius qui n'a aucune dépendance externe, ce module en introduit une, rendue nécessaire par l'API Gmail.

## Roadmap

- [ ] **Phase 1** : Ingestion des emails, vérification d'origine, parsing XML, stockage en table de staging, dashboard en lecture seule.
- [ ] **Phase 2** : Moteur de matching en cascade, actions de validation humaine dans le dashboard, rattachement définitif aux factures fournisseurs.
- [ ] **Phase 3** : Alertes IBAN (écart avec l'historique fournisseur), notification en cas de facture en quarantaine, extension à d'autres plateformes de facturation électronique, et éventuellement un support IMAP en complément de l'API Gmail.

## Sécurité

Toute écriture comptable ou tout rattachement d'objet Dolibarr est systématiquement soumis à validation humaine explicite, sans exception liée au niveau de confiance du matching : c'est la protection principale contre une facture frauduleuse avec un IBAN falsifié. Chaque validation ou rejet est journalisé avec l'utilisateur Dolibarr et l'horodatage.
