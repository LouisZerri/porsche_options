/**
 * ╔══════════════════════════════════════════════════════════════════════════════╗
 * ║           PORSCHE OPTIONS EXTRACTOR v4.0 - MySQL                             ║
 * ╚══════════════════════════════════════════════════════════════════════════════╝
 * 
 * INSTALLATION:
 *   npm install playwright mysql2
 *   npm run setup   # Installe Chromium localement
 * 
 * USAGE:
 *   node porsche_extractor_mysql.js --init              # Créer les tables
 *   node porsche_extractor_mysql.js --model 982850      # Extraire un modèle
 *   node porsche_extractor_mysql.js --model A,B,C       # Extraire plusieurs modèles
 *   node porsche_extractor_mysql.js --visible           # Mode navigateur visible
 *   node porsche_extractor_mysql.js --list              # Lister les modèles en base
 *   node porsche_extractor_mysql.js --stats             # Statistiques
 *   node porsche_extractor_mysql.js --export            # Export CSV
 */

const mysql = require('mysql2/promise');
const fs = require('fs');
const path = require('path');

// Utiliser le dossier browsers local (pour portabilité)
process.env.PLAYWRIGHT_BROWSERS_PATH = path.join(__dirname, 'browsers');

const { chromium } = require('playwright');

// ═══════════════════════════════════════════════════════════════════════════════
// CONFIGURATION BASE DE DONNÉES
// ═══════════════════════════════════════════════════════════════════════════════

const DB_CONFIG = {
    host: process.env.DB_HOST || 'localhost',
    port: process.env.DB_PORT || 3306,
    user: process.env.DB_USER || 'root',
    password: process.env.DB_PASSWORD || 'root',
    database: process.env.DB_NAME || 'porsche_options',
    charset: 'utf8mb4',
};

// ═══════════════════════════════════════════════════════════════════════════════
// CONFIGURATION EXTRACTEUR
// ═══════════════════════════════════════════════════════════════════════════════

const CONFIG = {
    baseUrl: 'https://configurator.porsche.com',
    locale: 'fr-FR',
    exportDir: './exports',
    timeout: 60000,
    delayBetweenModels: 3000,
};

// ═══════════════════════════════════════════════════════════════════════════════
// CLASSE BASE DE DONNÉES MYSQL
// ═══════════════════════════════════════════════════════════════════════════════

class PorscheDB {
    constructor() {
        this.pool = null;
    }
    
    async connect() {
        const tempPool = mysql.createPool({
            host: DB_CONFIG.host,
            port: DB_CONFIG.port,
            user: DB_CONFIG.user,
            password: DB_CONFIG.password,
            charset: DB_CONFIG.charset,
        });
        
        await tempPool.query(`CREATE DATABASE IF NOT EXISTS \`${DB_CONFIG.database}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci`);
        await tempPool.end();
        
        this.pool = mysql.createPool({
            ...DB_CONFIG,
            waitForConnections: true,
            connectionLimit: 10,
            queueLimit: 0
        });
        
        console.log(`✅ Connecté à MySQL: ${DB_CONFIG.host}/${DB_CONFIG.database}`);
    }
    
    async initSchema() {
        console.log('📦 Création des tables...\n');
        
        await this.pool.query(`
            CREATE TABLE IF NOT EXISTS p_families (
                id INT AUTO_INCREMENT PRIMARY KEY,
                code VARCHAR(50) UNIQUE NOT NULL,
                name VARCHAR(100) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        `);
        
        await this.pool.query(`
            CREATE TABLE IF NOT EXISTS p_models (
                id INT AUTO_INCREMENT PRIMARY KEY,
                code VARCHAR(20) UNIQUE NOT NULL,
                name VARCHAR(100) NOT NULL,
                family_id INT,
                base_price DECIMAL(10,2),
                year INT,
                options_count INT DEFAULT 0,
                last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (family_id) REFERENCES p_families(id) ON DELETE SET NULL,
                INDEX idx_family (family_id),
                INDEX idx_code (code)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        `);
        
        await this.pool.query(`
            CREATE TABLE IF NOT EXISTS p_categories (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) UNIQUE NOT NULL,
                slug VARCHAR(100) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        `);
        
        await this.pool.query(`
            CREATE TABLE IF NOT EXISTS p_options (
                id INT AUTO_INCREMENT PRIMARY KEY,
                model_id INT NOT NULL,
                category_id INT,
                code VARCHAR(20) NOT NULL,
                name VARCHAR(255),
                description TEXT,
                price DECIMAL(10,2),
                is_standard BOOLEAN DEFAULT FALSE,
                image_url VARCHAR(500),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_model_option (model_id, code),
                FOREIGN KEY (model_id) REFERENCES p_models(id) ON DELETE CASCADE,
                FOREIGN KEY (category_id) REFERENCES p_categories(id) ON DELETE SET NULL,
                INDEX idx_model (model_id),
                INDEX idx_code (code),
                INDEX idx_category (category_id),
                INDEX idx_price (price)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        `);
        
        await this.pool.query(`
            CREATE TABLE IF NOT EXISTS p_price_history (
                id INT AUTO_INCREMENT PRIMARY KEY,
                option_id INT NOT NULL,
                old_price DECIMAL(10,2),
                new_price DECIMAL(10,2),
                changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (option_id) REFERENCES p_options(id) ON DELETE CASCADE,
                INDEX idx_option (option_id),
                INDEX idx_date (changed_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        `);
        
        await this.pool.query(`
            CREATE OR REPLACE VIEW v_options_full AS
            SELECT 
                o.id,
                o.code AS option_code,
                o.name AS option_name,
                o.price,
                o.is_standard,
                o.description,
                m.code AS model_code,
                m.name AS model_name,
                m.base_price,
                f.code AS family_code,
                f.name AS family_name,
                c.name AS category_name,
                o.updated_at
            FROM p_options o
            JOIN p_models m ON o.model_id = m.id
            LEFT JOIN p_families f ON m.family_id = f.id
            LEFT JOIN p_categories c ON o.category_id = c.id
        `);
        
        console.log('✅ Tables créées:');
        console.log('   - p_families (familles de modèles)');
        console.log('   - p_models (modèles)');
        console.log('   - p_categories (catégories d\'options)');
        console.log('   - p_options (options)');
        console.log('   - p_price_history (historique des prix)');
        console.log('   - v_options_full (vue complète)');
    }
    
    async getOrCreateFamily(code, name) {
        const [existing] = await this.pool.query('SELECT id FROM p_families WHERE code = ?', [code]);
        if (existing.length > 0) return existing[0].id;
        
        const [result] = await this.pool.query('INSERT INTO p_families (code, name) VALUES (?, ?)', [code, name]);
        return result.insertId;
    }
    
    async getOrCreateCategory(name) {
        if (!name) return null;
        
        const slug = name.toLowerCase()
            .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
            .replace(/[^a-z0-9]+/g, '_')
            .replace(/^_|_$/g, '');
        
        const [existing] = await this.pool.query('SELECT id FROM p_categories WHERE name = ?', [name]);
        if (existing.length > 0) return existing[0].id;
        
        const [result] = await this.pool.query('INSERT INTO p_categories (name, slug) VALUES (?, ?)', [name, slug]);
        return result.insertId;
    }
    
    async upsertModel(code, name, family, basePrice, year = null) {
        const familyId = await this.getOrCreateFamily(family, family);
        
        await this.pool.query(`
            INSERT INTO p_models (code, name, family_id, base_price, year)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                family_id = VALUES(family_id),
                base_price = VALUES(base_price),
                year = VALUES(year)
        `, [code, name, familyId, basePrice, year || new Date().getFullYear()]);
        
        const [result] = await this.pool.query('SELECT id FROM p_models WHERE code = ?', [code]);
        return result[0].id;
    }
    
    async upsertOption(modelId, option) {
        const categoryId = await this.getOrCreateCategory(option.category);
        
        const [existing] = await this.pool.query(
            'SELECT id, price FROM p_options WHERE model_id = ? AND code = ?',
            [modelId, option.code]
        );
        
        if (existing.length > 0 && existing[0].price !== option.price && option.price !== null) {
            await this.pool.query(
                'INSERT INTO p_price_history (option_id, old_price, new_price) VALUES (?, ?, ?)',
                [existing[0].id, existing[0].price, option.price]
            );
        }
        
        await this.pool.query(`
            INSERT INTO p_options (model_id, category_id, code, name, price, is_standard)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                category_id = VALUES(category_id),
                name = VALUES(name),
                price = VALUES(price),
                is_standard = VALUES(is_standard)
        `, [modelId, categoryId, option.code, option.name, option.price, option.isStandard ? 1 : 0]);
    }
    
    async updateOptionsCount(modelCode) {
        await this.pool.query(`
            UPDATE p_models m
            SET options_count = (SELECT COUNT(*) FROM p_options o WHERE o.model_id = m.id)
            WHERE m.code = ?
        `, [modelCode]);
    }
    
    async getStats() {
        const [[families]] = await this.pool.query('SELECT COUNT(*) as count FROM p_families');
        const [[models]] = await this.pool.query('SELECT COUNT(*) as count FROM p_models');
        const [[options]] = await this.pool.query('SELECT COUNT(*) as count FROM p_options');
        const [[categories]] = await this.pool.query('SELECT COUNT(*) as count FROM p_categories');
        const [[priceChanges]] = await this.pool.query('SELECT COUNT(*) as count FROM p_price_history');
        
        const [byFamily] = await this.pool.query(`
            SELECT f.name as family, COUNT(DISTINCT m.id) as models, COUNT(o.id) as options
            FROM p_families f
            LEFT JOIN p_models m ON m.family_id = f.id
            LEFT JOIN p_options o ON o.model_id = m.id
            GROUP BY f.id
            ORDER BY options DESC
        `);
        
        return {
            families: families.count,
            models: models.count,
            options: options.count,
            categories: categories.count,
            priceChanges: priceChanges.count,
            byFamily
        };
    }
    
    async getAllOptions() {
        const [rows] = await this.pool.query('SELECT * FROM v_options_full ORDER BY family_name, model_name, category_name, price DESC');
        return rows;
    }
    
    async close() {
        if (this.pool) {
            await this.pool.end();
        }
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// EXTRACTEUR
// ═══════════════════════════════════════════════════════════════════════════════

class PorscheExtractor {
    constructor(db, headless = true) {
        this.db = db;
        this.headless = headless;
        this.browser = null;
        this.context = null;
    }
    
    async init() {
        this.browser = await chromium.launch({
            headless: this.headless,
            args: ['--disable-blink-features=AutomationControlled']
        });
        
        this.context = await this.browser.newContext({
            userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            locale: CONFIG.locale,
            viewport: { width: 1920, height: 1080 }
        });
    }
    
    // Déterminer la famille à partir du nom
    detectFamily(name) {
        const nameLower = name.toLowerCase();
        if (nameLower.includes('718') || nameLower.includes('cayman') || nameLower.includes('boxster') || nameLower.includes('spyder')) {
            return '718';
        } else if (nameLower.includes('911')) {
            return '911';
        } else if (nameLower.includes('taycan')) {
            return 'Taycan';
        } else if (nameLower.includes('panamera')) {
            return 'Panamera';
        } else if (nameLower.includes('macan')) {
            return 'Macan';
        } else if (nameLower.includes('cayenne')) {
            return 'Cayenne';
        }
        return 'Autre';
    }
    
    async extractModel(modelCode) {
        const url = `${CONFIG.baseUrl}/${CONFIG.locale}/mode/model/${modelCode}`;
        
        console.log(`\n📦 Modèle: ${modelCode}`);
        
        const page = await this.context.newPage();
        
        try {
            await page.goto(url, { waitUntil: 'networkidle', timeout: CONFIG.timeout });
            
            // Cookies
            try {
                const btn = page.getByRole('button', { name: /Tout accepter/i });
                await btn.click({ timeout: 5000 });
                await page.waitForTimeout(1000);
            } catch (e) {}
            
            // Récupérer le nom du modèle depuis la page
            const modelName = await page.evaluate(() => {
                // Méthode 1: h1
                const h1 = document.querySelector('h1');
                if (h1 && h1.textContent.trim().length > 3) {
                    return h1.textContent.trim();
                }
                
                // Méthode 2: titre de la page
                const title = document.title;
                const match = title.match(/Porsche\s+(.+?)\s*[-–|]/);
                if (match) return match[1].trim();
                
                // Méthode 3: og:title
                const ogTitle = document.querySelector('meta[property="og:title"]');
                if (ogTitle) {
                    return ogTitle.getAttribute('content')?.replace('Porsche', '').trim() || '';
                }
                
                return '';
            }) || modelCode;
            
            // Déterminer la famille
            const family = this.detectFamily(modelName);
            
            // Prix de base
            const basePrice = await page.evaluate(() => {
                const match = document.body.innerText.match(/(\d{1,3}(?:[\s\u00a0]\d{3})*[,.]\d{2})\s*€/);
                return match ? parseFloat(match[1].replace(/[\s\u00a0]/g, '').replace(',', '.')) : null;
            });
            
            // Sauvegarder le modèle
            const modelId = await this.db.upsertModel(modelCode, modelName, family, basePrice);
            
            // Explorer la page
            await page.evaluate(async () => {
                const delay = ms => new Promise(r => setTimeout(r, ms));
                for (let i = 0; i < document.body.scrollHeight; i += 500) {
                    window.scrollTo(0, i);
                    await delay(100);
                }
                window.scrollTo(0, 0);
                for (const h of document.querySelectorAll('h2, h3')) {
                    try { h.click(); await delay(200); } catch (e) {}
                }
            });
            
            await page.waitForTimeout(2000);
            
            // Extraire les options
            const options = await page.evaluate(() => {
                const results = [];
                const seen = new Set();
                
                document.querySelectorAll('a[href*="/option/"]').forEach(link => {
                    const match = link.getAttribute('href')?.match(/\/option\/([A-Z0-9]+)/);
                    if (!match || seen.has(match[1])) return;
                    
                    const code = match[1];
                    seen.add(code);
                    
                    let container = link;
                    for (let i = 0; i < 10 && container; i++) {
                        container = container.parentElement;
                        if (container?.innerText?.includes('€')) break;
                    }
                    
                    const text = container?.innerText || '';
                    const priceMatch = text.match(/(\d{1,3}(?:[\s\u00a0]\d{3})*[,.]\d{2})\s*€/);
                    const price = priceMatch ? parseFloat(priceMatch[1].replace(/[\s\u00a0]/g, '').replace(',', '.')) : null;
                    const lines = text.split('\n').filter(l => l.trim().length > 2);
                    
                    results.push({
                        code,
                        name: lines[0]?.substring(0, 200) || code,
                        price,
                        isStandard: text.toLowerCase().includes('série') || price === 0,
                        category: ''
                    });
                });
                
                return results;
            });
            
            // Sauvegarder les options
            for (const opt of options) {
                await this.db.upsertOption(modelId, opt);
            }
            
            await this.db.updateOptionsCount(modelCode);
            
            console.log(`   ✅ ${options.length} options | Prix: ${basePrice?.toLocaleString('fr-FR') || 'N/A'} €`);
            
            return options.length;
            
        } catch (error) {
            console.log(`   ❌ Erreur: ${error.message}`);
            return 0;
        } finally {
            await page.close();
        }
    }
    
    async close() {
        if (this.browser) {
            await this.browser.close();
        }
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
// EXPORT CSV
// ═══════════════════════════════════════════════════════════════════════════════

async function exportToCSV(db) {
    if (!fs.existsSync(CONFIG.exportDir)) {
        fs.mkdirSync(CONFIG.exportDir, { recursive: true });
    }
    
    const options = await db.getAllOptions();
    
    const csvLines = [
        'model_code;model_name;family;option_code;option_name;price;is_standard;category'
    ];
    
    options.forEach(o => {
        csvLines.push([
            o.model_code,
            `"${(o.model_name || '').replace(/"/g, '""')}"`,
            o.family_name || '',
            o.option_code,
            `"${(o.option_name || '').replace(/"/g, '""').replace(/[\n\r]/g, ' ')}"`,
            o.price || '',
            o.is_standard ? 'Oui' : 'Non',
            `"${(o.category_name || '').replace(/"/g, '""')}"`
        ].join(';'));
    });
    
    const filename = `porsche_options_${new Date().toISOString().split('T')[0]}.csv`;
    fs.writeFileSync(path.join(CONFIG.exportDir, filename), csvLines.join('\n'));
    console.log(`📄 Exporté: ${CONFIG.exportDir}/${filename} (${options.length} options)`);
}

// ═══════════════════════════════════════════════════════════════════════════════
// MAIN
// ═══════════════════════════════════════════════════════════════════════════════

async function main() {
    const args = process.argv.slice(2);
    
    console.log('╔══════════════════════════════════════════════════════════════╗');
    console.log('║         PORSCHE OPTIONS EXTRACTOR v4.0 - MySQL               ║');
    console.log('╚══════════════════════════════════════════════════════════════╝\n');
    
    const db = new PorscheDB();
    
    try {
        await db.connect();
        
        // --init : Créer les tables
        if (args.includes('--init')) {
            await db.initSchema();
            await db.close();
            return;
        }
        
        // --stats : Statistiques
        if (args.includes('--stats')) {
            const stats = await db.getStats();
            console.log('📊 Statistiques:\n');
            console.log(`   Familles: ${stats.families}`);
            console.log(`   Modèles: ${stats.models}`);
            console.log(`   Options: ${stats.options}`);
            console.log(`   Catégories: ${stats.categories}`);
            console.log(`   Changements de prix: ${stats.priceChanges}`);
            
            if (stats.byFamily.length > 0) {
                console.log('\n   Par famille:');
                stats.byFamily.forEach(f => {
                    console.log(`   - ${f.family}: ${f.models} modèles, ${f.options} options`);
                });
            }
            await db.close();
            return;
        }
        
        // --list : Lister les modèles EN BASE
        if (args.includes('--list')) {
            const [models] = await db.pool.query(`
                SELECT m.code, m.name, f.name as family, m.options_count, m.base_price, m.last_updated
                FROM p_models m
                LEFT JOIN p_families f ON m.family_id = f.id
                ORDER BY f.name, m.name
            `);
            
            if (models.length === 0) {
                console.log('📋 Aucun modèle en base. Lancez une extraction avec --model CODE\n');
            } else {
                console.log(`📋 ${models.length} modèles en base:\n`);
                let currentFamily = '';
                models.forEach(m => {
                    if (m.family !== currentFamily) {
                        currentFamily = m.family;
                        console.log(`\n  ${currentFamily || 'Autre'}:`);
                    }
                    console.log(`    ${m.code.padEnd(10)} ${m.name.padEnd(35)} ${m.options_count} options | ${m.base_price?.toLocaleString('fr-FR') || '-'} €`);
                });
            }
            await db.close();
            return;
        }
        
        // --export : Export CSV
        if (args.includes('--export')) {
            console.log('📤 Export des données...\n');
            await exportToCSV(db);
            await db.close();
            return;
        }
        
        // Extraction - nécessite --model
        const modelIndex = args.indexOf('--model');
        if (modelIndex === -1 || !args[modelIndex + 1]) {
            console.log('Usage:\n');
            console.log('  node porsche_extractor_mysql.js --init              # Créer les tables');
            console.log('  node porsche_extractor_mysql.js --model CODE        # Extraire un modèle');
            console.log('  node porsche_extractor_mysql.js --model CODE1,CODE2 # Extraire plusieurs modèles');
            console.log('  node porsche_extractor_mysql.js --stats             # Statistiques');
            console.log('  node porsche_extractor_mysql.js --list              # Lister les modèles en base');
            console.log('  node porsche_extractor_mysql.js --export            # Export CSV');
            console.log('  node porsche_extractor_mysql.js --visible           # Mode navigateur visible');
            console.log('\nExemple:');
            console.log('  node porsche_extractor_mysql.js --model 982890');
            console.log('  node porsche_extractor_mysql.js --model 982890,Y1AAD2,9YAAI1 --visible\n');
            await db.close();
            return;
        }
        
        // Récupérer les codes
        const modelCodes = args[modelIndex + 1].split(',').map(c => c.trim()).filter(c => c.length > 0);
        
        const headless = !args.includes('--visible');
        const extractor = new PorscheExtractor(db, headless);
        
        await extractor.init();
        
        console.log(`🚗 ${modelCodes.length} modèle(s) à extraire`);
        console.log(`🔧 Mode: ${headless ? 'Invisible' : 'Visible'}`);
        console.log(`💾 Base: ${DB_CONFIG.host}/${DB_CONFIG.database}`);
        
        let totalOptions = 0;
        let successCount = 0;
        
        for (let i = 0; i < modelCodes.length; i++) {
            const code = modelCodes[i];
            console.log(`\n[${i + 1}/${modelCodes.length}]`);
            
            const count = await extractor.extractModel(code);
            totalOptions += count;
            if (count > 0) successCount++;
            
            if (i < modelCodes.length - 1) {
                await new Promise(r => setTimeout(r, CONFIG.delayBetweenModels));
            }
        }
        
        await extractor.close();
        
        // Résumé
        console.log('\n═══════════════════════════════════════════════════════════════');
        console.log('                      📊 RÉSUMÉ FINAL                          ');
        console.log('═══════════════════════════════════════════════════════════════\n');
        console.log(`   ✅ Modèles extraits: ${successCount}/${modelCodes.length}`);
        console.log(`   📦 Options totales: ${totalOptions}`);
        console.log(`   💾 Base: ${DB_CONFIG.database}`);
        console.log('');
        console.log('   Commandes utiles:');
        console.log('   --stats    Voir les statistiques');
        console.log('   --export   Générer les fichiers CSV');
        console.log('   --list     Lister les modèles en base');
        console.log('═══════════════════════════════════════════════════════════════\n');
        
    } catch (error) {
        console.error('❌ Erreur:', error.message);
    } finally {
        await db.close();
    }
}

main().catch(console.error);