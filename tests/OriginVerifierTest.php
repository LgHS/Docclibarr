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
 * Fixtures synthétiques, voir tests/fixtures/README.md : à remplacer par les 3 emails
 * réels anonymisés dès que possible.
 */
class OriginVerifierTest extends TestCase
{
	protected function legitimateEml($extraHeaders = '')
	{
		return "Delivered-To: boite-dediee@example.org\r\n"
			."Received: by mx.google.com; Wed, 01 Sep 2026 08:00:00 +0200\r\n"
			."Authentication-Results: mx.google.com;\r\n"
			."       dkim=pass header.i=@doccle.be header.s=selector1 header.b=abc123;\r\n"
			."       spf=fail (google.com: domain of bounce@doccle.be does not designate 1.2.3.4 as permitted sender) smtp.mailfrom=bounce@doccle.be;\r\n"
			."       dmarc=pass (p=REJECT sp=REJECT dis=NONE) header.from=doccle.be\r\n"
			."From: Doccle <community@doccle.be>\r\n"
			."To: boite-dediee@example.org\r\n"
			."Subject: Nouvelle facture\r\n"
			.$extraHeaders
			."\r\n"
			."Corps du message.";
	}

	public function testAcceptsLegitimateMessage()
	{
		$verifier = new OriginVerifier();
		$result = $verifier->verify($this->legitimateEml(), 'doccle.be');

		$this->assertTrue($result, "Message légitime rejeté à tort : ".implode(' ; ', $verifier->errors));
		$this->assertEmpty($verifier->errors);
	}

	public function testSpfFailAloneDoesNotBlockAcceptance()
	{
		// Réplique exactement le cas documenté en SPEC.md section 5 : spf=fail dans
		// l'Authentication-Results même sur un message légitime, à cause du relais
		// interne Google. Le SPF n'est jamais vérifié par OriginVerifier.
		$verifier = new OriginVerifier();
		$result = $verifier->verify($this->legitimateEml(), 'doccle.be');

		$this->assertTrue($result);
	}

	public function testRejectsWrongFromDomain()
	{
		$eml = "Authentication-Results: mx.google.com; dkim=pass header.i=@doccle.be; dmarc=pass header.from=doccle.be\r\n"
			."From: Attaquant <community@doccle-be.example.com>\r\n"
			."\r\n"
			."Corps.";

		$verifier = new OriginVerifier();
		$result = $verifier->verify($eml, 'doccle.be');

		$this->assertFalse($result);
		$this->assertNotEmpty($verifier->errors);
	}

	public function testRejectsFailedDkim()
	{
		$eml = "Authentication-Results: mx.google.com; dkim=fail header.i=@doccle.be; dmarc=pass header.from=doccle.be\r\n"
			."From: Doccle <community@doccle.be>\r\n"
			."\r\n"
			."Corps.";

		$verifier = new OriginVerifier();
		$result = $verifier->verify($eml, 'doccle.be');

		$this->assertFalse($result);
	}

	public function testRejectsFailedDmarc()
	{
		$eml = "Authentication-Results: mx.google.com; dkim=pass header.i=@doccle.be; dmarc=fail header.from=doccle.be\r\n"
			."From: Doccle <community@doccle.be>\r\n"
			."\r\n"
			."Corps.";

		$verifier = new OriginVerifier();
		$result = $verifier->verify($eml, 'doccle.be');

		$this->assertFalse($result);
	}

	public function testRejectsMissingAuthenticationResults()
	{
		$eml = "From: Doccle <community@doccle.be>\r\n\r\nCorps.";

		$verifier = new OriginVerifier();
		$result = $verifier->verify($eml, 'doccle.be');

		$this->assertFalse($result);
	}

	public function testOnlyFirstAuthenticationResultsHeaderIsTrusted()
	{
		// Deux en-têtes Authentication-Results (plusieurs relais traversés) : seul le
		// premier (le plus récent, ajouté par le relais final) doit être pris en compte.
		$eml = "Authentication-Results: mx.google.com; dkim=pass header.i=@doccle.be; dmarc=pass header.from=doccle.be\r\n"
			."Authentication-Results: relais-interne.example.com; dkim=fail header.i=@doccle.be; dmarc=fail header.from=doccle.be\r\n"
			."From: Doccle <community@doccle.be>\r\n"
			."\r\n"
			."Corps.";

		$verifier = new OriginVerifier();
		$result = $verifier->verify($eml, 'doccle.be');

		$this->assertTrue($result, "Le premier en-tête aurait dû suffire : ".implode(' ; ', $verifier->errors));
	}
}
