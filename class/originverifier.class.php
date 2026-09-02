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
 * Vérifie l'authenticité d'un email reçu, sur les en-têtes bruts uniquement (voir
 * SPEC.md section 5).
 *
 * Aucune dépendance à Dolibarr ni à l'API Gmail : cette classe travaille sur le contenu
 * brut d'un .eml déjà récupéré, ce qui permet de la tester entièrement sur des fixtures
 * statiques (voir tests/OriginVerifierTest.php) sans jamais appeler l'API Gmail.
 *
 * Volontairement, le SPF brut n'est jamais vérifié : l'analyse des emails réels montre
 * qu'il apparaît en fail même sur des messages légitimes, à cause d'un relais interne
 * dans l'infrastructure Google entre l'arrivée du message et sa livraison finale. Seuls
 * le domaine du From, le DKIM et le DMARC sont considérés fiables.
 */
class OriginVerifier
{
	/** @var string[] Raisons du rejet, si verify() retourne false */
	public $errors = array();

	/**
	 * @var bool Résultat individuel de chaque vérification (voir SPEC.md section 5), exposé
	 *           séparément pour permettre à l'appelant de distinguer un message totalement
	 *           hors sujet (les trois échouent) d'un message suspect qui mérite une revue
	 *           humaine en quarantaine (au moins l'un des trois passe, voir
	 *           isCompletelyUnrelated() et SPEC.md section 5 et 13).
	 */
	public $domainMatches = false;
	public $dkimPasses = false;
	public $dmarcPasses = false;

	/**
	 * Vérifie qu'un message provient authentiquement du domaine attendu.
	 *
	 * @param string $rawEmlContent  Contenu brut du .eml (en-têtes + corps, tel que renvoyé
	 *                                par l'API Gmail en format "raw")
	 * @param string $expectedDomain Domaine attendu dans le From, DKIM et DMARC (ex: "doccle.be")
	 * @return bool True si les trois conditions de la section 5 sont réunies
	 */
	public function verify($rawEmlContent, $expectedDomain)
	{
		$this->errors = array();
		$this->domainMatches = false;
		$this->dkimPasses = false;
		$this->dmarcPasses = false;
		$expectedDomain = strtolower(trim($expectedDomain));

		$headers = $this->parseHeaders($rawEmlContent);

		$fromDomain = $this->extractFromDomain($this->firstHeader($headers, 'from'));
		$this->domainMatches = ($fromDomain !== null && $fromDomain === $expectedDomain);
		if (!$this->domainMatches) {
			$this->errors[] = "Domaine du From absent ou différent de \"".$expectedDomain."\" (trouvé : \"".($fromDomain !== null ? $fromDomain : '(aucun)')."\")";
		}

		// On ne considère que le premier en-tête Authentication-Results rencontré : c'est
		// celui ajouté par le dernier relais avant livraison finale (le plus proche de la
		// boîte de réception), donc le seul digne de confiance. Un expéditeur externe ne
		// peut pas injecter un faux en-tête à cet endroit précis, un MTA de réception
		// écrase systématiquement tout en-tête de ce nom préexistant.
		$authResults = $this->firstHeader($headers, 'authentication-results');

		if ($authResults === null) {
			$this->errors[] = "Aucun en-tête Authentication-Results trouvé";
			return false;
		}

		$this->dkimPasses = $this->checkDkim($authResults, $expectedDomain);
		if (!$this->dkimPasses) {
			$this->errors[] = "Signature DKIM absente ou invalide pour le domaine \"".$expectedDomain."\"";
		}

		$this->dmarcPasses = $this->checkDmarc($authResults, $expectedDomain);
		if (!$this->dmarcPasses) {
			$this->errors[] = "Résultat DMARC absent ou invalide pour le domaine \"".$expectedDomain."\"";
		}

		return empty($this->errors);
	}

	/**
	 * Distingue un message totalement hors sujet (rien ne rattache ce message à Doccle,
	 * probablement du spam sans rapport plutôt qu'une tentative d'usurpation) d'un message
	 * suspect qui mérite une revue humaine en quarantaine. À appeler après verify().
	 *
	 * Voir SPEC.md section 5 et 13 : seul le second cas part en quarantaine, le premier est
	 * ignoré silencieusement par le worker d'ingestion (aucun enregistrement de staging créé),
	 * décision explicite du 2026-09-02 plutôt que de faire remonter tout le bruit de la boîte.
	 *
	 * @return bool True si aucune des trois vérifications ne passe
	 */
	public function isCompletelyUnrelated()
	{
		return !$this->domainMatches && !$this->dkimPasses && !$this->dmarcPasses;
	}

	/**
	 * Découpe le bloc d'en-têtes bruts en tableau [nom-en-minuscule => valeur], en gardant
	 * toutes les occurrences (un même nom d'en-tête peut apparaître plusieurs fois, notamment
	 * Authentication-Results, une par relais traversé) dans l'ordre d'apparition.
	 *
	 * @param string $rawEmlContent Contenu brut du .eml
	 * @return array<int, array{name: string, value: string}>
	 */
	protected function parseHeaders($rawEmlContent)
	{
		// Les en-têtes s'arrêtent à la première ligne vide (CRLF+CRLF ou LF+LF selon la source).
		$boundary = strpos($rawEmlContent, "\r\n\r\n");
		$lineEnding = "\r\n";
		if ($boundary === false) {
			$boundary = strpos($rawEmlContent, "\n\n");
			$lineEnding = "\n";
			if ($boundary === false) {
				$boundary = strlen($rawEmlContent);
			}
		}

		$headerBlock = substr($rawEmlContent, 0, $boundary);
		$rawLines = explode($lineEnding, $headerBlock);

		$headers = array();
		$current = null;

		foreach ($rawLines as $line) {
			if ($line === '') {
				continue;
			}

			// Ligne repliée (continuation d'un en-tête précédent) : commence par un espace
			// ou une tabulation.
			if (($line[0] === ' ' || $line[0] === "\t") && $current !== null) {
				$headers[$current]['value'] .= ' '.trim($line);
				continue;
			}

			$colonPos = strpos($line, ':');
			if ($colonPos === false) {
				continue;
			}

			$name = strtolower(trim(substr($line, 0, $colonPos)));
			$value = trim(substr($line, $colonPos + 1));

			$headers[] = array('name' => $name, 'value' => $value);
			$current = count($headers) - 1;
		}

		return $headers;
	}

	/**
	 * @param array<int, array{name: string, value: string}> $headers
	 * @param string $name Nom d'en-tête en minuscule
	 * @return string|null Valeur du premier en-tête portant ce nom, null si absent
	 */
	protected function firstHeader($headers, $name)
	{
		foreach ($headers as $header) {
			if ($header['name'] === $name) {
				return $header['value'];
			}
		}

		return null;
	}

	/**
	 * @param string|null $fromHeaderValue Valeur brute de l'en-tête From
	 * @return string|null Domaine en minuscule, null si non extractible
	 */
	protected function extractFromDomain($fromHeaderValue)
	{
		if ($fromHeaderValue === null) {
			return null;
		}

		if (!preg_match('/@([a-z0-9.-]+\.[a-z]{2,})/i', $fromHeaderValue, $matches)) {
			return null;
		}

		return strtolower(rtrim($matches[1], '>'));
	}

	/**
	 * @param string $authResults Valeur de l'en-tête Authentication-Results
	 * @param string $expectedDomain Domaine attendu en minuscule
	 * @return bool
	 */
	protected function checkDkim($authResults, $expectedDomain)
	{
		$pattern = '/dkim=pass\s+header\.i=@'.preg_quote($expectedDomain, '/').'\b/i';

		return (bool) preg_match($pattern, $authResults);
	}

	/**
	 * @param string $authResults Valeur de l'en-tête Authentication-Results
	 * @param string $expectedDomain Domaine attendu en minuscule
	 * @return bool
	 */
	protected function checkDmarc($authResults, $expectedDomain)
	{
		$pattern = '/dmarc=pass\b.*header\.from='.preg_quote($expectedDomain, '/').'\b/i';

		return (bool) preg_match($pattern, $authResults);
	}
}
