<?php
/* Copyright (C) 2026 iooner.io for Liège Hackerspace
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * Extrait les données structurées d'une facture UBL 2.1 / Peppol BIS Billing 3.0 (voir
 * SPEC.md section 6).
 *
 * Aucune dépendance à Dolibarr : cette classe travaille sur le contenu XML brut, ce qui
 * permet de la tester entièrement sur des fixtures statiques (voir
 * tests/UblInvoiceParserTest.php) sans jamais appeler l'API Gmail.
 *
 * Parsing en DOM/XPath conscient des namespaces, jamais par recherche de texte brut :
 * les échantillons réels analysés montrent que l'ordre et l'ensemble exact des
 * déclarations xmlns:* varient d'un émetteur à l'autre sans que la structure logique
 * change.
 */
class UblInvoiceParser
{
	const NS_INVOICE = 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2';
	const NS_CBC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';
	const NS_CAC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';

	/** CustomizationID attendu pour une facture Peppol BIS Billing 3.0 conforme EN16931 */
	const EXPECTED_CUSTOMIZATION_ID = 'urn:cen.eu:en16931:2017#compliant#urn:fdc:peppol.eu:2017:poacc:billing:3.0';

	/** @var string[] Raisons de l'échec, si parse() retourne null */
	public $errors = array();

	/**
	 * @param string $xmlContent Contenu XML brut de la pièce jointe
	 * @return array<string, mixed>|null Données extraites, ou null si le XML n'est pas
	 *                                    reconnu comme une facture Peppol BIS Billing 3.0
	 *                                    conforme (part alors en file de traitement manuel,
	 *                                    voir SPEC.md section 2 et 6, jamais parsé à l'aveugle)
	 */
	public function parse($xmlContent)
	{
		$this->errors = array();

		$previousSetting = libxml_use_internal_errors(true);
		$dom = new DOMDocument();
		$loaded = $dom->loadXML($xmlContent, LIBXML_NONET);
		libxml_use_internal_errors($previousSetting);

		if (!$loaded) {
			$this->errors[] = "XML invalide ou non parsable";
			return null;
		}

		$xpath = new DOMXPath($dom);
		$xpath->registerNamespace('inv', self::NS_INVOICE);
		$xpath->registerNamespace('cbc', self::NS_CBC);
		$xpath->registerNamespace('cac', self::NS_CAC);

		$customizationId = $this->queryString($xpath, '/inv:Invoice/cbc:CustomizationID');
		if ($customizationId !== self::EXPECTED_CUSTOMIZATION_ID) {
			$this->errors[] = "CustomizationID absent ou inattendu (\"".$customizationId."\"), XML non reconnu comme Peppol BIS Billing 3.0";
			return null;
		}

		$paymentRefRaw = $this->queryString($xpath, '/inv:Invoice/cac:PaymentMeans/cbc:PaymentID');

		return array(
			'invoice_number' => $this->queryString($xpath, '/inv:Invoice/cbc:ID'),
			'issue_date' => $this->queryString($xpath, '/inv:Invoice/cbc:IssueDate'),
			'due_date' => $this->queryString($xpath, '/inv:Invoice/cbc:DueDate'),
			'supplier_vat' => $this->queryString($xpath, '/inv:Invoice/cac:AccountingSupplierParty/cac:Party/cac:PartyTaxScheme/cbc:CompanyID'),
			'supplier_name' => $this->queryString($xpath, '/inv:Invoice/cac:AccountingSupplierParty/cac:Party/cac:PartyName/cbc:Name'),
			'customer_vat' => $this->queryString($xpath, '/inv:Invoice/cac:AccountingCustomerParty/cac:Party/cac:PartyTaxScheme/cbc:CompanyID'),
			'amount_ht' => $this->queryFloat($xpath, '/inv:Invoice/cac:LegalMonetaryTotal/cbc:TaxExclusiveAmount'),
			// PayableAmount, jamais TaxInclusiveAmount qui peut différer en cas d'acompte
			// déjà versé (voir SPEC.md section 6).
			'amount_ttc' => $this->queryFloat($xpath, '/inv:Invoice/cac:LegalMonetaryTotal/cbc:PayableAmount'),
			'currency' => $this->queryString($xpath, '/inv:Invoice/cbc:DocumentCurrencyCode'),
			'payment_ref_raw' => $paymentRefRaw,
			'payment_ref_normalized' => $paymentRefRaw !== null ? $this->normalizePaymentRef($paymentRefRaw) : null,
			'payee_iban' => $this->queryString($xpath, '/inv:Invoice/cac:PaymentMeans/cac:PayeeFinancialAccount/cbc:ID'),
		);
	}

	/**
	 * Normalise une communication structurée en ne gardant que les chiffres, quel que soit
	 * son format d'origine (avec séparateurs façon +++xxx/xxxx/xxxxx+++, ou en une seule
	 * suite de chiffres sans séparateur selon l'émetteur, voir SPEC.md section 6). Toujours
	 * normaliser ainsi avant toute comparaison.
	 *
	 * @param string $rawPaymentRef
	 * @return string
	 */
	public function normalizePaymentRef($rawPaymentRef)
	{
		return preg_replace('/[^0-9]/', '', $rawPaymentRef);
	}

	/**
	 * @param DOMXPath $xpath
	 * @param string $query
	 * @return string|null Texte du premier nœud trouvé, null si absent (ne jamais confondre
	 *                       avec une chaîne vide : un champ présent mais vide reste une
	 *                       chaîne vide, un champ absent est null)
	 */
	protected function queryString(DOMXPath $xpath, $query)
	{
		$nodes = $xpath->query($query);
		if ($nodes === false || $nodes->length === 0) {
			return null;
		}

		return trim($nodes->item(0)->textContent);
	}

	/**
	 * @param DOMXPath $xpath
	 * @param string $query
	 * @return float|null
	 */
	protected function queryFloat(DOMXPath $xpath, $query)
	{
		$value = $this->queryString($xpath, $query);

		return $value !== null ? (float) $value : null;
	}
}
