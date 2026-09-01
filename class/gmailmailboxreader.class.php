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

require_once __DIR__.'/../vendor/autoload.php';

/**
 * Récupère les messages de la boîte Gmail dédiée via l'API Gmail (voir SPEC.md
 * section 4 et 16 pour la procédure de création des identifiants OAuth).
 *
 * Seule classe du module qui appelle réellement l'API Gmail (google/apiclient) : c'est
 * volontairement la "couche 2" la plus fine possible (voir SPEC.md section 14), toute la
 * logique métier (vérification d'origine, parsing XML, matching) vit ailleurs et se
 * teste sans jamais instancier cette classe.
 */
class GmailMailboxReader
{
	/** @var string[] */
	public $errors = array();

	/** @var Google_Service_Gmail */
	protected $service;

	/**
	 * @param string $clientId     Identifiant client OAuth (voir admin/setup.php)
	 * @param string $clientSecret Secret client OAuth
	 * @param string $refreshToken Refresh token généré une fois pour la boîte dédiée
	 */
	public function __construct($clientId, $clientSecret, $refreshToken)
	{
		$client = new Google_Client();
		$client->setClientId($clientId);
		$client->setClientSecret($clientSecret);
		$client->refreshToken($refreshToken);
		$client->setScopes(array(Google_Service_Gmail::GMAIL_READONLY));

		$this->service = new Google_Service_Gmail($client);
	}

	/**
	 * Récupère le contenu brut (.eml) de tous les messages correspondant à la requête,
	 * avec pagination. La requête ne sert qu'à limiter le volume à traiter (voir SPEC.md
	 * section 4) : la vérification d'authenticité réelle se fait toujours après, sur les
	 * en-têtes bruts de chaque message (voir OriginVerifier), jamais sur ce filtre seul.
	 *
	 * @param string $query      Requête de recherche Gmail, ex: "from:community@doccle.be has:attachment"
	 * @param int    $maxResults Taille de page
	 * @return array<int, array{message_id: string, raw: string}>
	 */
	public function fetchRawMessages($query, $maxResults = 50)
	{
		$this->errors = array();
		$messages = array();
		$pageToken = null;

		do {
			$params = array('q' => $query, 'maxResults' => $maxResults);
			if ($pageToken !== null) {
				$params['pageToken'] = $pageToken;
			}

			try {
				$response = $this->service->users_messages->listUsersMessages('me', $params);
			} catch (Exception $e) {
				$this->errors[] = "Échec de la liste des messages Gmail : ".$e->getMessage();
				return $messages;
			}

			foreach ((array) $response->getMessages() as $messageStub) {
				$raw = $this->fetchRawMessageById($messageStub->getId());
				if ($raw !== null) {
					$messages[] = array('message_id' => $messageStub->getId(), 'raw' => $raw);
				}
			}

			$pageToken = $response->getNextPageToken();
		} while (!empty($pageToken));

		return $messages;
	}

	/**
	 * @param string $messageId Id Gmail du message
	 * @return string|null Contenu brut (.eml) décodé, null en cas d'échec
	 */
	protected function fetchRawMessageById($messageId)
	{
		try {
			$full = $this->service->users_messages->get('me', $messageId, array('format' => 'raw'));
		} catch (Exception $e) {
			$this->errors[] = "Échec de récupération du message ".$messageId." : ".$e->getMessage();
			return null;
		}

		// Gmail encode le message brut en base64url (RFC 4648 section 5), pas le base64
		// standard : substitution des caractères avant décodage.
		return base64_decode(strtr($full->getRaw(), '-_', '+/'));
	}
}
