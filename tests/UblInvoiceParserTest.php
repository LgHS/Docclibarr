<?php
/* Copyright (C) 2026 iooner.io for Liège Hackerspace
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

use PHPUnit\Framework\TestCase;

/**
 * Fixtures entièrement fictives (noms, TVA, IBAN, communications structurées), voir
 * tests/fixtures/README.md : à remplacer par les 3 emails réels anonymisés (Fournisseur1,
 * Fournisseur2 BV, Fournisseur3) dès que possible. Seule la forme des données reproduit les
 * particularités décrites en SPEC.md section 6, aucune valeur réelle n'est reprise ici.
 */
class UblInvoiceParserTest extends TestCase
{
	const NS_INVOICE = 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2';
	const NS_CREDIT_NOTE = 'urn:oasis:names:specification:ubl:schema:xsd:CreditNote-2';
	const NS_CBC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';
	const NS_CAC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';
	const EXPECTED_CUSTOMIZATION_ID = 'urn:cen.eu:en16931:2017#compliant#urn:fdc:peppol.eu:2017:poacc:billing:3.0';

	/**
	 * Style "Fournisseur1" : communication structurée au format +++.../..../.....+++,
	 * EndpointID en schème 9925 (jamais utilisé comme clé de matching). Toutes les
	 * valeurs (TVA, IBAN, communication) sont fictives.
	 */
	protected function fournisseur1StyleXml()
	{
		return '<?xml version="1.0" encoding="UTF-8"?>'
			.'<Invoice xmlns="'.self::NS_INVOICE.'" xmlns:cbc="'.self::NS_CBC.'" xmlns:cac="'.self::NS_CAC.'">'
			.'<cbc:CustomizationID>'.self::EXPECTED_CUSTOMIZATION_ID.'</cbc:CustomizationID>'
			.'<cbc:ID>FA2026-0421</cbc:ID>'
			.'<cbc:IssueDate>2026-08-15</cbc:IssueDate>'
			.'<cbc:DueDate>2026-09-14</cbc:DueDate>'
			.'<cbc:DocumentCurrencyCode>EUR</cbc:DocumentCurrencyCode>'
			.'<cac:AccountingSupplierParty><cac:Party>'
			.'<cbc:EndpointID schemeID="9925">BE0123456789</cbc:EndpointID>'
			.'<cac:PartyName><cbc:Name>Fournisseur1 SA</cbc:Name></cac:PartyName>'
			.'<cac:PartyTaxScheme><cbc:CompanyID>BE0123456789</cbc:CompanyID></cac:PartyTaxScheme>'
			.'</cac:Party></cac:AccountingSupplierParty>'
			.'<cac:AccountingCustomerParty><cac:Party>'
			.'<cac:PartyTaxScheme><cbc:CompanyID>BE0987654321</cbc:CompanyID></cac:PartyTaxScheme>'
			.'</cac:Party></cac:AccountingCustomerParty>'
			.'<cac:PaymentMeans>'
			.'<cbc:PaymentID>+++111/2222/33333+++</cbc:PaymentID>'
			.'<cac:PayeeFinancialAccount><cbc:ID>BE00123412341234</cbc:ID></cac:PayeeFinancialAccount>'
			.'</cac:PaymentMeans>'
			.'<cac:LegalMonetaryTotal>'
			.'<cbc:TaxExclusiveAmount>100.00</cbc:TaxExclusiveAmount>'
			.'<cbc:TaxInclusiveAmount>121.00</cbc:TaxInclusiveAmount>'
			.'<cbc:PayableAmount>121.00</cbc:PayableAmount>'
			.'</cac:LegalMonetaryTotal>'
			.'</Invoice>';
	}

	/**
	 * Style "Fournisseur2 BV" : aucun bloc PaymentMeans du tout, oblige le fallback niveau 2
	 * du moteur de matching (voir SPEC.md section 8). Valeurs fictives.
	 */
	protected function fournisseur2StyleXml()
	{
		return '<?xml version="1.0" encoding="UTF-8"?>'
			.'<Invoice xmlns="'.self::NS_INVOICE.'" xmlns:cbc="'.self::NS_CBC.'" xmlns:cac="'.self::NS_CAC.'">'
			.'<cbc:CustomizationID>'.self::EXPECTED_CUSTOMIZATION_ID.'</cbc:CustomizationID>'
			.'<cbc:ID>2026-INV-777</cbc:ID>'
			.'<cbc:IssueDate>2026-08-10</cbc:IssueDate>'
			.'<cbc:DueDate>2026-09-09</cbc:DueDate>'
			.'<cbc:DocumentCurrencyCode>EUR</cbc:DocumentCurrencyCode>'
			.'<cac:AccountingSupplierParty><cac:Party>'
			.'<cbc:EndpointID schemeID="0208">0234567890</cbc:EndpointID>'
			.'<cac:PartyName><cbc:Name>Fournisseur2 BV</cbc:Name></cac:PartyName>'
			.'<cac:PartyTaxScheme><cbc:CompanyID>BE0234567890</cbc:CompanyID></cac:PartyTaxScheme>'
			.'</cac:Party></cac:AccountingSupplierParty>'
			.'<cac:AccountingCustomerParty><cac:Party>'
			.'<cac:PartyTaxScheme><cbc:CompanyID>BE0987654321</cbc:CompanyID></cac:PartyTaxScheme>'
			.'</cac:Party></cac:AccountingCustomerParty>'
			.'<cac:LegalMonetaryTotal>'
			.'<cbc:TaxExclusiveAmount>50.00</cbc:TaxExclusiveAmount>'
			.'<cbc:PayableAmount>60.50</cbc:PayableAmount>'
			.'</cac:LegalMonetaryTotal>'
			.'</Invoice>';
	}

	public function testParsesFournisseur1StyleInvoice()
	{
		$parser = new UblInvoiceParser();
		$data = $parser->parse($this->fournisseur1StyleXml());

		$this->assertNotNull($data);
		$this->assertSame(UblInvoiceParser::DOCUMENT_TYPE_INVOICE, $data['document_type']);
		$this->assertSame('FA2026-0421', $data['invoice_number']);
		$this->assertSame('BE0123456789', $data['supplier_vat']);
		$this->assertSame(121.00, $data['amount_ttc']);
		$this->assertSame('+++111/2222/33333+++', $data['payment_ref_raw']);
		$this->assertSame('111222233333', $data['payment_ref_normalized']);
		$this->assertSame('BE00123412341234', $data['payee_iban']);
	}

	/**
	 * Style réel rencontré le 2026-09-02 (annulation de facture reçue via Doccle) : un
	 * CreditNote plutôt qu'un Invoice. Valeurs ci-dessous entièrement fictives.
	 */
	protected function creditNoteStyleXml()
	{
		return '<?xml version="1.0" encoding="UTF-8"?>'
			.'<CreditNote xmlns="'.self::NS_CREDIT_NOTE.'" xmlns:cbc="'.self::NS_CBC.'" xmlns:cac="'.self::NS_CAC.'">'
			.'<cbc:CustomizationID>'.self::EXPECTED_CUSTOMIZATION_ID.'</cbc:CustomizationID>'
			.'<cbc:ID>AV-2026-0007</cbc:ID>'
			.'<cbc:IssueDate>2026-08-12</cbc:IssueDate>'
			.'<cbc:DocumentCurrencyCode>EUR</cbc:DocumentCurrencyCode>'
			.'<cac:AccountingSupplierParty><cac:Party>'
			.'<cac:PartyName><cbc:Name>Fournisseur Eau SC</cbc:Name></cac:PartyName>'
			.'<cac:PartyTaxScheme><cbc:CompanyID>BE0345678901</cbc:CompanyID></cac:PartyTaxScheme>'
			.'</cac:Party></cac:AccountingSupplierParty>'
			.'<cac:AccountingCustomerParty><cac:Party>'
			.'<cac:PartyTaxScheme><cbc:CompanyID>BE0987654321</cbc:CompanyID></cac:PartyTaxScheme>'
			.'</cac:Party></cac:AccountingCustomerParty>'
			.'<cac:LegalMonetaryTotal>'
			.'<cbc:TaxExclusiveAmount>40.00</cbc:TaxExclusiveAmount>'
			.'<cbc:PayableAmount>44.00</cbc:PayableAmount>'
			.'</cac:LegalMonetaryTotal>'
			.'</CreditNote>';
	}

	public function testParsesCreditNote()
	{
		$parser = new UblInvoiceParser();
		$data = $parser->parse($this->creditNoteStyleXml());

		$this->assertNotNull($data);
		$this->assertSame(UblInvoiceParser::DOCUMENT_TYPE_CREDIT_NOTE, $data['document_type']);
		$this->assertSame('AV-2026-0007', $data['invoice_number']);
		$this->assertSame(44.00, $data['amount_ttc']);
		// Pas de notion d'échéance sur une note de crédit.
		$this->assertNull($data['due_date']);
	}

	public function testCreditNoteWithoutExpectedCustomizationIdIsRejected()
	{
		$xml = str_replace(self::EXPECTED_CUSTOMIZATION_ID, 'urn:something:else', $this->creditNoteStyleXml());

		$parser = new UblInvoiceParser();
		$data = $parser->parse($xml);

		$this->assertNull($data);
	}

	public function testUsesPayableAmountNotTaxInclusiveAmount()
	{
		// PayableAmount et TaxInclusiveAmount diffèrent volontairement dans la fixture
		// (cas d'un acompte déjà versé, voir SPEC.md section 6) : amount_ttc doit valoir
		// PayableAmount.
		$xml = str_replace('<cbc:PayableAmount>121.00</cbc:PayableAmount>', '<cbc:PayableAmount>21.00</cbc:PayableAmount>', $this->fournisseur1StyleXml());

		$parser = new UblInvoiceParser();
		$data = $parser->parse($xml);

		$this->assertSame(21.00, $data['amount_ttc']);
	}

	public function testFallsBackToTaxInclusiveAmountWhenPayableAmountIsZero()
	{
		// Cas réel trouvé le 2026-09-02 (frais bancaires prélevés automatiquement) :
		// PayableAmount à 0 quand la facture est déjà intégralement réglée. amount_ttc
		// doit alors valoir TaxInclusiveAmount (le vrai montant de la transaction), pas 0.
		$xml = str_replace('<cbc:PayableAmount>121.00</cbc:PayableAmount>', '<cbc:PayableAmount>0</cbc:PayableAmount>', $this->fournisseur1StyleXml());

		$parser = new UblInvoiceParser();
		$data = $parser->parse($xml);

		$this->assertSame(121.00, $data['amount_ttc']);
	}

	public function testHandlesMissingPaymentMeansGracefully()
	{
		$parser = new UblInvoiceParser();
		$data = $parser->parse($this->fournisseur2StyleXml());

		$this->assertNotNull($data);
		$this->assertNull($data['payment_ref_raw']);
		$this->assertNull($data['payment_ref_normalized']);
		$this->assertNull($data['payee_iban']);
		$this->assertSame('BE0234567890', $data['supplier_vat']);
	}

	public function testNormalizePaymentRefKeepsOnlyDigits()
	{
		$parser = new UblInvoiceParser();

		$this->assertSame('111222233333', $parser->normalizePaymentRef('+++111/2222/33333+++'));
		// Style "Fournisseur3" : communication présente mais sans séparateurs (voir SPEC.md
		// section 6), valeur fictive.
		$this->assertSame('999888777666', $parser->normalizePaymentRef('999888777666'));
	}

	public function testRejectsXmlWithoutExpectedCustomizationId()
	{
		$xml = str_replace(self::EXPECTED_CUSTOMIZATION_ID, 'urn:something:else', $this->fournisseur1StyleXml());

		$parser = new UblInvoiceParser();
		$data = $parser->parse($xml);

		$this->assertNull($data);
		$this->assertNotEmpty($parser->errors);
	}

	public function testRejectsMalformedXml()
	{
		$parser = new UblInvoiceParser();
		$data = $parser->parse('<Invoice><unclosed>');

		$this->assertNull($data);
	}
}
