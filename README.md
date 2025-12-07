# 🚗 Porsche Options Manager

Interface web PHP + extracteur Node.js pour gérer les options du configurateur Porsche.

## 📁 Structure

```
php-interface/
├── index.php              # Dashboard
├── models.php             # Liste des modèles
├── model-detail.php       # Détail d'un modèle + options
├── options.php            # Recherche d'options
├── export.php             # Export CSV
├── extraction.php         # Lancement des extractions
├── config.php             # Configuration BDD
└── extractor/
    ├── porsche_extractor_mysql.js
    └── package.json
```

## 🔧 Installation locale (pour tester)

### 1. Prérequis
- PHP 8.x avec PDO MySQL
- MySQL/MariaDB
- Node.js 18+ 
- Serveur web (Apache/Nginx) ou `php -S`

### 2. Base de données
```sql
CREATE DATABASE porsche_options CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

### 3. Configurer config.php
```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'porsche_options');
define('DB_USER', 'root');
define('DB_PASS', '');
```

### 4. Installer l'extracteur
```bash
cd php-interface/extractor
npm install
npx playwright install chromium
```

### 5. Initialiser les tables MySQL
```bash
node porsche_extractor_mysql.js --init
```

### 6. Lancer le serveur PHP
```bash
cd php-interface
php -S localhost:8000
```

### 7. Ouvrir l'interface
→ http://localhost:8000

---

## 🚀 Commandes d'extraction

```bash
# Se placer dans le dossier extractor
cd php-interface/extractor

# Extraire UN modèle (test rapide ~30s)
node porsche_extractor_mysql.js --model 982850

# Extraire TOUS les modèles (~20-30 min)
node porsche_extractor_mysql.js

# Voir les stats
node porsche_extractor_mysql.js --stats

# Lister les modèles configurés
node porsche_extractor_mysql.js --list
```

---

## 📋 Pour le client (O2Switch)

### Installation SSH sur O2Switch

```bash
# 1. Se connecter en SSH
ssh username@serveur.o2switch.net

# 2. Installer Node.js via nvm
curl -o- https://raw.githubusercontent.com/nvm-sh/nvm/v0.39.0/install.sh | bash
source ~/.bashrc
nvm install 20
nvm use 20

# 3. Aller dans le dossier de l'extracteur
cd ~/public_html/porsche-manager/extractor

# 4. Installer les dépendances
npm install
npx playwright install chromium

# 5. Initialiser la BDD (une seule fois)
node porsche_extractor_mysql.js --init

# 6. Lancer une extraction
node porsche_extractor_mysql.js
```

### Mise à jour des données

Quand le client veut actualiser les données :

```bash
cd ~/public_html/porsche-manager/extractor
node porsche_extractor_mysql.js
```

---

## ⚠️ Notes importantes

1. **L'extraction ne purge PAS** les données existantes - elle met à jour
2. Pour purger : utiliser le bouton dans l'interface ou :
   ```sql
   TRUNCATE p_options;
   TRUNCATE p_models;
   TRUNCATE p_families;
   ```
3. **Durée** : ~30s par modèle, ~25 min pour tout

---

## 🗄️ Structure MySQL

```sql
-- Familles (718, 911, Taycan...)
SELECT * FROM p_families;

-- Modèles
SELECT * FROM p_models;

-- Options
SELECT * FROM p_options;

-- Vue complète
SELECT * FROM v_options_full;
```

### Requêtes utiles

```sql
-- Options les plus chères
SELECT m.name, o.code, o.name, o.price
FROM p_options o
JOIN p_models m ON o.model_id = m.id
WHERE o.price > 5000
ORDER BY o.price DESC;

-- Options communes à plusieurs modèles
SELECT code, name, COUNT(DISTINCT model_id) as nb
FROM p_options
GROUP BY code
HAVING nb > 5
ORDER BY nb DESC;
```
