<?php
// index.php

// 1) Charger le dataset complet (sparse) : $units et $ABILITY_FLAGS
require __DIR__ . '/battle_cats_units_dataset_sparse.php';

// 2) Fichier "base de données light"
$dataFile = __DIR__ . '/user_cats.json';

// Charger la liste des IDs de chats possédés
$ownedIds = [];
if (file_exists($dataFile)) {
    $json = file_get_contents($dataFile);
    $decoded = json_decode($json, true);
    if (is_array($decoded) && isset($decoded['ownedIds']) && is_array($decoded['ownedIds'])) {
        // On garde uniquement des entiers valides
        foreach ($decoded['ownedIds'] as $id) {
            if (is_int($id)) {
                $ownedIds[] = $id;
            }
        }
    }
}

// 3) Gestion de la sauvegarde AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // On attend du JSON : { ownedIds: [list de IDs] }
    $input = file_get_contents('php://input');
    $payload = json_decode($input, true);

    if (is_array($payload) && isset($payload['ownedIds']) && is_array($payload['ownedIds'])) {
        $clean = [];
        foreach ($payload['ownedIds'] as $id) {
            if (is_int($id)) {
                $clean[] = $id;
            }
        }
        file_put_contents($dataFile, json_encode(['ownedIds' => $clean], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'count' => count($clean)]);
        exit;
    }

    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'payload invalide']);
    exit;
}

// 4) Ordre des abilities détaillées (drapeaux)
$ABILITY_FLAGS = $ABILITY_FLAGS ?? [];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Mon Set de Chats – Battle Cats Helper</title>
    <link rel="stylesheet" href="style.css">
	
	<link rel="icon" type="image/png" href="favicon/favicon-96x96.png" sizes="96x96" />
	<link rel="icon" type="image/svg+xml" href="favicon/favicon.svg" />
	<link rel="shortcut icon" href="favicon/favicon.ico" />
	<link rel="apple-touch-icon" sizes="180x180" href="favicon/apple-touch-icon.png" />
	<meta name="apple-mobile-web-app-title" content="MyCats" />
	<link rel="manifest" href="favicon/site.webmanifest" />
	
</head>
<body>

<main>
	<section class="panel">
		
		<div class="flex">
			<div class="flex-main">
				<label for="searchInput">Ajouter un personnage (auto-complétion)</label><br>
				<input type="text" id="searchInput" placeholder="Tape le nom / rareté / type d’ennemi…">
				<span id="saveStatus" class="status"></span>
			</div>

			<div class="top10-panel">
				<div class="top10-title">Top 10 de tes unités</div>
				<div id="top10List" class="top10-grid">
					<!-- Rempli par le JS -->
				</div>
			</div>
		</div>

	</section>


    <section class="panel">
        <div id="tableContainer">
            <!-- tableau généré en JS -->
        </div>
       
    </section>

    <section class="panel">
        <button id="exportBtn" class="secondary">Générer le texte à donner à l’IA</button>

        <div id="exportArea" style="margin-top:1rem; display:none;">
            <textarea id="exportText" readonly></textarea>
            <div class="export-actions">
                <button id="copyExportBtn" class="primary">Copier</button>
                <button id="downloadExportBtn" class="secondary">Télécharger (.txt)</button>
            </div>
        </div>

        <p class="muted">
            Le texte inclura ton set, avec les stats et abilities, dans un format pratique pour expliquer ton compte à une IA.
        </p>
    </section>
</main>

<script>
// --- Données PHP vers JS ---
const ALL_UNITS = <?php echo json_encode($units, JSON_UNESCAPED_UNICODE); ?>;
let ownedIds = <?php echo json_encode($ownedIds, JSON_UNESCAPED_UNICODE); ?>;
const ABILITY_FLAGS = <?php echo json_encode($ABILITY_FLAGS, JSON_UNESCAPED_UNICODE); ?>;
</script>
<script src="app.js"></script>
</body>
</html>
