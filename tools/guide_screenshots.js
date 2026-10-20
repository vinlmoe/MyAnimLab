/**
 * Screenshots of the guides (docs/guides/img), taken with Playwright on a
 * test server running the demonstration database (tools/demo_data.php).
 *
 *   php tools/demo_data.php --db=myanimlab_demo --user=root --password=... --ids=/tmp/demo_ids.json
 *   (point the test server's .env to DB_DATABASE=myanimlab_demo)
 *   ASSETS=/path/to/node_modules BASE=http://127.0.0.1:8081 IDS=/tmp/demo_ids.json \
 *     node tools/guide_screenshots.js docs/guides/img
 *
 * The pages load Bootstrap, Font Awesome, Select2 and jQuery from CDNs and
 * their QR codes from api.qrserver.com: they are served here from local npm
 * packages (ASSETS: bs5@npm:bootstrap@5.1.3, bs4@npm:bootstrap@4.5.2,
 * fa5@npm:@fortawesome/fontawesome-free@5.15.3,
 * fa6@npm:@fortawesome/fontawesome-free@6.0.0-beta3, s2@npm:select2@4.1.0-rc.0,
 * s213@npm:select2@4.0.13, jq@npm:jquery@3.6.0, qrcode), so that the
 * screenshots work without access to those hosts.
 *
 * Pages are saved as JPEG below the banner and menu, elements as PNG, at
 * 1.4 times their size (1792 px wide for a full page).
 * ONLY=name1,name2 takes only those screenshots.
 */
const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');
const QRCode = require(path.join(process.env.ASSETS, 'qrcode'));

const out = process.argv[2] || 'docs/guides/img';
const base = process.env.BASE || 'http://127.0.0.1:8081';
const ids = JSON.parse(fs.readFileSync(process.env.IDS, 'utf8'));
const A = process.env.ASSETS;
const password = 'Demo-2026!';

// CDN URL prefix -> local folder.
const cdn = [
  [/^https:\/\/cdn\.jsdelivr\.net\/npm\/bootstrap@5[^/]*\/dist\//, 'bs5/dist/'],
  [/^https:\/\/(maxcdn|stackpath)\.bootstrapcdn\.com\/bootstrap\/4[^/]*\//, 'bs4/dist/'],
  [/^https:\/\/cdnjs\.cloudflare\.com\/ajax\/libs\/font-awesome\/5[^/]*\//, 'fa5/'],
  [/^https:\/\/cdnjs\.cloudflare\.com\/ajax\/libs\/font-awesome\/6[^/]*\//, 'fa6/'],
  [/^https:\/\/cdn\.jsdelivr\.net\/npm\/select2@4\.1[^/]*\/dist\//, 's2/dist/'],
  [/^https:\/\/cdnjs\.cloudflare\.com\/ajax\/libs\/select2\/4\.1[^/]*\//, 's2/dist/'],
  [/^https:\/\/cdnjs\.cloudflare\.com\/ajax\/libs\/select2\/4\.0[^/]*\//, 's213/dist/'],
  [/^https:\/\/(code\.jquery\.com|ajax\.googleapis\.com\/ajax\/libs\/jquery\/[^/]+|cdnjs\.cloudflare\.com\/ajax\/libs\/jquery\/[^/]+)\/jquery[^/]*\.js$/, null],
];
const types = { css: 'text/css', js: 'application/javascript', woff2: 'font/woff2', woff: 'font/woff', ttf: 'font/ttf', svg: 'image/svg+xml', eot: 'application/vnd.ms-fontobject' };

async function route(r) {
  const url = r.request().url().split('?')[0];
  if (url.startsWith(base)) return r.continue();
  if (r.request().url().startsWith('https://api.qrserver.com/')) {
    const data = new URL(r.request().url()).searchParams.get('data') || '';
    return r.fulfill({ contentType: 'image/png', body: await QRCode.toBuffer(data, { margin: 1, width: 220 }) });
  }
  for (const [re, dir] of cdn) {
    if (re.test(url)) {
      const file = dir === null ? path.join(A, 'jq/dist/jquery.min.js') : path.join(A, dir, url.replace(re, ''));
      if (fs.existsSync(file)) {
        return r.fulfill({ contentType: types[path.extname(file).slice(1)] || 'application/octet-stream', body: fs.readFileSync(file) });
      }
    }
  }
  return r.fulfill({ status: 204, body: '' }); // other external resources (captcha, QR reader...)
}

// 1280 px wide pages rendered at 1.4: 1792 px images, sharp once printed.
const scale = 1.4;

async function session(browser, email, viewport = { width: 1280, height: 860 }) {
  const context = await browser.newContext({ viewport, deviceScaleFactor: scale, locale: 'fr-FR' });
  await context.route('**/*', route);
  const page = await context.newPage();
  await page.goto(base + '/index.php');
  await page.fill('input[name=username]', email);
  await page.fill('input[name=password]', password);
  await Promise.all([page.waitForNavigation(), page.click('button[type=submit], input[type=submit]')]);
  return page;
}

/**
 * Screenshot of a page (JPEG, below the banner and menu unless opts.banner)
 * or of an element (PNG: opts.element is a CSS selector, opts.locator a
 * Playwright locator). opts.clipX = [left, right] keeps a band of the page,
 * opts.height its top part.
 */
const only = (process.env.ONLY || '').split(',').filter(Boolean);

async function shot(page, name, url, opts = {}) {
  if (only.length && !only.includes(name)) return;
  if (url) await page.goto(base + '/' + url, { waitUntil: 'networkidle' });
  if (opts.before) await opts.before(page);
  await page.waitForTimeout(150);
  if (opts.locator || opts.element) {
    const target = opts.locator ? page.locator(opts.locator).first() : await page.$(opts.element);
    await target.screenshot({ path: path.join(out, name + '.png') });
  } else {
    const vp = page.viewportSize();
    const top = opts.banner ? 0 : await page.evaluate(() => {
      const bar = document.querySelector('.nav-container') || document.querySelector('.brand-banner');
      return bar ? Math.ceil(bar.getBoundingClientRect().bottom) : 0;
    });
    const [left, right] = opts.clipX || [0, vp.width];
    const height = Math.min(vp.height - top, opts.height || vp.height);
    await page.screenshot({ path: path.join(out, name + '.jpg'), type: 'jpeg', quality: 85, clip: { x: left, y: top, width: right - left, height } });
  }
  console.log('  ' + name);
}

(async () => {
  fs.mkdirSync(out, { recursive: true });
  const browser = await chromium.launch({ executablePath: process.env.CHROMIUM || undefined });

  // Sign-in page, signed out.
  const anon = await browser.newContext({ viewport: { width: 1280, height: 760 }, deviceScaleFactor: scale });
  await anon.route('**/*', route);
  const login = await anon.newPage();
  await shot(login, 'u_connexion', 'index.php', { banner: true });

  // User: Julien Bernard (animal care technician, user of the breeding cage).
  const u = await session(browser, 'julien.bernard@demo.fr');
  await shot(u, 'u_accueil', 'home.php', { banner: true });
  await shot(u, 'u_tournee', 'daily_round.php');
  await shot(u, 'u_temperatures', 'daily_round.php', { element: '#roomTemperatures' });
  await shot(u, 'u_tableau_bord', 'hc_dash.php', { height: 500 });
  await shot(u, 'u_fiche_cage', 'hc_view.php?id=' + ids.holding[0], { clipX: [230, 1050] });
  await shot(u, 'u_portees_en_cours', 'bc_view.php?id=' + ids.breeding, { element: '#currentLitters' });
  await shot(u, 'u_portee', 'litter_manage.php?litter_id=' + ids.overdueLitter);
  await shot(u, 'u_fichiers', 'hc_view.php?id=' + ids.holding[0], { element: '#files' });
  await shot(u, 'u_sanitaire', 'health_report_view.php?id=' + ids.healthReport);
  await shot(u, 'u_registre_animal', 'animal_view.php?id=' + ids.exitedAnimal, { element: '#register' });
  await shot(u, 'u_ordonnances', 'prescription_manage.php');
  await shot(u, 'u_administration_cage', 'medication_administration.php?cage_id=' + ids.holding[1]);
  await shot(u, 'u_administrations_historique', null, { element: '#administrationHistory' });
  await shot(u, 'u_livret', 'training_book.php');
  await shot(u, 'u_livret_parcours', null, { element: '#trainingPath' });
  await shot(u, 'u_livret_continue', null, { locator: 'h5:has-text("Formation continue") + div' });
  await shot(u, 'u_livret_ajout', null, {
    before: async p => { await p.click('.path-add[data-category="competence"]'); await p.waitForTimeout(600); },
    element: '#addEntry form',
  });
  await shot(u, 'u_livret_procedures', 'training_book.php', { element: '#procedures' });
  await shot(u, 'u_sortie_encadrant', 'animal_exit.php?code=euthanasie&id=' + ids.sickAnimal, { element: '.content' });
  await shot(u, 'u_retrait_cage', 'cage_retire.php?id=' + ids.holding[0], {
    before: async p => { await p.selectOption('#allFate', 'euthanasie'); await p.click('#applyAll'); await p.waitForTimeout(200); },
    element: '.content',
  });
  await shot(u, 'u_arbre', 'animal_pedigree.php?id=' + ids.inbred, { element: '.ped-wrapper' });
  await shot(u, 'u_arbre_descendance', 'animal_pedigree.php?id=' + ids.founder, { element: '#descendants' });
  await shot(u, 'u_naissance', 'litter_manage.php?cage_id=' + ids.breeding, { element: '#birthForm' });
  await shot(u, 'u_sevrage', 'litter_manage.php?litter_id=' + ids.overdueLitter, { element: '#weanForm' });
  await shot(u, 'u_terrarium', 'terrarium_view.php?id=' + ids.terrarium);
  await shot(u, 'u_terrarium_traitements', null, {
    before: async p => { await p.click('[data-bs-target="#treatments"]'); await p.waitForTimeout(300); },
    element: '#treatments',
  });
  await shot(u, 'u_terrarium_sanitaire', null, {
    before: async p => { await p.click('[data-bs-target="#health"]'); await p.waitForTimeout(300); },
    element: '#health',
  });
  await shot(u, 'u_terrarium_captures', null, {
    before: async p => { await p.click('[data-bs-target="#captures"]'); await p.waitForTimeout(300); },
    element: '#captures',
  });

  // Sophie Leroy (researcher, BEA): experimental follow-up.
  const s = await session(browser, 'sophie.leroy@demo.fr');
  await shot(s, 'u_suivi', 'experiment_followup.php?experiment_animal_id=' + ids.enrollmentAlert);
  await shot(s, 'u_suivi_correction', 'experiment_followup_view.php?id=' + ids.correctedFollowup, { element: '#followupRevisions' });
  await shot(s, 'u_experience', 'experiment_manage.php?protocol_id=' + ids.protocol);
  await shot(s, 'u_protocole', 'protocol_manage.php?trial_id=' + ids.trial, { element: '.protocol-card' });
  await shot(s, 'u_actes', 'experiment_acts.php?experiment_id=' + ids.experiment, { element: '#act' + ids.act });
  await shot(s, 'u_planifier_acte', 'experiment_acts.php?experiment_id=' + ids.experiment, {
    before: async p => { await p.evaluate(() => document.getElementById('planAct').classList.add('show')); },
    element: '#planActForm',
  });

  // Phone: the page opened by the QR code of the breeding cage.
  const phone = await session(browser, 'julien.bernard@demo.fr', { width: 390, height: 844 });
  await shot(phone, 'u_qr_accouplement', 'cage_check.php?via=qr&id=' + ids.breeding);

  // Breeding cage label (printed card).
  await shot(u, 'u_etiquette', 'cage_labels.php?id=' + ids.breeding, { element: 'article.label' });
  // The same table once completed by hand (example of pencil entries).
  await shot(u, 'u_etiquette_crayon', 'cage_labels.php?id=' + ids.breeding, {
    before: async p => p.evaluate(() => {
      const rows = document.querySelectorAll('article.label .litters tbody tr');
      const pencil = (td, text, keep = false) => { td.innerHTML = (keep ? td.innerHTML + ' ' : '') + '<span style="font-family: \'Comic Sans MS\', \'Segoe Print\', cursive; color: #555; font-size: 1.05em">' + text + '</span>'; };
      pencil(rows[0].cells[2], '16/10 : 1', true);
      pencil(rows[0].cells[4], '17/10/26');
      pencil(rows[2].cells[0], '18/10/26');
      pencil(rows[2].cells[1], '8');
      pencil(rows[2].cells[3], '08/11/26');
      pencil(rows[2].cells[5], '✓');
    }),
    element: 'article.label .litters',
  });

  // Administrator: Claire Martin.
  const a = await session(browser, 'claire.martin@demo.fr');
  await shot(a, 'a_utilisateurs', 'manage_users.php');
  await shot(a, 'a_laboratoire', 'manage_lab.php');
  await shot(a, 'a_autorisations', 'manage_iacuc.php');
  await shot(a, 'a_propriete', 'hc_dash.php', {
    before: async p => {
      await p.check('#selectAll');
      await p.evaluate(() => { document.getElementById('ownershipPanel').open = true; });
      await p.evaluate(() => document.querySelector('.bulk-bar').scrollIntoView());
    },
    element: '.bulk-bar',
  });
  await shot(a, 'a_registre', 'animal_register.php');
  await shot(a, 'a_retro_navigation', 'experiment_retrospective.php?protocol_id=' + ids.protocol, { height: 330 });
  await shot(a, 'a_retrospective', 'experiment_retrospective.php?experiment_id=' + ids.doneExperiment, { element: 'main, .content' });
  await shot(a, 'a_dossier', 'animal_dossier.php?experiment_animal_id=' + ids.doneEnrollment);
  await shot(a, 'a_journaux', 'log_export.php');
  await shot(a, 'a_stupefiants', 'narcotics_register.php');
  await shot(a, 'a_pharmacie', 'pharmacy_manage.php');
  await shot(a, 'a_livrets', 'training_overview.php');
  await shot(a, 'a_livret_validation', 'training_book.php?user_id=' + ids.julien, { locator: 'h5:has-text("Formation continue") + div' });
  await shot(a, 'a_accouplement', 'bc_addn.php', { clipX: [230, 1050] });
  await shot(a, 'a_terrariums', 'terrarium_dash.php');

  await browser.close();
})().catch(e => { console.error(e); process.exit(1); });
