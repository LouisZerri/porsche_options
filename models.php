<?php
/**
 * PORSCHE OPTIONS MANAGER - Liste des modèles
 */
require_once 'config.php';

$db = getDB();

// Filtres
$familyId = $_GET['family'] ?? null;
$search = $_GET['search'] ?? '';

$families = [];
$models = [];

try {
    // Familles pour le filtre
    $families = $db->query("SELECT * FROM p_families ORDER BY name")->fetchAll();

    // Construire la requête
    $sql = "
        SELECT m.*, f.name as family_name
        FROM p_models m
        LEFT JOIN p_families f ON m.family_id = f.id
        WHERE 1=1
    ";
    $params = [];

    if ($familyId) {
        $sql .= " AND m.family_id = ?";
        $params[] = $familyId;
    }

    if ($search) {
        $sql .= " AND (m.name LIKE ? OR m.code LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    $sql .= " ORDER BY f.name, m.name";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $models = $stmt->fetchAll();
} catch (PDOException $e) {
    // Tables n'existent pas encore
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Modèles - Porsche Options Manager</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: { extend: { colors: { 'porsche-red': '#d5001c' } } }
        }
    </script>
</head>
<body class="bg-gray-900 text-white min-h-screen">
    <!-- Header -->
    <header class="bg-black border-b border-gray-800 sticky top-0 z-50">
        <div class="max-w-7xl mx-auto px-4 py-4 flex items-center justify-between">
            <div class="flex items-center gap-4">
                <div class="w-10 h-10 bg-porsche-red rounded-full flex items-center justify-center font-bold text-xl">P</div>
                <div>
                    <h1 class="text-xl font-bold">Porsche Options Manager</h1>
                    <p class="text-gray-400 text-sm">Gestion des options du configurateur</p>
                </div>
            </div>
            <nav class="flex items-center gap-4">
                <a href="index.php" class="text-gray-400 hover:text-white transition">Dashboard</a>
                <a href="models.php" class="text-white hover:text-porsche-red transition">Modèles</a>
                <a href="options.php" class="text-gray-400 hover:text-white transition">Options</a>
                <a href="extraction.php" class="bg-porsche-red hover:bg-red-700 px-4 py-2 rounded-lg transition">
                    🚀 Extraction
                </a>
            </nav>
        </div>
    </header>

    <main class="max-w-7xl mx-auto px-4 py-8">
        <div class="flex items-center justify-between mb-6">
            <h2 class="text-2xl font-bold">Modèles (<?= count($models) ?>)</h2>
        </div>

        <!-- Filtres -->
        <form method="GET" class="bg-gray-800 rounded-xl border border-gray-700 p-4 mb-6">
            <div class="flex flex-wrap gap-4">
                <div class="flex-1 min-w-[200px]">
                    <label class="block text-sm text-gray-400 mb-1">Rechercher</label>
                    <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" 
                           placeholder="Nom ou code..."
                           class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 focus:outline-none focus:border-porsche-red">
                </div>
                <div class="w-48">
                    <label class="block text-sm text-gray-400 mb-1">Famille</label>
                    <select name="family" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 focus:outline-none focus:border-porsche-red">
                        <option value="">Toutes</option>
                        <?php foreach ($families as $f): ?>
                        <option value="<?= $f['id'] ?>" <?= $familyId == $f['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($f['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="flex items-end gap-2">
                    <button type="submit" class="bg-porsche-red hover:bg-red-700 px-6 py-2 rounded-lg transition">
                        Filtrer
                    </button>
                    <a href="models.php" class="bg-gray-700 hover:bg-gray-600 px-4 py-2 rounded-lg transition">
                        Reset
                    </a>
                </div>
            </div>
        </form>

        <!-- Liste des modèles -->
        <?php if (empty($models)): ?>
            <div class="bg-gray-800 rounded-xl border border-gray-700 p-12 text-center">
                <p class="text-gray-500 text-lg">Aucun modèle trouvé</p>
                <a href="extraction.php" class="inline-block mt-4 bg-porsche-red hover:bg-red-700 px-6 py-3 rounded-lg transition">
                    Lancer une extraction
                </a>
            </div>
        <?php else: ?>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                <?php foreach ($models as $model): ?>
                <a href="model-detail.php?code=<?= urlencode($model['code']) ?>" 
                   class="bg-gray-800 rounded-xl border border-gray-700 p-5 hover:border-porsche-red transition group">
                    <div class="flex justify-between items-start mb-3">
                        <div>
                            <span class="text-xs text-gray-500 font-mono"><?= htmlspecialchars($model['code']) ?></span>
                            <h3 class="font-semibold text-lg group-hover:text-porsche-red transition">
                                <?= htmlspecialchars($model['name']) ?>
                            </h3>
                        </div>
                        <span class="bg-gray-700 text-xs px-2 py-1 rounded">
                            <?= htmlspecialchars($model['family_name'] ?? 'N/A') ?>
                        </span>
                    </div>
                    <div class="flex justify-between items-center text-sm">
                        <span class="text-gray-400">
                            <?= $model['base_price'] ? formatPrice($model['base_price']) : '-' ?>
                        </span>
                        <span class="text-blue-400 font-medium">
                            <?= $model['options_count'] ?? 0 ?> options
                        </span>
                    </div>
                    <?php if ($model['last_updated']): ?>
                    <p class="text-xs text-gray-600 mt-2">
                        Mis à jour: <?= date('d/m/Y H:i', strtotime($model['last_updated'])) ?>
                    </p>
                    <?php endif; ?>
                </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </main>
</body>
</html>