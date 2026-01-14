<?php


require_once 'config.php';

$db = getDb();
$message = '';
$messageType = '';

// Récupérer les modèles pour le select
$models = $db->query("SELECT id, code, name FROM p_models ORDER BY name")->fetchAll();

// Récupérer les catégories existantes
$categories = $db->query("SELECT DISTINCT name FROM p_categories ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);

// Récupérer les sous-catégories existantes
$subCategories = $db->query("SELECT DISTINCT sub_category FROM p_options WHERE sub_category IS NOT NULL ORDER BY sub_category")->fetchAll(PDO::FETCH_COLUMN);

// Mode édition ?
$editOption = null;
if (isset($_GET['edit']) && isset($_GET['model_id'])) {
    $stmt = $db->prepare("SELECT * FROM p_options WHERE code = ? AND model_id = ?");
    $stmt->execute([$_GET['edit'], $_GET['model_id']]);
    $editOption = $stmt->fetch();
}

// Traitement du formulaire
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $modelId = $_POST['model_id'];
        $code = trim($_POST['code']);
        $name = trim($_POST['name']);
        $nameDe = trim($_POST['name_de']) ?: null;
        $price = $_POST['price'] !== '' ? floatval($_POST['price']) : null;
        $isStandard = isset($_POST['is_standard']) ? 1 : 0;
        $isExclusive = isset($_POST['is_exclusive_manufaktur']) ? 1 : 0;
        $optionType = $_POST['option_type'];
        $category = trim($_POST['category']) ?: trim($_POST['category_new']) ?: null;
        $subCategory = trim($_POST['sub_category']) ?: trim($_POST['sub_category_new']) ?: null;
        $imageUrl = trim($_POST['image_url']) ?: null;
        $description = trim($_POST['description']) ?: null;
        
        if (empty($code) || empty($name) || empty($modelId)) {
            throw new Exception("Code, Nom et Modèle sont obligatoires");
        }
        
        // Créer/récupérer la catégorie
        $categoryId = null;
        if ($category) {
            $stmt = $db->prepare("SELECT id FROM p_categories WHERE name = ?");
            $stmt->execute([$category]);
            $cat = $stmt->fetch();
            if ($cat) {
                $categoryId = $cat['id'];
            } else {
                $stmt = $db->prepare("INSERT INTO p_categories (name, slug) VALUES (?, ?)");
                $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $category));
                $stmt->execute([$category, $slug]);
                $categoryId = $db->lastInsertId();
            }
        }
        
        // Insert ou Update
        if (isset($_POST['original_code']) && isset($_POST['original_model_id'])) {
            // Mode édition - supprimer l'ancien puis insérer
            $stmt = $db->prepare("DELETE FROM p_options WHERE code = ? AND model_id = ?");
            $stmt->execute([$_POST['original_code'], $_POST['original_model_id']]);
        }
        
        $stmt = $db->prepare("
            INSERT INTO p_options (model_id, category_id, code, name, name_de, description, price, is_standard, is_exclusive_manufaktur, option_type, sub_category, image_url)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                name_de = VALUES(name_de),
                description = VALUES(description),
                price = VALUES(price),
                is_standard = VALUES(is_standard),
                is_exclusive_manufaktur = VALUES(is_exclusive_manufaktur),
                option_type = VALUES(option_type),
                category_id = VALUES(category_id),
                sub_category = VALUES(sub_category),
                image_url = VALUES(image_url)
        ");
        
        $stmt->execute([
            $modelId, $categoryId, $code, $name, $nameDe, $description,
            $price, $isStandard, $isExclusive, $optionType, $subCategory, $imageUrl
        ]);
        
        $message = $editOption ? "Option '$code' mise à jour avec succès !" : "Option '$code' ajoutée avec succès !";
        $messageType = 'success';
        
        // Rediriger vers la page du modèle si spécifié
        if (isset($_POST['redirect_to_model']) && $_POST['redirect_to_model']) {
            header("Location: model-detail.php?code=" . urlencode($_POST['redirect_model_code']));
            exit;
        }
        
        // Reset pour nouveau formulaire
        $editOption = null;
        
    } catch (Exception $e) {
        $message = "Erreur: " . $e->getMessage();
        $messageType = 'error';
    }
}

// Suppression
if (isset($_GET['delete']) && isset($_GET['model_id'])) {
    try {
        $stmt = $db->prepare("DELETE FROM p_options WHERE code = ? AND model_id = ?");
        $stmt->execute([$_GET['delete'], $_GET['model_id']]);
        $message = "Option '{$_GET['delete']}' supprimée avec succès !";
        $messageType = 'success';
    } catch (Exception $e) {
        $message = "Erreur: " . $e->getMessage();
        $messageType = 'error';
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $editOption ? 'Modifier' : 'Ajouter' ?> une option - Porsche Options Manager</title>
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
</head>
<body class="bg-white text-gray-900">
    <header class="border-b border-porsche-border sticky top-0 bg-white z-50">
        <div class="max-w-7xl mx-auto px-6 py-4 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <svg viewBox="0 0 100 100" class="w-10 h-10">
                    <circle cx="50" cy="50" r="48" fill="#d5001c"/>
                    <text x="50" y="62" text-anchor="middle" fill="white" font-size="32" font-weight="bold">P</text>
                </svg>
                <div>
                    <h1 class="text-xl font-bold text-black">Porsche Options Manager</h1>
                    <p class="text-gray-500 text-sm">Ajout d'options</p>
                </div>
            </div>
             <nav class="flex items-center gap-6 text-sm">
                <a href="index.php" class="text-gray-600 hover:text-black transition">Dashboard</a>
                <a href="models.php" class="text-gray-600 hover:text-black transition">Modèles</a>
                <a href="options.php" class="text-gray-600 hover:text-black transition">Options</a>
                <a href="option-edit.php" class="text-gray-600 hover:text-black transition">+ Option</a>
                <a href="stats.php" class="text-gray-600 hover:text-black transition">Stats</a>
                <a href="extraction.php" class="bg-porsche-red hover:bg-red-700 text-white px-4 py-2 rounded transition">
                    Extraction
                </a>
            </nav>
        </div>
    </header>

    <main class="max-w-4xl mx-auto px-6 py-8">
        <div class="flex items-center justify-between mb-6">
            <h2 class="text-2xl font-bold"><?= $editOption ? '✏️ Modifier' : '➕ Ajouter' ?> une option</h2>
            <?php if (isset($_GET['model_code'])): ?>
                <a href="model-detail.php?code=<?= htmlspecialchars($_GET['model_code']) ?>" class="text-porsche-red hover:underline">
                    ← Retour au modèle
                </a>
            <?php endif; ?>
        </div>
        
        <?php if ($message): ?>
            <div class="mb-6 p-4 rounded-lg <?= $messageType === 'success' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>
        
        <form method="POST" class="bg-porsche-gray rounded-lg p-6 space-y-6">
            <?php if ($editOption): ?>
                <input type="hidden" name="original_code" value="<?= htmlspecialchars($editOption['code']) ?>">
                <input type="hidden" name="original_model_id" value="<?= htmlspecialchars($editOption['model_id']) ?>">
            <?php endif; ?>
            
            <?php if (isset($_GET['model_code'])): ?>
                <input type="hidden" name="redirect_to_model" value="1">
                <input type="hidden" name="redirect_model_code" value="<?= htmlspecialchars($_GET['model_code']) ?>">
            <?php endif; ?>
            
            <!-- Modèle -->
            <div>
                <label class="block text-sm font-medium mb-2">Modèle *</label>
                <select name="model_id" required class="w-full px-4 py-2 border border-porsche-border rounded-lg focus:ring-2 focus:ring-porsche-red focus:border-transparent">
                    <option value="">-- Sélectionner un modèle --</option>
                    <?php foreach ($models as $model): ?>
                        <option value="<?= $model['id'] ?>" <?= ($editOption && $editOption['model_id'] == $model['id']) || (isset($_GET['model_id']) && $_GET['model_id'] == $model['id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($model['name']) ?> (<?= htmlspecialchars($model['code']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <!-- Code et Nom -->
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium mb-2">Code option *</label>
                    <input type="text" name="code" required
                           value="<?= htmlspecialchars($editOption['code'] ?? '') ?>"
                           placeholder="Ex: XYZ, 123, P11..."
                           class="w-full px-4 py-2 border border-porsche-border rounded-lg focus:ring-2 focus:ring-porsche-red">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-2">Type d'option *</label>
                    <select name="option_type" required class="w-full px-4 py-2 border border-porsche-border rounded-lg focus:ring-2 focus:ring-porsche-red">
                        <?php 
                        $types = ['option' => '⚙️ Option générale', 'color_ext' => '🎨 Couleur extérieure', 'color_int' => '🛋️ Couleur intérieure', 'wheel' => '🛞 Jante', 'seat' => '💺 Siège', 'pack' => '📦 Pack', 'hood' => '🏠 Capote'];
                        foreach ($types as $val => $label): 
                        ?>
                            <option value="<?= $val ?>" <?= ($editOption && $editOption['option_type'] === $val) ? 'selected' : '' ?>>
                                <?= $label ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <!-- Nom FR et DE -->
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium mb-2">Nom (FR) *</label>
                    <input type="text" name="name" required
                           value="<?= htmlspecialchars($editOption['name'] ?? '') ?>"
                           placeholder="Nom de l'option en français"
                           class="w-full px-4 py-2 border border-porsche-border rounded-lg focus:ring-2 focus:ring-porsche-red">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-2">Nom (DE)</label>
                    <input type="text" name="name_de"
                           value="<?= htmlspecialchars($editOption['name_de'] ?? '') ?>"
                           placeholder="Nom en allemand (optionnel)"
                           class="w-full px-4 py-2 border border-porsche-border rounded-lg focus:ring-2 focus:ring-porsche-red">
                </div>
            </div>
            
            <!-- Prix et statut -->
            <div class="grid grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm font-medium mb-2">Prix (€)</label>
                    <input type="number" name="price" step="0.01" min="0"
                           value="<?= $editOption ? htmlspecialchars($editOption['price']) : '' ?>"
                           placeholder="0.00"
                           class="w-full px-4 py-2 border border-porsche-border rounded-lg focus:ring-2 focus:ring-porsche-red">
                </div>
                <div class="flex items-center gap-4 pt-6">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" name="is_standard" value="1" 
                               <?= ($editOption && $editOption['is_standard']) ? 'checked' : '' ?>
                               class="w-4 h-4 text-porsche-red rounded">
                        <span class="text-sm">De série</span>
                    </label>
                </div>
                <div class="flex items-center gap-4 pt-6">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" name="is_exclusive_manufaktur" value="1"
                               <?= ($editOption && $editOption['is_exclusive_manufaktur']) ? 'checked' : '' ?>
                               class="w-4 h-4 text-porsche-red rounded">
                        <span class="text-sm">Exclusive Manufaktur</span>
                    </label>
                </div>
            </div>
            
            <!-- Catégorie -->
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium mb-2">Catégorie (existante)</label>
                    <select name="category" class="w-full px-4 py-2 border border-porsche-border rounded-lg focus:ring-2 focus:ring-porsche-red">
                        <option value="">-- Choisir ou créer --</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium mb-2">Ou nouvelle catégorie</label>
                    <input type="text" name="category_new"
                           placeholder="Créer une nouvelle catégorie"
                           class="w-full px-4 py-2 border border-porsche-border rounded-lg focus:ring-2 focus:ring-porsche-red">
                </div>
            </div>
            
            <!-- Sous-catégorie -->
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium mb-2">Sous-catégorie (existante)</label>
                    <select name="sub_category" class="w-full px-4 py-2 border border-porsche-border rounded-lg focus:ring-2 focus:ring-porsche-red">
                        <option value="">-- Choisir ou créer --</option>
                        <?php foreach ($subCategories as $subCat): ?>
                            <option value="<?= htmlspecialchars($subCat) ?>"><?= htmlspecialchars($subCat) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium mb-2">Ou nouvelle sous-catégorie</label>
                    <input type="text" name="sub_category_new"
                           placeholder="Créer une nouvelle sous-catégorie"
                           class="w-full px-4 py-2 border border-porsche-border rounded-lg focus:ring-2 focus:ring-porsche-red">
                </div>
            </div>
            
            <!-- Image URL -->
            <div>
                <label class="block text-sm font-medium mb-2">URL de l'image</label>
                <input type="url" name="image_url"
                       value="<?= htmlspecialchars($editOption['image_url'] ?? '') ?>"
                       placeholder="https://..."
                       class="w-full px-4 py-2 border border-porsche-border rounded-lg focus:ring-2 focus:ring-porsche-red">
            </div>
            
            <!-- Description -->
            <div>
                <label class="block text-sm font-medium mb-2">Description</label>
                <textarea name="description" rows="3"
                          placeholder="Description détaillée de l'option..."
                          class="w-full px-4 py-2 border border-porsche-border rounded-lg focus:ring-2 focus:ring-porsche-red"><?= htmlspecialchars($editOption['description'] ?? '') ?></textarea>
            </div>
            
            <!-- Boutons -->
            <div class="flex items-center gap-4 pt-4">
                <button type="submit" class="bg-porsche-red hover:bg-red-700 text-white px-6 py-2 rounded-lg font-medium transition">
                    <?= $editOption ? '💾 Mettre à jour' : '➕ Ajouter l\'option' ?>
                </button>
                <?php if ($editOption): ?>
                    <a href="option-edit.php" class="text-gray-600 hover:text-black">Annuler</a>
                <?php endif; ?>
            </div>
        </form>
        
        <!-- Liste des dernières options ajoutées manuellement -->
        <div class="mt-12">
            <h3 class="text-xl font-bold mb-4">📋 Options récentes</h3>
            <?php
            $recentOptions = $db->query("
                SELECT o.*, m.name as model_name, m.code as model_code
                FROM p_options o
                JOIN p_models m ON o.model_id = m.id
                ORDER BY o.id DESC
                LIMIT 10
            ")->fetchAll();
            ?>
            
            <?php if (empty($recentOptions)): ?>
                <p class="text-gray-500">Aucune option en base de données.</p>
            <?php else: ?>
                <div class="bg-white border border-porsche-border rounded-lg overflow-hidden">
                    <table class="w-full">
                        <thead class="bg-porsche-gray">
                            <tr>
                                <th class="px-4 py-3 text-left text-sm font-medium">Code</th>
                                <th class="px-4 py-3 text-left text-sm font-medium">Nom</th>
                                <th class="px-4 py-3 text-left text-sm font-medium">Type</th>
                                <th class="px-4 py-3 text-left text-sm font-medium">Prix</th>
                                <th class="px-4 py-3 text-left text-sm font-medium">Modèle</th>
                                <th class="px-4 py-3 text-center text-sm font-medium">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-porsche-border">
                            <?php foreach ($recentOptions as $opt): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-3 font-mono text-sm"><?= htmlspecialchars($opt['code']) ?></td>
                                    <td class="px-4 py-3 text-sm"><?= htmlspecialchars(mb_substr($opt['name'], 0, 40)) ?>...</td>
                                    <td class="px-4 py-3 text-sm">
                                        <?php
                                        $typeIcons = ['color_ext' => '🎨', 'color_int' => '🛋️', 'wheel' => '🛞', 'seat' => '💺', 'pack' => '📦', 'hood' => '🏠', 'option' => '⚙️'];
                                        echo ($typeIcons[$opt['option_type']] ?? '⚙️') . ' ' . $opt['option_type'];
                                        ?>
                                    </td>
                                    <td class="px-4 py-3 text-sm">
                                        <?= $opt['is_standard'] ? '<span class="text-green-600">Série</span>' : ($opt['price'] ? number_format($opt['price'], 0, ',', ' ') . ' €' : '-') ?>
                                    </td>
                                    <td class="px-4 py-3 text-sm text-gray-500"><?= htmlspecialchars($opt['model_name']) ?></td>
                                    <td class="px-4 py-3 text-center">
                                        <a href="?edit=<?= urlencode($opt['code']) ?>&model_id=<?= $opt['model_id'] ?>" 
                                           class="text-blue-600 hover:text-blue-800 text-sm mr-3">✏️ Modifier</a>
                                        <a href="?delete=<?= urlencode($opt['code']) ?>&model_id=<?= $opt['model_id'] ?>" 
                                           onclick="return confirm('Supprimer cette option ?')"
                                           class="text-red-600 hover:text-red-800 text-sm">🗑️ Suppr</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </main>
</body>
</html>