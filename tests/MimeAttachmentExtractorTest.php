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
 * Fixture synthétique, voir tests/fixtures/README.md.
 */
class MimeAttachmentExtractorTest extends TestCase
{
	public function testExtractsPdfAndXmlFromMultipartMessage()
	{
		$pdfContent = "%PDF-1.4 contenu factice";
		$xmlContent = '<Invoice><cbc:ID>FA1</cbc:ID></Invoice>';

		$eml = "From: Doccle <community@doccle.be>\r\n"
			."Content-Type: multipart/mixed; boundary=\"outer\"\r\n"
			."\r\n"
			."--outer\r\n"
			."Content-Type: text/plain\r\n"
			."\r\n"
			."Corps du message.\r\n"
			."--outer\r\n"
			."Content-Type: application/pdf; name=\"whatever-doccle-names-it.pdf\"\r\n"
			."Content-Transfer-Encoding: base64\r\n"
			."Content-Disposition: attachment; filename=\"whatever-doccle-names-it.pdf\"\r\n"
			."\r\n"
			.chunk_split(base64_encode($pdfContent))
			."--outer\r\n"
			."Content-Type: application/xml; name=\"invoice.xml\"\r\n"
			."Content-Transfer-Encoding: base64\r\n"
			."Content-Disposition: attachment; filename=\"invoice.xml\"\r\n"
			."\r\n"
			.chunk_split(base64_encode($xmlContent))
			."--outer--\r\n";

		$extractor = new MimeAttachmentExtractor();
		$attachments = $extractor->extractAttachments($eml);

		$this->assertCount(2, $attachments);

		$pdf = array_values(array_filter($attachments, function ($a) {
			return $a['mimeType'] === 'application/pdf';
		}))[0];
		$xml = array_values(array_filter($attachments, function ($a) {
			return $a['mimeType'] === 'application/xml';
		}))[0];

		$this->assertSame($pdfContent, $pdf['content']);
		$this->assertSame($xmlContent, $xml['content']);
		$this->assertSame('whatever-doccle-names-it.pdf', $pdf['filename']);
	}

	public function testIgnoresNonAttachmentTextParts()
	{
		$eml = "Content-Type: multipart/mixed; boundary=\"outer\"\r\n"
			."\r\n"
			."--outer\r\n"
			."Content-Type: text/html\r\n"
			."\r\n"
			."<p>Pas une pièce jointe</p>\r\n"
			."--outer--\r\n";

		$extractor = new MimeAttachmentExtractor();
		$attachments = $extractor->extractAttachments($eml);

		$this->assertCount(0, $attachments);
	}

	public function testFallsBackToGenericFilenameWhenNoneProvided()
	{
		$eml = "Content-Type: multipart/mixed; boundary=\"outer\"\r\n"
			."\r\n"
			."--outer\r\n"
			."Content-Type: application/pdf\r\n"
			."Content-Transfer-Encoding: base64\r\n"
			."\r\n"
			.chunk_split(base64_encode("%PDF-1.4"))
			."--outer--\r\n";

		$extractor = new MimeAttachmentExtractor();
		$attachments = $extractor->extractAttachments($eml);

		$this->assertCount(1, $attachments);
		$this->assertSame('piece.pdf', $attachments[0]['filename']);
	}
}
