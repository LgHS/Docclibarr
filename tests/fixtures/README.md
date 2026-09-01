# Fixtures de test

Les fixtures utilisées par les tests (`tests/OriginVerifierTest.php`, `tests/UblInvoiceParserTest.php`, `tests/MimeAttachmentExtractorTest.php`) sont pour l'instant **synthétiques**, construites à la main pour reproduire les particularités décrites dans SPEC.md section 6 et 14 (absence de `PaymentMeans` chez OGBAY, communication non formatée chez Proximus, format `+++.../..../.....+++` chez Piron), sans utiliser le contenu réel des trois emails déjà analysés.

À faire dès que possible : remplacer ces fixtures synthétiques par les trois `.eml` réels (anonymisés si nécessaire), comme demandé en SPEC.md section 14. Emplacement prévu une fois disponibles :

- `piron.eml`
- `ogbay.eml`
- `proximus.eml`

Le `.gitignore` du dépôt autorise déjà les `.eml` sous `tests/fixtures/` (voir l'exception `!tests/fixtures/**/*.eml`).
