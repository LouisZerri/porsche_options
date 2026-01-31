const { chromium } = require('playwright');
const mysql = require('mysql2/promise');

const CONFIG = {
    db: { host: 'localhost', user: 'root', password: 'root', database: 'porsche_options' }
};

(async () => {
    const modelCode = process.argv[2] || '982850';
    console.log('\n🔍 Test des infobulles pour le modèle ' + modelCode + '\n');

    const db = await mysql.createPool(CONFIG.db);
    
    const [dbOptions] = await db.query(
        'SELECT code, name, description FROM p_options WHERE model_id = (SELECT id FROM p_models WHERE code = ?)',
        [modelCode]
    );
    console.log('📦 ' + dbOptions.length + ' options en base de données');

    const browser = await chromium.launch({
        headless: true,
        executablePath: './browsers/chromium-1200/chrome-linux64/chrome'
    });
    const page = await browser.newPage();

    const url = 'https://configurator.porsche.com/fr-FR/mode/model/' + modelCode;
    await page.goto(url, { waitUntil: 'networkidle', timeout: 60000 });

    try {
        await page.getByRole('button', { name: /Tout accepter/i }).click({ timeout: 3000 });
        await page.waitForTimeout(1000);
    } catch (e) {}

    await page.evaluate(async () => {
        const buttons = document.querySelectorAll('button[aria-expanded="false"]');
        for (const btn of buttons) {
            try { btn.click(); await new Promise(r => setTimeout(r, 200)); } catch (e) {}
        }
    });
    await page.waitForTimeout(2000);

    const siteLinks = await page.evaluate(() => {
        const codes = new Set();
        document.querySelectorAll('a[href*="/option/"]').forEach(link => {
            const match = link.href.match(/\/option\/([A-Z0-9]+)/i);
            if (match) codes.add(match[1]);
        });
        return [...codes];
    });
    console.log('🌐 ' + siteLinks.length + ' liens infobulles sur le site\n');

    const results = { withDesc: [], noDesc: [], error: [] };
    let tested = 0;

    for (const code of siteLinks) {
        try {
            await page.goto('https://configurator.porsche.com/fr-FR/mode/model/' + modelCode + '/option/' + code, 
                { waitUntil: 'domcontentloaded', timeout: 15000 });
            await page.waitForTimeout(800);

            const hasDesc = await page.evaluate(() => {
                const sheets = document.querySelectorAll('icc-p-sheet');
                for (const sheet of sheets) {
                    const h2 = sheet.querySelector('h2');
                    if (h2) {
                        const descDiv = sheet.querySelector('[class*="py-fluid-xs"][class*="prose-text-sm"]')
                                      || sheet.querySelector('[class*="prose-text-sm"][class*="break-words"]');
                        if (descDiv && descDiv.innerText && descDiv.innerText.trim().length > 15) return true;
                    }
                }
                return false;
            });

            if (hasDesc) results.withDesc.push(code);
            else results.noDesc.push(code);
        } catch (e) {
            results.error.push(code);
        }

        tested++;
        if (tested % 20 === 0) console.log('   ⏳ ' + tested + '/' + siteLinks.length + ' testés...');
    }

    await browser.close();

    const dbWithDesc = dbOptions.filter(o => o.description && o.description.length > 15).map(o => o.code);
    const dbWithoutDesc = dbOptions.filter(o => !o.description || o.description.length <= 15).map(o => o.code);

    console.log('\n📊 RÉSULTATS :');
    console.log('   Site - Avec description: ' + results.withDesc.length);
    console.log('   Site - Sans description: ' + results.noDesc.length);
    console.log('   Site - Erreurs: ' + results.error.length);
    console.log('   BDD  - Avec description: ' + dbWithDesc.length);
    console.log('   BDD  - Sans description: ' + dbWithoutDesc.length);

    const missing = results.withDesc.filter(code => !dbWithDesc.includes(code));
    if (missing.length > 0) {
        console.log('\n❌ MANQUANTES (' + missing.length + ') - sur le site mais pas en BDD:');
        missing.forEach(code => {
            const opt = dbOptions.find(o => o.code === code);
            console.log('   ' + code + ': ' + (opt ? opt.name : '(pas en base)'));
        });
    } else {
        console.log('\n✅ Toutes les descriptions du site sont en BDD !');
    }

    if (results.noDesc.length > 0) {
        console.log('\n⚪ Sans infobulle sur le site (' + results.noDesc.length + '):');
        results.noDesc.slice(0, 10).forEach(code => console.log('   ' + code));
        if (results.noDesc.length > 10) console.log('   ... et ' + (results.noDesc.length - 10) + ' autres');
    }

    await db.end();
    console.log('\n✅ Test terminé');
})();
