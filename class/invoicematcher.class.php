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
 * Moteur de rapprochement en cascade (voir SPEC.md section 8), matche uniquement contre
 * les factures fournisseur (`llx_facture_fourn`, voir la correction de section 0 : pas de
 * ligne bancaire déjà réconciliée à cibler).
 *
 * La logique de score elle-même (match()) est pure, sans dépendance à Dolibarr, testable
 * en isolation sur des candidats fournis à la main (voir tests/InvoiceMatcherTest.php).
 * Seule findCandidates() touche la base Dolibarr et n'a pas pu être vérifiée contre un
 * vrai schéma (à tester en couche 3, voir SPEC.md section 14).
 *
 * Convention confirmée par l'utilisateur (2026-09-02) : la communication structurée du
 * fournisseur est saisie dans le champ standard `ref_supplier` de la facture fournisseur
 * Dolibarr, comparée ici normalisée (chiffres uniquement) comme le reste du module.
 */
class InvoiceMatcher
{
	const CONFIDENCE_HIGH = 'high';
	const CONFIDENCE_MEDIUM = 'medium';

	/** Fenêtre de date raisonnable pour le niveau 2 (voir SPEC.md section 8) */
	const DATE_WINDOW_DAYS = 45;

	/**
	 * Tolérance de comparaison des montants : seulement pour absorber le bruit
	 * d'arrondi flottant (ex: 0.1 + 0.2 != 0.3 en float), jamais pour tolérer un vrai
	 * écart d'un centime entre deux montants distincts.
	 */
	const AMOUNT_EPSILON = 0.001;

	/**
	 * Cascade de matching, purement fonctionnelle : ne touche jamais la base, ne décide
	 * jamais seule d'appliquer quoi que ce soit (voir SPEC.md section 8 et 12, le score ne
	 * sert qu'à trier et pré-remplir le dashboard).
	 *
	 * @param array $stagingData Données extraites du XML : payment_ref_normalized,
	 *                            supplier_vat, amount_ttc, issue_date (format Y-m-d ou
	 *                            timestamp Unix)
	 * @param array $candidates  Factures fournisseur candidates, chacune sous la forme
	 *                            array('id'=>int, 'ref_supplier'=>string|null,
	 *                            'supplier_vat'=>string|null, 'amount_ttc'=>float|null,
	 *                            'date'=>string|int|null)
	 * @return array|null array('confidence'=>self::CONFIDENCE_*, 'candidate_id'=>int) si un
	 *                     candidat est retenu, null sinon (niveau 3, aucune proposition)
	 */
	public function match(array $stagingData, array $candidates)
	{
		$paymentRefNormalized = $stagingData['payment_ref_normalized'] ?? null;

		// Niveau 1 (fort) : communication structurée normalisée exactement identique.
		if ($paymentRefNormalized !== null && $paymentRefNormalized !== '') {
			foreach ($candidates as $candidate) {
				$candidateRef = $this->normalizeDigitsOnly($candidate['ref_supplier'] ?? '');
				if ($candidateRef !== '' && $candidateRef === $paymentRefNormalized) {
					return array('confidence' => self::CONFIDENCE_HIGH, 'candidate_id' => (int) $candidate['id']);
				}
			}
		}

		// Niveau 2 (moyen) : TVA fournisseur exacte + montant TTC exact + fenêtre de date.
		$supplierVat = self::normalizeVat($stagingData['supplier_vat'] ?? null);
		$amountTtc = $stagingData['amount_ttc'] ?? null;
		$issueDate = $stagingData['issue_date'] ?? null;

		if ($supplierVat !== null && $amountTtc !== null && $issueDate !== null) {
			$issueTimestamp = is_int($issueDate) ? $issueDate : strtotime($issueDate);

			foreach ($candidates as $candidate) {
				$candidateVat = self::normalizeVat($candidate['supplier_vat'] ?? null);
				if ($candidateVat === null || $candidateVat !== $supplierVat) {
					continue;
				}

				if ($candidate['amount_ttc'] === null || abs((float) $candidate['amount_ttc'] - (float) $amountTtc) > self::AMOUNT_EPSILON) {
					continue;
				}

				if ($candidate['date'] === null) {
					continue;
				}
				$candidateTimestamp = is_int($candidate['date']) ? $candidate['date'] : strtotime($candidate['date']);
				if ($issueTimestamp === false || $candidateTimestamp === false) {
					continue;
				}

				$diffDays = abs($issueTimestamp - $candidateTimestamp) / 86400;
				if ($diffDays > self::DATE_WINDOW_DAYS) {
					continue;
				}

				return array('confidence' => self::CONFIDENCE_MEDIUM, 'candidate_id' => (int) $candidate['id']);
			}
		}

		// Niveau 3 : aucune correspondance suffisante.
		return null;
	}

	/**
	 * @param string $value
	 * @return string
	 */
	protected function normalizeDigitsOnly($value)
	{
		return preg_replace('/[^0-9]/', '', (string) $value);
	}

	/**
	 * Normalisation d'une TVA (majuscules, sans espaces ni ponctuation), publique et
	 * statique car réutilisée par IngestionWorker pour le garde-fou anti-usurpation de la
	 * TVA client (voir SPEC.md section 6, 12 et 13).
	 *
	 * @param string|null $vat
	 * @return string|null
	 */
	public static function normalizeVat($vat)
	{
		if ($vat === null) {
			return null;
		}

		$normalized = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $vat));

		return $normalized !== '' ? $normalized : null;
	}

	/**
	 * Récupère les factures fournisseur candidates pour une TVA donnée, en excluant
	 * celles déjà rattachées à un autre enregistrement de staging validé (garde contre le
	 * double rattachement d'une même facture à deux emails différents, voir la mémoire
	 * reference_dolifius_dolibarr_conventions, technique reprise du design V2 jamais
	 * construit sur DoliFius).
	 *
	 * AVERTISSEMENT : requête non vérifiée contre un vrai schéma Dolibarr (à tester en
	 * couche 3, voir SPEC.md section 14). Suppose une table llx_societe classique avec un
	 * champ tva_intra, et llx_facture_fourn.fk_soc en clé étrangère standard.
	 *
	 * @param DoliDB $db
	 * @param string $supplierVat
	 * @return array Candidats au format attendu par match()
	 */
	public function findCandidates($db, $supplierVat)
	{
		$candidates = array();

		$sql = "SELECT f.rowid as id, f.ref_supplier, f.total_ttc as amount_ttc, f.datef as date, s.tva_intra as supplier_vat";
		$sql .= " FROM ".MAIN_DB_PREFIX."facture_fourn as f";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."societe as s ON s.rowid = f.fk_soc";
		$sql .= " WHERE s.tva_intra = '".$db->escape($supplierVat)."'";
		$sql .= " AND f.rowid NOT IN (";
		$sql .= "   SELECT matched_object_id FROM ".MAIN_DB_PREFIX."facturation_electronique_staging";
		$sql .= "   WHERE matched_object_type = 'invoice_supplier' AND matched_object_id IS NOT NULL";
		$sql .= "   AND match_status = '".FacturationElectroniqueStaging::STATUS_VALIDATED."'";
		$sql .= " )";

		$resql = $db->query($sql);
		if (!$resql) {
			return $candidates;
		}

		while ($obj = $db->fetch_object($resql)) {
			$candidates[] = array(
				'id' => (int) $obj->id,
				'ref_supplier' => $obj->ref_supplier,
				'supplier_vat' => $obj->supplier_vat,
				'amount_ttc' => $obj->amount_ttc !== null ? (float) $obj->amount_ttc : null,
				'date' => $obj->date,
			);
		}

		return $candidates;
	}
}
