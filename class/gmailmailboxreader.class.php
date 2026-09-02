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
	 * @throws Exception Si le rafraîchissement du token échoue (token invalide, révoqué,
	 *                    identifiants client incorrects, etc.), avec le détail exact
	 *                    renvoyé par Google plutôt que de laisser un client sans jeton
	 *                    valide échouer plus tard silencieusement sur le premier appel API.
	 */
	public function __construct($clientId, $clientSecret, $refreshToken)
	{
		$client = new Google_Client();
		$client->setClientId($clientId);
		$client->setClientSecret($clientSecret);
		$client->setScopes(array(Google_Service_Gmail::GMAIL_READONLY));

		// refreshToken() ne lève jamais d'exception en cas d'échec (voir
		// Google_Client::fetchAccessTokenWithRefreshToken) : si Google renvoie une erreur
		// (ex: "invalid_grant"), le tableau retourné ne contient simplement pas
		// d'access_token, et le client continue silencieusement sans jeton valide. On
		// vérifie donc explicitement le résultat plutôt que de laisser le premier appel
		// API échouer plus loin de façon peu explicite.
		$credentials = $client->refreshToken($refreshToken);
		if (!isset($credentials['access_token'])) {
			$detail = isset($credentials['error_description'])
				? $credentials['error_description']
				: (isset($credentials['error']) ? $credentials['error'] : 'réponse inattendue de Google');
			throw new Exception("Échec du rafraîchissement du token OAuth : ".$detail);
		}

		$this->service = new Google_Service_Gmail($client);
	}

	/**
	 * Vérifie que les identifiants configurés fonctionnent réellement, sans rien
	 * télécharger : appelle l'endpoint Gmail le plus léger possible (users.getProfile),
	 * qui confirme juste l'accès et renvoie l'adresse du compte autorisé. Utilisé par le
	 * bouton "Tester la connexion" de admin/setup.php, pour diagnostiquer un problème de
	 * credentials sans attendre un cycle complet du cron d'ingestion.
	 *
	 * @return array{success: bool, email?: string, messagesTotal?: int, error?: string}
	 */
	public function testConnection()
	{
		try {
			$profile = $this->service->users->getProfile('me');
		} catch (Exception $e) {
			return array('success' => false, 'error' => get_class($e).' : '.$e->getMessage());
		}

		$email = $profile !== null ? $profile->getEmailAddress() : null;

		// Distingue un vrai succès (adresse email présente) d'une réponse HTTP réussie
		// mais vide ou mal désérialisée (voir la découverte du 2026-09-02, un premier
		// appel a montré "succès" avec des champs vides sans qu'aucune exception n'ait
		// été levée) : dans ce cas, mieux vaut le signaler explicitement que d'afficher
		// un faux succès trompeur, avec assez de détail pour comprendre où ça a coincé.
		if ($email === null || $email === '') {
			return array(
				'success' => false,
				'error' => 'Réponse Google reçue sans erreur HTTP mais sans adresse email exploitable. '
					.'Profile null : '.var_export($profile === null, true).'. '
					.'Classe reçue : '.($profile !== null ? get_class($profile) : 'n/a').'. '
					.'Contenu brut : '.($profile !== null ? var_export($profile->toSimpleObject(), true) : 'n/a'),
			);
		}

		return array(
			'success' => true,
			'email' => $email,
			'messagesTotal' => $profile->getMessagesTotal(),
		);
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
