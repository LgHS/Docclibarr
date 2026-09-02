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
 * Teste uniquement InvoiceMatcher::match(), la partie sans dépendance à Dolibarr.
 * findCandidates() (requête SQL réelle) n'est pas testable ici, voir SPEC.md section 14.
 */
class InvoiceMatcherTest extends TestCase
{
	public function testLevel1ExactPaymentRefMatch()
	{
		$staging = array(
			'payment_ref_normalized' => '111222233333',
			'supplier_vat' => 'BE0123456789',
			'amount_ttc' => 121.00,
			'issue_date' => '2026-08-15',
		);
		$candidates = array(
			array('id' => 42, 'ref_supplier' => '+++111/2222/33333+++', 'supplier_vat' => 'BE0999999999', 'amount_ttc' => 999.00, 'date' => '2020-01-01'),
		);

		$matcher = new InvoiceMatcher();
		$result = $matcher->match($staging, $candidates);

		$this->assertNotNull($result);
		$this->assertSame(InvoiceMatcher::CONFIDENCE_HIGH, $result['confidence']);
		$this->assertSame(42, $result['candidate_id']);
	}

	public function testLevel1TakesPriorityOverLevel2()
	{
		// Un candidat matche exactement en niveau 1 (mauvaise TVA/montant sinon), un autre
		// matcherait en niveau 2 : le niveau 1 doit l'emporter.
		$staging = array(
			'payment_ref_normalized' => '111222233333',
			'supplier_vat' => 'BE0123456789',
			'amount_ttc' => 121.00,
			'issue_date' => '2026-08-15',
		);
		$candidates = array(
			array('id' => 1, 'ref_supplier' => 'AUTRE-REF', 'supplier_vat' => 'BE0123456789', 'amount_ttc' => 121.00, 'date' => '2026-08-15'),
			array('id' => 42, 'ref_supplier' => '+++111/2222/33333+++', 'supplier_vat' => 'BE0999999999', 'amount_ttc' => 1.00, 'date' => '2000-01-01'),
		);

		$matcher = new InvoiceMatcher();
		$result = $matcher->match($staging, $candidates);

		$this->assertSame(42, $result['candidate_id']);
	}

	public function testLevel2FallbackOnVatAmountAndDateWindow()
	{
		// Pas de communication structurée exploitable (cas OGBAY, voir SPEC.md section 8).
		$staging = array(
			'payment_ref_normalized' => null,
			'supplier_vat' => 'BE0234567890',
			'amount_ttc' => 60.50,
			'issue_date' => '2026-08-10',
		);
		$candidates = array(
			array('id' => 7, 'ref_supplier' => null, 'supplier_vat' => 'BE0234567890', 'amount_ttc' => 60.50, 'date' => '2026-08-20'),
		);

		$matcher = new InvoiceMatcher();
		$result = $matcher->match($staging, $candidates);

		$this->assertNotNull($result);
		$this->assertSame(InvoiceMatcher::CONFIDENCE_MEDIUM, $result['confidence']);
		$this->assertSame(7, $result['candidate_id']);
	}

	public function testLevel2RejectsOutsideDateWindow()
	{
		$staging = array(
			'payment_ref_normalized' => null,
			'supplier_vat' => 'BE0234567890',
			'amount_ttc' => 60.50,
			'issue_date' => '2026-08-10',
		);
		$candidates = array(
			// 46 jours d'écart, juste au-dessus de la fenêtre de 45 jours.
			array('id' => 7, 'ref_supplier' => null, 'supplier_vat' => 'BE0234567890', 'amount_ttc' => 60.50, 'date' => '2026-09-25'),
		);

		$matcher = new InvoiceMatcher();
		$result = $matcher->match($staging, $candidates);

		$this->assertNull($result);
	}

	public function testLevel2RejectsDifferentAmount()
	{
		$staging = array(
			'payment_ref_normalized' => null,
			'supplier_vat' => 'BE0234567890',
			'amount_ttc' => 60.50,
			'issue_date' => '2026-08-10',
		);
		$candidates = array(
			array('id' => 7, 'ref_supplier' => null, 'supplier_vat' => 'BE0234567890', 'amount_ttc' => 60.51, 'date' => '2026-08-10'),
		);

		$matcher = new InvoiceMatcher();
		$result = $matcher->match($staging, $candidates);

		$this->assertNull($result);
	}

	public function testNoMatchReturnsNull()
	{
		$staging = array(
			'payment_ref_normalized' => '999999999999',
			'supplier_vat' => 'BE0111111111',
			'amount_ttc' => 10.00,
			'issue_date' => '2026-08-10',
		);
		$candidates = array(
			array('id' => 1, 'ref_supplier' => 'AUTRE', 'supplier_vat' => 'BE0222222222', 'amount_ttc' => 20.00, 'date' => '2000-01-01'),
		);

		$matcher = new InvoiceMatcher();
		$result = $matcher->match($staging, $candidates);

		$this->assertNull($result);
	}

	public function testEmptyCandidateListReturnsNull()
	{
		$staging = array(
			'payment_ref_normalized' => '111222233333',
			'supplier_vat' => 'BE0123456789',
			'amount_ttc' => 121.00,
			'issue_date' => '2026-08-15',
		);

		$matcher = new InvoiceMatcher();
		$result = $matcher->match($staging, array());

		$this->assertNull($result);
	}

	public function testVatComparisonIgnoresCaseAndSpaces()
	{
		$staging = array(
			'payment_ref_normalized' => null,
			'supplier_vat' => 'be 0234.567.890',
			'amount_ttc' => 60.50,
			'issue_date' => '2026-08-10',
		);
		$candidates = array(
			array('id' => 7, 'ref_supplier' => null, 'supplier_vat' => 'BE0234567890', 'amount_ttc' => 60.50, 'date' => '2026-08-10'),
		);

		$matcher = new InvoiceMatcher();
		$result = $matcher->match($staging, $candidates);

		$this->assertNotNull($result);
		$this->assertSame(7, $result['candidate_id']);
	}
}
