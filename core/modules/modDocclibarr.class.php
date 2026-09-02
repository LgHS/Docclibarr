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
 * Descripteur du module Docclibarr.
 * Déclare les permissions, le menu et la configuration du module.
 */

include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

class modDocclibarr extends DolibarrModules
{
	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		global $langs, $conf;

		$this->db = $db;

		// Identifiant unique du module. Plage 100000-499999 = éditeurs tiers (à réserver
		// officiellement auprès de l'équipe Dolibarr avant publication sur le Dolistore,
		// voir https://wiki.dolibarr.org/index.php?title=List_of_modules_id).
		// 109501 = choix provisoire, PAS ENCORE réservé officiellement (109500 déjà pris par DoliFius).
		$this->numero = 109501;

		// Nom technique utilisé pour $user->rights->docclibarr->...
		$this->rights_class = 'docclibarr';

		$this->family = "financial";
		$this->module_position = '91';

		$this->name = preg_replace('/^mod/i', '', get_class($this));
		$this->description = "Ingestion des factures électroniques Doccle (Peppol/UBL) reçues par email dans Dolibarr";
		$this->descriptionlong = "Lit une boîte mail dédiée recevant les factures fournisseurs relayées par Peppol au nom de Doccle, vérifie l'authenticité de chaque message (DKIM/DMARC), extrait les données structurées du XML UBL, et propose un rattachement aux factures fournisseurs Dolibarr. Aucune écriture ni rattachement n'est jamais appliqué sans validation humaine explicite.";

		$this->editor_name = 'iooner for LgHS';
		$this->editor_url = 'https://github.com/LgHS/Docclibarr';

		$this->version = '0.2.11';

		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);

		// Icône du module (syntaxe "nom@techname" pour indiquer à Dolibarr de la chercher
		// dans le dossier du module). Fichiers à fournir dans img/ : docclibarr.png (menu,
		// titres de page) et object_docclibarr.png (liste des modules dans l'admin), sur le
		// modèle de DoliFius.
		$this->picto = 'docclibarr@docclibarr';

		$this->module_parts = array(
			'triggers' => 0,
			'login' => 0,
			'substitutions' => 0,
			'menus' => 0,
			'theme' => 0,
			'tpl' => 0,
			'barcode' => 0,
			'models' => 0,
			'css' => array(),
			'js' => array(),
			'hooks' => array(),
			'moduleforexternal' => 0,
		);

		$this->dirs = array();

		// Page de configuration du module
		$this->config_page_url = array("setup.php@docclibarr");

		$this->hidden = false;

		// Dépend du module Fournisseurs (factures fournisseur) du cœur Dolibarr
		$this->depends = array('modFournisseur');
		$this->requiredby = array();
		$this->conflictwith = array();

		$this->langfiles = array("docclibarr@docclibarr");

		$this->phpmin = array(7, 2);
		// Même cible que DoliFius : instance LgHS en 22.x au moment de l'écriture.
		$this->need_dolibarr_version = array(22, -3);

		$this->warnings_activation = array();
		$this->warnings_activation_ext = array();

		// Constantes de configuration (adresse de la boîte mail dédiée, identifiants OAuth
		// Gmail, etc.) - définies via admin/setup.php, jamais codées en dur (voir SPEC.md
		// section 4).
		$this->const = array();

		// Pas de widgets pour la V1
		$this->boxes = array();

		// Tâche cron de secours (voir SPEC.md section 4 et 15) : filet de sécurité si la
		// notification push Pub/Sub est manquée, pas encore implémentée en phase 1. Toutes
		// les 15 minutes, désactivée par défaut à l'activation (l'utilisateur doit la
		// vérifier/activer une fois la configuration Gmail renseignée, voir admin/setup.php).
		$this->cronjobs = array(
			0 => array(
				'label' => 'DocclibarrIngestionWorker',
				'jobtype' => 'method',
				'class' => 'docclibarr/class/ingestionworker.class.php',
				'objectname' => 'IngestionWorker',
				'method' => 'run',
				'parameters' => '',
				'comment' => "Récupère les nouvelles factures électroniques Doccle et les met en staging",
				'frequency' => 15,
				'unitfrequency' => 60,
				'status' => 0,
				'test' => true,
				'priority' => 50,
			),
		);

		// Permissions
		$this->rights = array();
		$r = 0;

		$this->rights[$r][0] = $this->numero + 1;
		$this->rights[$r][1] = "Consulter les factures électroniques reçues et leur statut";
		$this->rights[$r][4] = 'read';
		$r++;

		$this->rights[$r][0] = $this->numero + 2;
		$this->rights[$r][1] = "Valider ou rejeter le rattachement d'une facture électronique";
		$this->rights[$r][4] = 'validate';
		$r++;

		// Menus
		$this->menu = array();
		$r = 0;

		$this->menu[$r] = array(
			'fk_menu' => 'fk_mainmenu=billing',
			'type' => 'left',
			'titre' => 'Factures électroniques Doccle',
			// Icône Font Awesome plutôt qu'une image custom, pour rester cohérent avec le
			// reste du menu Facturation.
			'prefix' => '<i class="fas fa-envelope-open-text pictofixedwidth"></i>',
			'mainmenu' => 'billing',
			'leftmenu' => 'docclibarr',
			'url' => '/docclibarr/list.php',
			'langs' => 'docclibarr@docclibarr',
			'position' => 100,
			'enabled' => '1',
			'perms' => '$user->rights->docclibarr->read',
			'target' => '',
			'user' => 0,
		);
		$r++;
	}

	/**
	 * Activation du module.
	 *
	 * @param string $options Options
	 * @return int 1 si OK, 0 si KO
	 */
	public function init($options = '')
	{
		$sql = array();
		$sql[] = "-- Voir sql/llx_facturation_electronique_staging.sql pour le détail des colonnes";

		$result = $this->_load_tables('/docclibarr/sql/');
		if ($result < 0) {
			return 0;
		}

		return $this->_init($sql, $options);
	}

	/**
	 * Désactivation du module.
	 *
	 * @param string $options Options
	 * @return int 1 si OK, 0 si KO
	 */
	public function remove($options = '')
	{
		$sql = array();

		return $this->_remove($sql, $options);
	}
}
