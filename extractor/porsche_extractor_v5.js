/**
 * PORSCHE OPTIONS EXTRACTOR v5.7 - FINAL
 * Extrait couleurs, jantes, options depuis les INPUT checkboxes et liens
 * Gestion correcte des prix par catégorie
 */

const mysql = require('mysql2/promise');
const path = require('path');

process.env.PLAYWRIGHT_BROWSERS_PATH = path.join(__dirname, 'browsers');

const { chromium } = require('playwright');

const DB_CONFIG = {
    host: process.env.DB_HOST || 'localhost',
    port: process.env.DB_PORT || 3306,
    user: process.env.DB_USER || 'root',
    password: process.env.DB_PASSWORD || 'root',
    database: process.env.DB_NAME || 'porsche_options',
    charset: 'utf8mb4',
};

const CONFIG = {
    baseUrl: 'https://configurator.porsche.com',
    locale: 'fr-FR',
    timeout: 60000,
};

// ═══════════════════════════════════════════════════════════════════════════════
// BASE DE DONNÉES
// ═══════════════════════════════════════════════════════════════════════════════

class PorscheDB {
    constructor() { this.pool = null; }
    
    async connect(skipDbSelect = false) {
        if (skipDbSelect) {
            // Connexion sans sélectionner de base (pour --init)
            this.pool = mysql.createPool({ 
                host: DB_CONFIG.host, 
                port: DB_CONFIG.port, 
                user: DB_CONFIG.user, 
                password: DB_CONFIG.password, 
                charset: DB_CONFIG.charset,
                waitForConnections: true, 
                connectionLimit: 10 
            });
        } else {
            // Connexion normale avec la base
            const tempPool = mysql.createPool({ host: DB_CONFIG.host, port: DB_CONFIG.port, user: DB_CONFIG.user, password: DB_CONFIG.password, charset: DB_CONFIG.charset });
            await tempPool.query(`CREATE DATABASE IF NOT EXISTS \`${DB_CONFIG.database}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci`);
            await tempPool.end();
            this.pool = mysql.createPool({ ...DB_CONFIG, waitForConnections: true, connectionLimit: 10 });
        }
        console.log(`✅ Connecté à MySQL: ${DB_CONFIG.host}`);
    }
    
    async initSchema() {
        console.log('🗑️  Suppression de la base existante...');
        await this.pool.query(`DROP DATABASE IF EXISTS \`${DB_CONFIG.database}\``);
        
        console.log('📦 Création de la base de données...');
        await this.pool.query(`CREATE DATABASE \`${DB_CONFIG.database}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci`);
        await this.pool.query(`USE \`${DB_CONFIG.database}\``);
        
        console.log('📋 Création des tables...\n');
        await this.pool.query(`CREATE TABLE IF NOT EXISTS p_families (id INT AUTO_INCREMENT PRIMARY KEY, code VARCHAR(50) UNIQUE NOT NULL, name VARCHAR(100) NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`);
        await this.pool.query(`CREATE TABLE IF NOT EXISTS p_models (id INT AUTO_INCREMENT PRIMARY KEY, code VARCHAR(20) UNIQUE NOT NULL, name VARCHAR(100) NOT NULL, family_id INT, base_price DECIMAL(10,2), year INT, options_count INT DEFAULT 0, colors_ext_count INT DEFAULT 0, colors_int_count INT DEFAULT 0, last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, FOREIGN KEY (family_id) REFERENCES p_families(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`);
        await this.pool.query(`CREATE TABLE IF NOT EXISTS p_categories (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(150) NOT NULL, parent_name VARCHAR(150), slug VARCHAR(150), UNIQUE KEY unique_cat (name, parent_name)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`);
        await this.pool.query(`CREATE TABLE IF NOT EXISTS p_options (id INT AUTO_INCREMENT PRIMARY KEY, model_id INT NOT NULL, category_id INT, code VARCHAR(20) NOT NULL, name VARCHAR(255), price DECIMAL(10,2), is_standard BOOLEAN DEFAULT FALSE, option_type ENUM('option', 'color_ext', 'color_int', 'wheel', 'seat', 'pack') DEFAULT 'option', image_url VARCHAR(500), UNIQUE KEY unique_model_option (model_id, code), FOREIGN KEY (model_id) REFERENCES p_models(id) ON DELETE CASCADE, FOREIGN KEY (category_id) REFERENCES p_categories(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`);
        console.log('✅ Base de données réinitialisée avec succès !');
    }
    
    async getOrCreateFamily(code, name) {
        const [existing] = await this.pool.query('SELECT id FROM p_families WHERE code = ?', [code]);
        if (existing.length > 0) return existing[0].id;
        const [result] = await this.pool.query('INSERT INTO p_families (code, name) VALUES (?, ?)', [code, name]);
        return result.insertId;
    }
    
    async getOrCreateCategory(name, parentName = null) {
        if (!name) return null;
        const slug = name.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/[^a-z0-9]+/g, '_');
        const [existing] = await this.pool.query('SELECT id FROM p_categories WHERE name = ? AND (parent_name = ? OR (parent_name IS NULL AND ? IS NULL))', [name, parentName, parentName]);
        if (existing.length > 0) return existing[0].id;
        try {
            const [result] = await this.pool.query('INSERT INTO p_categories (name, parent_name, slug) VALUES (?, ?, ?)', [name, parentName, slug]);
            return result.insertId;
        } catch (e) { return null; }
    }
    
    async upsertModel(code, name, family, basePrice) {
        const familyId = await this.getOrCreateFamily(family, family);
        await this.pool.query(`INSERT INTO p_models (code, name, family_id, base_price, year) VALUES (?, ?, ?, ?, 2025) ON DUPLICATE KEY UPDATE name = VALUES(name), family_id = VALUES(family_id), base_price = VALUES(base_price)`, [code, name, familyId, basePrice]);
        const [result] = await this.pool.query('SELECT id FROM p_models WHERE code = ?', [code]);
        return result[0].id;
    }
    
    async upsertOption(modelId, option) {
        const categoryId = await this.getOrCreateCategory(option.category, option.parentCategory);
        await this.pool.query(`INSERT INTO p_options (model_id, category_id, code, name, price, is_standard, option_type, image_url) VALUES (?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE category_id = VALUES(category_id), name = VALUES(name), price = VALUES(price), is_standard = VALUES(is_standard), option_type = VALUES(option_type), image_url = COALESCE(VALUES(image_url), image_url)`, [modelId, categoryId, option.code, option.name, option.price, option.isStandard ? 1 : 0, option.type || 'option', option.imageUrl || null]);
    }
    
    async updateCounts(modelId) {
        await this.pool.query(`UPDATE p_models SET options_count = (SELECT COUNT(*) FROM p_options WHERE model_id = ? AND option_type NOT IN ('color_ext', 'color_int')), colors_ext_count = (SELECT COUNT(*) FROM p_options WHERE model_id = ? AND option_type = 'color_ext'), colors_int_count = (SELECT COUNT(*) FROM p_options WHERE model_id = ? AND option_type = 'color_int') WHERE id = ?`, [modelId, modelId, modelId, modelId]);
    }
    
    async close() { if (this.pool) await this.pool.end(); }
}

// ═══════════════════════════════════════════════════════════════════════════════
// EXTRACTEUR v5.3 FINAL
// ═══════════════════════════════════════════════════════════════════════════════

class PorscheExtractor {
    constructor(db, headless = true) {
        this.db = db;
        this.headless = headless;
        this.browser = null;
        this.context = null;
    }
    
    async init() {
        this.browser = await chromium.launch({ headless: this.headless, args: ['--disable-blink-features=AutomationControlled'] });
        this.context = await this.browser.newContext({ userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36', locale: CONFIG.locale, viewport: { width: 1920, height: 1080 } });
    }
    
    detectFamily(name) {
        const n = name.toLowerCase();
        if (n.includes('718') || n.includes('cayman') || n.includes('boxster') || n.includes('spyder')) return '718';
        if (n.includes('911')) return '911';
        if (n.includes('taycan')) return 'Taycan';
        if (n.includes('panamera')) return 'Panamera';
        if (n.includes('macan')) return 'Macan';
        if (n.includes('cayenne')) return 'Cayenne';
        return 'Autre';
    }
    
    async extractModel(modelCode) {
        // Essayer 2026 d'abord, puis 2025 si ça échoue
        const years = ['2026', '2025', '2024'];
        let url = `${CONFIG.baseUrl}/${CONFIG.locale}/mode/model/${years[0]}/${modelCode}`;
        
        console.log(`\n${'═'.repeat(70)}`);
        console.log(`📦 EXTRACTION: ${modelCode}`);
        console.log(`${'═'.repeat(70)}`);
        
        const page = await this.context.newPage();
        
        try {
            console.log('\n⏳ Chargement...');
            
            // Essayer différentes années
            let loaded = false;
            for (const year of years) {
                url = `${CONFIG.baseUrl}/${CONFIG.locale}/mode/model/${year}/${modelCode}`;
                try {
                    const response = await page.goto(url, { waitUntil: 'networkidle', timeout: CONFIG.timeout });
                    if (response && response.status() === 200) {
                        const pageUrl = page.url();
                        if (!pageUrl.includes('/error') && !pageUrl.includes('/404')) {
                            console.log(`   ✓ Trouvé avec année ${year}`);
                            loaded = true;
                            break;
                        }
                    }
                } catch (e) {
                    continue;
                }
            }
            
            if (!loaded) {
                console.log('   ❌ Modèle non trouvé');
                return 0;
            }
            
            try {
                await page.getByRole('button', { name: /Tout accepter/i }).click({ timeout: 5000 });
                await page.waitForTimeout(1000);
            } catch (e) {}
            
            await page.waitForTimeout(3000);
            
            const modelName = await page.locator('h1').first().textContent().catch(() => modelCode);
            console.log(`📋 ${modelName.trim()}`);
            
            const basePrice = await page.evaluate(() => {
                // Chercher le prix de base près du H1 ou dans une zone spécifique
                // Le prix de base est généralement > 30 000€ pour une Porsche
                const allPrices = [];
                const priceRegex = /(\d{1,3}(?:[\s\u00a0]\d{3})*[,.]\d{2})\s*€/g;
                const text = document.body.innerText;
                let match;
                
                while ((match = priceRegex.exec(text)) !== null) {
                    const price = parseFloat(match[1].replace(/[\s\u00a0]/g, '').replace(',', '.'));
                    if (price > 30000 && price < 1000000) {
                        allPrices.push(price);
                    }
                }
                
                // Retourner le premier prix > 30 000€ (généralement le prix de base)
                return allPrices.length > 0 ? allPrices[0] : null;
            });
            console.log(`💰 ${basePrice?.toLocaleString('fr-FR')} €`);
            
            const family = this.detectFamily(modelName);
            const modelId = await this.db.upsertModel(modelCode, modelName.trim(), family, basePrice);
            
            // Scroll et déploiement
            console.log('\n📜 Déploiement des sections...');
            
            await page.evaluate(async () => {
                for (let i = 0; i < document.body.scrollHeight; i += 500) {
                    window.scrollTo(0, i);
                    await new Promise(r => setTimeout(r, 100));
                }
                window.scrollTo(0, 0);
            });
            
            await page.evaluate(async () => {
                const delay = ms => new Promise(r => setTimeout(r, ms));
                for (const h2 of document.querySelectorAll('h2')) {
                    try { h2.click(); await delay(300); } catch (e) {}
                }
                for (const h3 of document.querySelectorAll('h3')) {
                    try { h3.click(); await delay(200); } catch (e) {}
                }
            });
            
            await page.waitForTimeout(3000);
            
            // ═══════════════════════════════════════════════════════════════
            // EXTRACTION COMPLÈTE
            // ═══════════════════════════════════════════════════════════════
            
            const allOptions = await page.evaluate(() => {
                const results = [];
                const seen = new Set();
                
                // ─────────────────────────────────────────────────────────────
                // 1. EXTRAIRE LES COULEURS DEPUIS LES INPUT CHECKBOXES
                // ─────────────────────────────────────────────────────────────
                
                // Fonction pour trouver la section H2 parente
                function findParentH2(element) {
                    let el = element;
                    for (let i = 0; i < 15 && el; i++) {
                        el = el.parentElement;
                        if (!el) break;
                        
                        const h2 = el.querySelector('h2');
                        if (h2) {
                            const text = h2.textContent.toLowerCase();
                            // Ignorer les H2 de résumé
                            if (!text.includes('et jantes') && !text.includes('et sièges')) {
                                return h2.textContent.trim();
                            }
                        }
                    }
                    return null;
                }
                
                // Fonction pour trouver la sous-section H3
                function findParentH3(element) {
                    let el = element;
                    for (let i = 0; i < 10 && el; i++) {
                        el = el.parentElement;
                        if (!el) break;
                        
                        // Chercher H3 précédent
                        let prev = el.previousElementSibling;
                        while (prev) {
                            if (prev.tagName === 'H3') return prev.textContent.trim();
                            const h3 = prev.querySelector('h3');
                            if (h3) return h3.textContent.trim();
                            prev = prev.previousElementSibling;
                        }
                    }
                    return null;
                }
                
                // Fonction pour trouver l'URL de l'image associée à un élément
                function findImageUrl(element, type, code) {
                    // Chercher dans les parents proches
                    let el = element;
                    for (let i = 0; i < 8 && el; i++) {
                        // Chercher toutes les images dans ce conteneur
                        const allImgs = el.querySelectorAll('img');
                        for (const img of allImgs) {
                            // Essayer src, data-src, data-lazy-src
                            let src = img.src || '';
                            if (!src || src.includes('data:')) {
                                src = img.getAttribute('data-src') || img.getAttribute('data-lazy-src') || '';
                            }
                            
                            if (src && !src.includes('data:') && !src.includes('icon') && !src.includes('svg')) {
                                return src;
                            }
                        }
                        
                        // Chercher les picture/source srcset
                        const sources = el.querySelectorAll('picture source, source');
                        for (const source of sources) {
                            const srcset = source.getAttribute('srcset') || '';
                            if (srcset) {
                                const firstSrc = srcset.split(',')[0]?.trim()?.split(' ')[0];
                                if (firstSrc && !firstSrc.includes('data:')) {
                                    return firstSrc;
                                }
                            }
                        }
                        
                        // Chercher background-image
                        const allElements = el.querySelectorAll('*');
                        for (const child of allElements) {
                            const style = window.getComputedStyle(child);
                            const bgImage = style.backgroundImage;
                            if (bgImage && bgImage !== 'none' && bgImage.includes('url(')) {
                                const urlMatch = bgImage.match(/url\(["']?([^"')]+)["']?\)/);
                                if (urlMatch && urlMatch[1] && !urlMatch[1].includes('data:') && !urlMatch[1].includes('icon')) {
                                    return urlMatch[1];
                                }
                            }
                        }
                        
                        // Chercher attributs data-image
                        const withDataImg = el.querySelectorAll('[data-image], [data-src], [data-background]');
                        for (const elem of withDataImg) {
                            const dataImg = elem.getAttribute('data-image') || elem.getAttribute('data-src') || elem.getAttribute('data-background') || '';
                            if (dataImg && !dataImg.includes('data:')) {
                                return dataImg;
                            }
                        }
                        
                        el = el.parentElement;
                    }
                    
                    // Chercher dans les siblings
                    const parent = element.parentElement;
                    if (parent) {
                        const siblings = parent.querySelectorAll('img');
                        for (const img of siblings) {
                            const src = img.src || img.getAttribute('data-src') || '';
                            if (src && !src.includes('data:') && !src.includes('icon')) {
                                return src;
                            }
                        }
                    }
                    
                    // Chercher label associé
                    const inputId = element.getAttribute('id');
                    if (inputId) {
                        const label = document.querySelector(`label[for="${inputId}"]`);
                        if (label) {
                            const labelImg = label.querySelector('img');
                            if (labelImg) {
                                const src = labelImg.src || labelImg.getAttribute('data-src') || '';
                                if (src && !src.includes('data:')) {
                                    return src;
                                }
                            }
                        }
                    }
                    
                    return null;
                }
                
                // Extraire les couleurs depuis les inputs
                const colorInputs = document.querySelectorAll('input[name="options"]');
                
                colorInputs.forEach(input => {
                    const code = input.getAttribute('value');
                    const name = input.getAttribute('aria-label');
                    
                    if (!code || !name || seen.has(code)) return;
                    seen.add(code);
                    
                    // Trouver la section parente
                    const parentH2 = findParentH2(input);
                    const parentH3 = findParentH3(input);
                    
                    // Déterminer le type
                    let type = 'option';
                    const h2Lower = (parentH2 || '').toLowerCase();
                    
                    if (h2Lower.includes('couleurs extérieure')) {
                        type = 'color_ext';
                    } else if (h2Lower.includes('couleurs intérieure')) {
                        type = 'color_int';
                    } else if (h2Lower.includes('jante')) {
                        type = 'wheel';
                    } else if (h2Lower.includes('siège')) {
                        type = 'seat';
                    }
                    
                    // ═══════════════════════════════════════════════════════════
                    // Chercher le prix selon le type
                    // ═══════════════════════════════════════════════════════════
                    let price = null;
                    let isStandard = false;
                    
                    // Pour les COULEURS: chercher le prix dans le H3 parent (catégorie)
                    // Ex: "Légendes 3 540,00 €", "Contrastes 0,00 €"
                    if (type === 'color_ext' || type === 'color_int') {
                        let el = input;
                        for (let i = 0; i < 15 && el; i++) {
                            el = el.parentElement;
                            if (!el) break;
                            
                            const text = el.innerText || '';
                            const priceMatch = text.match(/(\d{1,3}(?:[\s\u00a0]\d{3})*[,.]\d{2})\s*€/);
                            
                            if (priceMatch) {
                                const val = parseFloat(priceMatch[1].replace(/[\s\u00a0]/g, '').replace(',', '.'));
                                if (val >= 0 && val < 50000) {
                                    price = val;
                                    isStandard = (val === 0);
                                    break;
                                }
                            }
                        }
                    } else {
                        // Pour les AUTRES (jantes, sièges): chercher près de l'élément
                        // Profondeur LIMITÉE (4 niveaux) pour éviter de prendre le prix d'un autre élément
                        let el = input;
                        for (let i = 0; i < 4 && el; i++) {
                            el = el.parentElement;
                            if (!el) break;
                            
                            // Vérifier que le conteneur n'est pas trop large (éviter sections entières)
                            const childInputs = el.querySelectorAll('input[name="options"]');
                            if (childInputs.length > 3) continue; // Trop d'inputs = conteneur trop large
                            
                            const text = el.innerText || '';
                            const priceMatches = text.match(/(\d{1,3}(?:[\s\u00a0]\d{3})*[,.]\d{2})\s*€/g) || [];
                            
                            for (const pm of priceMatches) {
                                const val = parseFloat(pm.replace(/[\s\u00a0€]/g, '').replace(',', '.'));
                                if (val >= 0 && val < 50000) {
                                    price = val;
                                    isStandard = (val === 0);
                                    break;
                                }
                            }
                            
                            if (price !== null) break;
                        }
                    }
                    
                    // Fallback: checked ou disabled = série
                    if (price === null && (input.hasAttribute('checked') || input.hasAttribute('disabled'))) {
                        isStandard = true;
                        price = 0;
                    }
                    
                    // Capturer l'URL de l'image (seulement pour couleurs et sièges)
                    let imageUrl = null;
                    if (type === 'color_ext' || type === 'color_int' || type === 'seat') {
                        imageUrl = findImageUrl(input, type, code);
                    }
                    
                    results.push({
                        code,
                        name,
                        price,
                        isStandard,
                        type,
                        category: parentH3 || parentH2 || 'Couleurs',
                        parentCategory: parentH2 || 'Couleurs',
                        imageUrl
                    });
                });
                
                // ─────────────────────────────────────────────────────────────
                // 2. EXTRAIRE LES AUTRES OPTIONS DEPUIS LES LIENS <a>
                // ─────────────────────────────────────────────────────────────
                
                const sectionConfig = [
                    { match: 'couleurs extérieure', type: 'color_ext' },
                    { match: 'couleurs intérieure', type: 'color_int' },
                    { match: 'jante', type: 'wheel' },
                    { match: 'siège', type: 'seat' },
                    { match: 'pack', type: 'pack' },
                ];
                
                document.querySelectorAll('a[href*="/option/"]').forEach(link => {
                    const href = link.getAttribute('href') || '';
                    const match = href.match(/\/option\/([A-Z0-9]+)/i);
                    if (!match) return;
                    
                    const code = match[1];
                    if (seen.has(code)) return;
                    seen.add(code);
                    
                    // Trouver le H2 et H3 parents
                    const parentH2 = findParentH2(link);
                    const parentH3 = findParentH3(link);
                    
                    // Déterminer le type
                    let type = 'option';
                    const h2Lower = (parentH2 || '').toLowerCase();
                    for (const cfg of sectionConfig) {
                        if (h2Lower.includes(cfg.match)) {
                            type = cfg.type;
                            break;
                        }
                    }
                    
                    // Prix et nom
                    let container = link;
                    let price = null;
                    let name = null;
                    
                    for (let i = 0; i < 8 && container; i++) {
                        container = container.parentElement;
                        if (!container) break;
                        
                        const text = container.innerText || '';
                        
                        // Chercher le prix - prendre le premier prix > 0 trouvé
                        if (price === null) {
                            const prices = text.match(/(\d{1,3}(?:[\s\u00a0]\d{3})*[,.]\d{2})\s*€/g) || [];
                            for (const p of prices) {
                                const val = parseFloat(p.replace(/[\s\u00a0€]/g, '').replace(',', '.'));
                                // Ignorer les prix trop élevés (prix de base du véhicule)
                                if (val > 0 && val < 50000) {
                                    price = val;
                                    break;
                                } else if (val === 0) {
                                    price = 0;
                                }
                            }
                        }
                        
                        // Chercher le nom
                        if (!name) {
                            const lines = text.split('\n').filter(l => l.trim().length > 2 && l.length < 150 && !l.includes('€'));
                            if (lines.length > 0) name = lines[0].trim();
                        }
                        
                        if (price !== null && name) break;
                    }
                    
                    if (!name) name = link.textContent?.trim() || code;
                    
                    const containerText = container?.innerText || '';
                    const isStandard = containerText.toLowerCase().includes('série') || price === 0;
                    
                    // Capturer l'URL de l'image (seulement pour couleurs et sièges)
                    let imageUrl = null;
                    if (type === 'color_ext' || type === 'color_int' || type === 'seat') {
                        imageUrl = findImageUrl(link, type, code);
                    }
                    
                    results.push({
                        code,
                        name: name.substring(0, 250),
                        price,
                        isStandard,
                        type,
                        category: parentH3 || parentH2 || 'Autre',
                        parentCategory: parentH2 || 'Autre',
                        imageUrl
                    });
                });
                
                return results;
            });
            
            // Stats
            const byType = {};
            let imagesFound = 0;
            allOptions.forEach(o => { 
                byType[o.type] = (byType[o.type] || 0) + 1;
                if (o.imageUrl) imagesFound++;
            });
            
            console.log(`\n📊 ${allOptions.length} éléments extraits`);
            console.log(`   🎨 Couleurs ext: ${byType['color_ext'] || 0}`);
            console.log(`   🛋️ Couleurs int: ${byType['color_int'] || 0}`);
            console.log(`   🛞 Jantes: ${byType['wheel'] || 0}`);
            console.log(`   💺 Sièges: ${byType['seat'] || 0}`);
            console.log(`   📦 Packs: ${byType['pack'] || 0}`);
            console.log(`   ⚙️ Options: ${byType['option'] || 0}`);
            console.log(`   🖼️ Images: ${imagesFound}`);
            
            // Lister les couleurs
            const colors = allOptions.filter(o => o.type === 'color_ext' || o.type === 'color_int');
            console.log(`\n🎨 COULEURS EXTRAITES (${colors.length}):`);
            colors.forEach(c => {
                const priceStr = c.isStandard ? '✓ Série' : `${c.price?.toLocaleString('fr-FR') || '?'} €`;
                console.log(`   [${c.code}] ${c.type === 'color_ext' ? 'EXT' : 'INT'} - ${c.name} - ${priceStr}`);
            });
            
            // Lister les jantes
            const wheels = allOptions.filter(o => o.type === 'wheel');
            console.log(`\n🛞 JANTES EXTRAITES (${wheels.length}):`);
            wheels.forEach(w => {
                const priceStr = w.isStandard ? '✓ Série' : `${w.price?.toLocaleString('fr-FR') || '?'} €`;
                console.log(`   [${w.code}] ${w.name.substring(0, 50)} - ${priceStr}`);
            });
            
            // Sauvegarder
            console.log('\n💾 Sauvegarde...');
            for (const opt of allOptions) {
                await this.db.upsertOption(modelId, opt);
            }
            await this.db.updateCounts(modelId);
            
            console.log(`\n${'═'.repeat(70)}`);
            console.log(`✅ TERMINÉ: ${allOptions.length} éléments | ${byType['color_ext'] || 0} couleurs ext | ${byType['color_int'] || 0} couleurs int`);
            console.log(`${'═'.repeat(70)}`);
            
            return allOptions.length;
            
        } catch (error) {
            console.log(`\n❌ ERREUR: ${error.message}`);
            return 0;
        } finally {
            await page.close();
        }
    }
    
    async close() { if (this.browser) await this.browser.close(); }
}

// MAIN
async function main() {
    const args = process.argv.slice(2);
    
    console.log('╔══════════════════════════════════════════════════════════════════════════╗');
    console.log('║       PORSCHE OPTIONS EXTRACTOR v5.7 - FINAL                             ║');
    console.log('╚══════════════════════════════════════════════════════════════════════════╝\n');
    
    const db = new PorscheDB();
    
    try {
        if (args.includes('--init')) {
            await db.connect(true);  // Connexion sans sélectionner de base
            await db.initSchema();
            try { await db.close(); } catch(e) {}
            return;
        }
        
        await db.connect();
        
        if (args.includes('--stats')) {
            const [[{ models }]] = await db.pool.query('SELECT COUNT(*) as models FROM p_models');
            const [[{ options }]] = await db.pool.query('SELECT COUNT(*) as options FROM p_options');
            const [byType] = await db.pool.query('SELECT option_type, COUNT(*) as count FROM p_options GROUP BY option_type ORDER BY count DESC');
            
            console.log('📊 Statistiques:\n');
            console.log(`   Modèles: ${models}`);
            console.log(`   Options: ${options}`);
            console.log('\n   Par type:');
            byType.forEach(t => console.log(`      ${t.option_type}: ${t.count}`));
            return;
        }
        
        if (args.includes('--list')) {
            const [models] = await db.pool.query(`SELECT m.code, m.name, f.name as family, m.options_count, m.colors_ext_count, m.colors_int_count FROM p_models m LEFT JOIN p_families f ON m.family_id = f.id ORDER BY f.name, m.name`);
            console.log(`📋 ${models.length} modèles:\n`);
            models.forEach(m => console.log(`   ${m.code.padEnd(10)} ${m.name.substring(0, 25).padEnd(25)} ${String(m.options_count).padStart(3)} opt | ${String(m.colors_ext_count).padStart(2)} ext | ${String(m.colors_int_count).padStart(2)} int`));
            return;
        }
        
        const modelIndex = args.indexOf('--model');
        if (modelIndex === -1 || !args[modelIndex + 1]) {
            console.log('Usage:');
            console.log('  node porsche_extractor_v5.js --init');
            console.log('  node porsche_extractor_v5.js --model 982890');
            console.log('  node porsche_extractor_v5.js --model 982890 --visible');
            console.log('  node porsche_extractor_v5.js --stats');
            console.log('  node porsche_extractor_v5.js --list\n');
            return;
        }
        
        const modelCodes = args[modelIndex + 1].split(',').map(c => c.trim()).filter(c => c);
        const headless = !args.includes('--visible');
        
        const extractor = new PorscheExtractor(db, headless);
        await extractor.init();
        
        console.log(`🚗 Modèle(s): ${modelCodes.join(', ')}`);
        console.log(`🔧 Mode: ${headless ? 'Invisible' : 'Visible'}\n`);
        
        let total = 0;
        for (const code of modelCodes) {
            total += await extractor.extractModel(code);
            if (modelCodes.length > 1) await new Promise(r => setTimeout(r, 3000));
        }
        
        await extractor.close();
        
        if (modelCodes.length > 1) {
            console.log(`\n📊 TOTAL: ${total} éléments extraits\n`);
        }
        
    } catch (error) {
        console.error('❌ Erreur:', error.message);
    } finally {
        try { await db.close(); } catch(e) {}
    }
}

main().catch(console.error);