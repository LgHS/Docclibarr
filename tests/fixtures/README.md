# Fixtures de test

Les fixtures utilisées par les tests (`tests/OriginVerifierTest.php`, `tests/UblInvoiceParserTest.php`, `tests/MimeAttachmentExtractorTest.php`, `tests/InvoiceMatcherTest.php`) sont **synthétiques**, construites à la main pour reproduire des variations que le standard UBL/Peppol permet (champ `PaymentMeans` optionnel, format libre de la communication structurée, type de document `Invoice` ou `CreditNote`), aucune donnée réelle.

## Validation contre de vrais emails (`tests/fixtures/real/`)

Ce sous-dossier est prévu pour y déposer des `.eml`/`.xml` réels (`.gitignore` exclut tout `tests/fixtures/real/` sans exception, purement local, jamais publié). Utile pour vérifier que le parsing tient sur du vrai contenu, pas seulement sur des fixtures imaginées, voir la section Tests du `README.md` principal pour la méthode et les bugs déjà trouvés ainsi.
