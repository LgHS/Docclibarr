#!/usr/bin/env bash
# Copyright (C) 2026 iooner.io for Liège Hackerspace
#
# Construit un zip déployable du module Docclibarr : dossier racine "docclibarr" (doit
# être exactement ce nom, voir le gotcha de nommage documenté dans SPEC.md et déjà
# rencontré sur DoliFius), sans les fichiers de développement (tests/, phpunit.xml.dist,
# SPEC.md, .git), avec les dépendances de production installées (--no-dev) et
# google/apiclient-services élagué pour ne garder que le service Gmail : la librairie
# embarque par défaut les définitions de ~334 API Google, dont une seule nous sert.
#
# Usage : scripts/build-zip.sh
# Résultat : build/docclibarr-vX.Y.Z.zip (X.Y.Z = version dans core/modules/modDocclibarr.class.php)

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BUILD_DIR="$REPO_ROOT/build"
STAGING_DIR="$BUILD_DIR/docclibarr"

VERSION=$(grep -m1 "this->version" "$REPO_ROOT/core/modules/modDocclibarr.class.php" | sed -E "s/.*'([^']+)'.*/\1/")
# Dolibarr attend exactement "modulename-x[.y.z].zip" à l'upload, pas de préfixe "v"
# devant le numéro de version (erreur déjà rencontrée en le nommant docclibarr-v0.1.0.zip).
ZIP_NAME="docclibarr-${VERSION}.zip"

echo "Version détectée : ${VERSION}"

rm -rf "$BUILD_DIR"
mkdir -p "$STAGING_DIR"

echo "Copie des fichiers du module (hors dev)..."
rsync -a \
	--exclude='.git' \
	--exclude='SPEC.md' \
	--exclude='NOTES_*.md' \
	--exclude='tests' \
	--exclude='phpunit.xml.dist' \
	--exclude='vendor' \
	--exclude='composer.lock' \
	--exclude='.phpunit.result.cache' \
	--exclude='.gitignore' \
	--exclude='*.code-workspace' \
	--exclude='.DS_Store' \
	--exclude='build' \
	--exclude='scripts' \
	"$REPO_ROOT"/ "$STAGING_DIR"/

echo "Installation des dépendances de production..."
(cd "$STAGING_DIR" && composer install --no-dev --optimize-autoloader --no-interaction --no-progress --quiet)

echo "Élagage de google/apiclient-services (garde uniquement Gmail)..."
GAPI_SRC="$STAGING_DIR/vendor/google/apiclient-services/src"
if [ -d "$GAPI_SRC" ]; then
	find "$GAPI_SRC" -mindepth 1 -maxdepth 1 ! -name 'Gmail.php' ! -name 'Gmail' -exec rm -rf {} +
fi

echo "Création de l'archive..."
(cd "$BUILD_DIR" && zip -rq "$ZIP_NAME" docclibarr -x '*.DS_Store')

echo ""
echo "Terminé : build/${ZIP_NAME}"
du -h "$BUILD_DIR/$ZIP_NAME"
