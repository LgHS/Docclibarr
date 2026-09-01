<?php
/* Copyright (C) 2026 iooner.io for Liège Hackerspace
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */
/**
 * Bootstrap PHPUnit : charge uniquement les classes "couche 1" (voir SPEC.md section 14),
 * sans dépendance à Dolibarr ni à l'API Gmail, testables entièrement sur fixtures
 * statiques.
 */

require_once __DIR__.'/../class/originverifier.class.php';
require_once __DIR__.'/../class/ublinvoiceparser.class.php';
require_once __DIR__.'/../class/mimeattachmentextractor.class.php';
