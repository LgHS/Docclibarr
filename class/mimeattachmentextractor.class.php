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
 * Extrait les pièces jointes (PDF, XML) d'un .eml brut au format MIME multipart (voir
 * SPEC.md section 4 et 7).
 *
 * Parseur MIME manuel volontairement minimal plutôt qu'une dépendance composer
 * supplémentaire : la structure des emails Doccle observée est un multipart/mixed
 * classique (corps + deux pièces jointes), pas besoin d'une librairie complète pour ça.
 * Aucune dépendance à Dolibarr ni à l'API Gmail, testable sur fixtures statiques (voir
 * tests/MimeAttachmentExtractorTest.php).
 */
class MimeAttachmentExtractor
{
	/** @var string[] */
	public $errors = array();

	/**
	 * @param string $rawEmlContent Contenu brut du .eml
	 * @return array<int, array{filename: string, mimeType: string, content: string}>
	 */
	public function extractAttachments($rawEmlContent)
	{
		$this->errors = array();

		$boundary = strpos($rawEmlContent, "\r\n\r\n");
		$lineEnding = "\r\n";
		if ($boundary === false) {
			$boundary = strpos($rawEmlContent, "\n\n");
			$lineEnding = "\n";
			if ($boundary === false) {
				$this->errors[] = "Impossible de séparer en-têtes et corps du message";
				return array();
			}
		}

		$headerBlock = substr($rawEmlContent, 0, $boundary);
		$body = substr($rawEmlContent, $boundary + strlen($lineEnding.$lineEnding));

		$contentType = $this->extractHeaderValue($headerBlock, 'content-type');
		$attachments = array();
		$this->walkPart($contentType, $body, $attachments);

		return $attachments;
	}

	/**
	 * Traite récursivement une partie MIME : si c'est un multipart, découpe sur sa
	 * boundary et rappelle walkPart() sur chaque sous-partie ; sinon, si c'est une pièce
	 * jointe PDF ou XML, la décode et l'ajoute au résultat.
	 *
	 * @param string $contentTypeHeader Valeur de l'en-tête Content-Type de cette partie
	 * @param string $partBody          Corps brut de cette partie (en-têtes propres inclus
	 *                                    pour une sous-partie de multipart)
	 * @param array  $attachments       Accumulateur, par référence
	 */
	protected function walkPart($contentTypeHeader, $partBody, array &$attachments)
	{
		if (preg_match('/multipart\/[a-z-]+/i', $contentTypeHeader) && preg_match('/boundary="?([^";\r\n]+)"?/i', $contentTypeHeader, $m)) {
			$boundary = $m[1];
			$rawParts = preg_split('/--'.preg_quote($boundary, '/').'(--)?/', $partBody);

			foreach ($rawParts as $rawPart) {
				$rawPart = trim($rawPart);
				if ($rawPart === '') {
					continue;
				}

				$sep = strpos($rawPart, "\r\n\r\n");
				$sepLen = 4;
				if ($sep === false) {
					$sep = strpos($rawPart, "\n\n");
					$sepLen = 2;
					if ($sep === false) {
						continue;
					}
				}

				$subHeaders = substr($rawPart, 0, $sep);
				$subBody = substr($rawPart, $sep + $sepLen);
				$subContentType = $this->extractHeaderValue($subHeaders, 'content-type');

				if (preg_match('/multipart\//i', $subContentType)) {
					$this->walkPart($subContentType, $subBody, $attachments);
					continue;
				}

				$this->maybeExtractAttachment($subHeaders, $subContentType, $subBody, $attachments);
			}

			return;
		}

		// Partie unique, pas de multipart englobant (cas rare pour un email Doccle, mais
		// on ne veut pas échouer silencieusement).
		$this->maybeExtractAttachment('', $contentTypeHeader, $partBody, $attachments);
	}

	/**
	 * @param string $subHeaders      En-têtes bruts de la sous-partie
	 * @param string $subContentType  Content-Type de la sous-partie
	 * @param string $subBody         Corps brut (encodé) de la sous-partie
	 * @param array  $attachments     Accumulateur, par référence
	 */
	protected function maybeExtractAttachment($subHeaders, $subContentType, $subBody, array &$attachments)
	{
		$isPdf = stripos($subContentType, 'application/pdf') !== false;
		$isXml = stripos($subContentType, 'xml') !== false; // application/xml ou text/xml selon l'émetteur

		if (!$isPdf && !$isXml) {
			return;
		}

		$encoding = strtolower($this->extractHeaderValue($subHeaders, 'content-transfer-encoding'));
		$decoded = $this->decodeBody($subBody, $encoding);

		$filename = $this->extractFilename($subHeaders);
		if ($filename === null) {
			// Le nom de fichier d'origine ne doit jamais servir de source d'information
			// (voir SPEC.md section 7), on en fabrique un provisoire si absent : le
			// renommage définitif se fait de toute façon après le parsing XML.
			$filename = $isPdf ? 'piece.pdf' : 'facture.xml';
		}

		$attachments[] = array(
			'filename' => $filename,
			'mimeType' => $isPdf ? 'application/pdf' : 'application/xml',
			'content' => $decoded,
		);
	}

	/**
	 * @param string $headerBlock
	 * @param string $name Nom d'en-tête en minuscule
	 * @return string
	 */
	protected function extractHeaderValue($headerBlock, $name)
	{
		if (preg_match('/^'.preg_quote($name, '/').':\s*(.+(?:\r?\n[ \t].+)*)/mi', $headerBlock, $m)) {
			return preg_replace('/\r?\n[ \t]+/', ' ', trim($m[1]));
		}

		return '';
	}

	/**
	 * @param string $subHeaders
	 * @return string|null
	 */
	protected function extractFilename($subHeaders)
	{
		$disposition = $this->extractHeaderValue($subHeaders, 'content-disposition');
		if (preg_match('/filename\*?=("?)([^";\r\n]+)\1/i', $disposition, $m)) {
			return trim($m[2]);
		}

		$contentType = $this->extractHeaderValue($subHeaders, 'content-type');
		if (preg_match('/name\*?=("?)([^";\r\n]+)\1/i', $contentType, $m)) {
			return trim($m[2]);
		}

		return null;
	}

	/**
	 * @param string $body
	 * @param string $encoding Content-Transfer-Encoding en minuscule
	 * @return string
	 */
	protected function decodeBody($body, $encoding)
	{
		switch ($encoding) {
			case 'base64':
				return base64_decode(preg_replace('/\s+/', '', $body));
			case 'quoted-printable':
				return quoted_printable_decode($body);
			default:
				return $body;
		}
	}
}
