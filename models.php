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
        SELECT m.*, f.name as family_name,
               (m.options_count + m.colors_ext_count + m.colors_int_count) as total_count
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
            theme: { 
                extend: { 
                    colors: { 
                        'porsche-red': '#d5001c',
                        'porsche-gray': '#f2f2f2',
                        'porsche-border': '#e0e0e0'
                    } 
                } 
            }
        }
    </script>
    <style>
        body { font-family: 'PorscheNext', 'Segoe UI', Arial, sans-serif; }
    </style>
</head>
<body class="bg-white text-black min-h-screen">
    <!-- Header -->
    <header class="bg-white border-b border-porsche-border sticky top-0 z-50">
        <div class="max-w-7xl mx-auto px-6 py-4 flex items-center justify-between">
            <div class="flex items-center gap-4">
                <svg class="w-10 h-10" viewBox="0 0 100 100">
                    <circle cx="50" cy="50" r="48" fill="#d5001c"/>
                    <text x="50" y="62" text-anchor="middle" fill="white" font-size="32" font-weight="bold">P</text>
                </svg>
                <div>
                    <h1 class="text-xl font-bold text-black">Porsche Options Manager</h1>
                    <p class="text-gray-500 text-sm">Gestion des modèles Porsche</p>
                </div>
            </div>
            <nav class="flex items-center gap-6 text-sm">
                <a href="index.php" class="text-gray-600 hover:text-black transition">Dashboard</a>
                <a href="models.php" class="text-black font-medium">Modèles</a>
                <a href="options.php" class="text-gray-600 hover:text-black transition">Options</a>
                <a href="extraction.php" class="bg-porsche-red hover:bg-red-700 text-white px-4 py-2 rounded transition">
                    Extraction
                </a>
            </nav>
        </div>
    </header>

    <main class="max-w-7xl mx-auto px-6 py-8">
        <div class="flex items-center justify-between mb-6">
            <h2 class="text-2xl font-bold">Modèles (<?= count($models) ?>)</h2>
        </div>

        <!-- Filtres -->
        <form method="GET" class="border border-porsche-border rounded-lg p-4 mb-6">
            <div class="flex flex-wrap gap-4">
                <div class="flex-1 min-w-[200px]">
                    <label class="block text-xs text-gray-500 uppercase tracking-wide mb-1">Rechercher</label>
                    <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" 
                           placeholder="Nom ou code..."
                           class="w-full border border-porsche-border rounded px-4 py-2 focus:outline-none focus:border-black focus:ring-1 focus:ring-black">
                </div>
                <div class="w-48">
                    <label class="block text-xs text-gray-500 uppercase tracking-wide mb-1">Famille</label>
                    <select name="family" class="w-full border border-porsche-border rounded px-4 py-2 focus:outline-none focus:border-black">
                        <option value="">Toutes</option>
                        <?php foreach ($families as $f): ?>
                        <option value="<?= $f['id'] ?>" <?= $familyId == $f['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($f['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="flex items-end gap-2">
                    <button type="submit" class="bg-black hover:bg-gray-800 text-white px-6 py-2 rounded transition">
                        Filtrer
                    </button>
                    <a href="models.php" class="border border-porsche-border hover:bg-gray-50 px-4 py-2 rounded transition">
                        Reset
                    </a>
                </div>
            </div>
        </form>

        <!-- Liste des modèles -->
        <?php if (empty($models)): ?>
            <div class="border border-porsche-border rounded-lg p-12 text-center">
                <p class="text-gray-500 text-lg">Aucun modèle trouvé</p>
                <a href="extraction.php" class="inline-block mt-4 bg-porsche-red hover:bg-red-700 text-white px-6 py-3 rounded transition">
                    Lancer une extraction
                </a>
            </div>
        <?php else: ?>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                <?php foreach ($models as $model): ?>
                <a href="model-detail.php?code=<?= urlencode($model['code']) ?>" 
                   class="border border-porsche-border rounded-lg p-5 hover:shadow-lg hover:border-gray-400 transition group">
                    <div class="flex justify-between items-start mb-3">
                        <div>
                            <span class="text-xs text-gray-400 font-mono"><?= htmlspecialchars($model['code']) ?></span>
                            <h3 class="font-bold text-lg group-hover:text-porsche-red transition">
                                <?= htmlspecialchars($model['name']) ?>
                            </h3>
                        </div>
                        <span class="bg-porsche-gray text-xs px-2 py-1 rounded">
                            <?= htmlspecialchars($model['family_name'] ?? 'N/A') ?>
                        </span>
                    </div>
                    <div class="flex justify-between items-center text-sm">
                        <span class="text-gray-500">
                            <?= $model['base_price'] ? formatPrice($model['base_price']) : '-' ?>
                        </span>
                        <span class="font-medium">
                            <?= $model['total_count'] ?? 0 ?> options
                        </span>
                    </div>
                    <?php if ($model['last_updated']): ?>
                    <p class="text-xs text-gray-400 mt-2">
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