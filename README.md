![Logo](https://myvivarium.online/images/logo1.jpg)

# MyVivarium

![Statut du projet](https://img.shields.io/badge/status-active-brightgreen) [![Licence LGPL](https://img.shields.io/badge/License-LGPL--3.0-blue.svg)](https://choosealicense.com/licenses/lgpl-3.0/)

[![Visiter le site de démonstration](https://img.shields.io/badge/Visit-Demo%20Site-blue?style=for-the-badge)](https://demo.myvivarium.online)

![PHP](https://img.shields.io/badge/php-%23777BB4.svg?&style=for-the-badge&logo=php&logoColor=white) ![HTML](https://img.shields.io/badge/html5-%23E34F26.svg?&style=for-the-badge&logo=html5&logoColor=white) ![CSS](https://img.shields.io/badge/css3-%231572B6.svg?&style=for-the-badge&logo=css3&logoColor=white) ![JavaScript](https://img.shields.io/badge/javascript-%23F7DF1E.svg?&style=for-the-badge&logo=javascript&logoColor=black) ![Font Awesome](https://img.shields.io/badge/font%20awesome-%23339AF0.svg?&style=for-the-badge&logo=font-awesome&logoColor=white) ![Bootstrap](https://img.shields.io/badge/bootstrap-%23563D7C.svg?&style=for-the-badge&logo=bootstrap&logoColor=white)

MyVivarium est une plateforme en ligne pour gérer efficacement votre animalerie. Elle propose notamment l’inscription des utilisateurs, la gestion des profils et du laboratoire, ainsi que la surveillance environnementale en temps réel grâce à des capteurs IoT.

## Sommaire
- [Fonctionnalités](#fonctionnalités)
- [Guides](#guides)
- [Langue de l’interface](#langue-de-linterface)
- [Capture d’écran](#capture-décran)
- [Installation](#installation)
- [Utilisation](#utilisation)
- [Rôle de chaque fichier](#rôle-de-chaque-fichier)
- [Citer ce travail](#citer-ce-travail)
- [Contribuer](#contribuer)
- [Licence](#licence)

## Fonctionnalités
- Connexion des utilisateurs avec vérification de l’adresse e-mail.
- Gestion du profil utilisateur et réinitialisation du mot de passe.
- Fonctions d’administration pour gérer les utilisateurs et le laboratoire.
- Surveillance environnementale en temps réel par capteurs IoT. Pour plus de détails, consultez le [dépôt RPi-IoT](https://github.com/myvivarium/RPi-IoT).
- Gestion des données sécurisée et conforme.

![image](https://myvivarium.online/images/illustration.jpg)

## Guides

- [Guide de l’utilisateur](docs/guides/guide_utilisateur.pdf) : connexion, cages, tournée quotidienne, animaux, reproduction et sevrage, expérimentation, pharmacie, terrariums, formation, règles de traçabilité.
- [Guide de l’administrateur](docs/guides/guide_administrateur.pdf) : rôles, installation et mises à jour, paramètres du laboratoire, comptes, référentiels, supervision quotidienne, corrections réglementaires, volet BEA, stupéfiants, exports et préparation d’une inspection.

Les sources LaTeX sont dans `docs/guides/` (`pdflatex guide_utilisateur.tex`).

## Langue de l’interface

L’interface est en français par défaut et peut être affichée en anglais. Chaque utilisateur choisit sa langue dans le menu de l’en-tête ou dans son profil ; ce choix est conservé dans son compte.

Les textes sont traduits par `i18n.php` :

- `tr('Texte anglais')` renvoie la traduction du texte dans la langue courante (`trh()` l’échappe pour le HTML, `trj()` le renvoie sous forme de chaîne JavaScript). Les valeurs variables passent par des marqueurs : `tr('Cage {cage} retired.', ['cage' => $id])` ;
- les traductions françaises se trouvent dans `lang/fr_text.php`, indexées par le texte anglais d’origine. Un texte absent du catalogue s’affiche en anglais ;
- quelques libellés de navigation utilisent des clés (`t('nav.home')`) définies dans `lang/fr.php` et `lang/en.php`.

Pour ajouter un texte à l’interface, écrivez-le en anglais dans `tr()` puis ajoutez sa traduction dans `lang/fr_text.php`.

## Installation

### Session de test locale (Docker)

L’environnement local utilise une base MySQL isolée et active le bandeau de démonstration. Il ne se connecte ni à une base de production ni à un serveur SMTP.

1. Installez puis lancez Docker Desktop.
2. Depuis la racine du dépôt, démarrez la session :

   ```bash
   docker compose up --build
   ```

3. Ouvrez <http://localhost:8080> et connectez-vous avec :
   - E-mail : `admin@myvivarium.online`
   - Mot de passe : `P@ssw0rd`

Arrêtez la session avec `docker compose down`. Pour effacer toutes les données de test locales et recharger le schéma, utilisez `docker compose down -v`, puis relancez-la.

### 1. Installation en un clic sur DigitalOcean (la plus simple)

[![Badge de parrainage DigitalOcean](https://web-platforms.sfo2.cdn.digitaloceanspaces.com/WWW/Badge%203.svg)](https://www.digitalocean.com/?refcode=fdb1aa3adb7d&utm_campaign=Referral_Invite&utm_medium=Referral_Program&utm_source=badge)

#### Étapes :

1. **Créez un compte DigitalOcean** avec le lien de parrainage ci-dessus pour obtenir vos crédits.

2. **Créez un Droplet PHPMyAdmin** :
   - Utilisez ce lien pour déployer le [Droplet PHPMyAdmin](https://marketplace.digitalocean.com/apps/phpmyadmin) de DigitalOcean, qui installe en un clic PHPMyAdmin, MySQL Server, Apache, PHP et Certbot.
   - Après avoir cliqué sur **Create PHPMyAdmin Droplet**, connectez-vous à votre compte DigitalOcean puis :
     - choisissez la **région** et le **datacenter** de votre serveur ;
     - vérifiez que l’image **PHPMyAdmin** du Marketplace est sélectionnée ;
     - dans **Choose a plan**, sélectionnez **Basic** puis **Regular (1 GB / 1 CPU)** pour le processeur ;
     - configurez l’accès en créant une **clé SSH** ou en choisissant un **mot de passe** ;
     - enfin, cliquez sur **Create Droplet**.

3. **Accédez au Droplet** :
   - Une fois le droplet prêt, utilisez la console du tableau de bord DigitalOcean pour accéder au serveur.
   - Si vous disposez d’un nom de domaine, utilisez l’adresse IPv4 du droplet pour créer un enregistrement A dans la configuration DNS de votre domaine.

4. **Téléchargez le script d’installation** :
   Une fois connecté à la console de votre serveur Linux, téléchargez le script d’installation avec la commande suivante :

   ```bash
   curl -O https://raw.githubusercontent.com/vinlmoe/MyAnimLab/main/setup/setup.sh
   ```
5. **Rendez le script exécutable** :
   ```bash
   chmod +x setup.sh
   ```
6. **Lancez le script** :
   ```bash
   sudo ./setup.sh
   ```
7. **Répondez aux questions du script** :
   - Indiquez l’e-mail, le nom de domaine, le mot de passe de la base de données et les paramètres SMTP pour terminer l’installation et la configuration.

8. **Finalisez l’installation** :
   - Une fois la configuration DNS propagée (si vous utilisez un domaine), le site est accessible et vous pouvez commencer à utiliser MyVivarium.

### 2. Installation personnalisée

#### Prérequis
- PHP [8.1.2]
- MySQL Server [8.0.32]
- Apache [2.4.41]
- phpMyAdmin [5.2.1]
- Tutoriel d’installation d’une pile Linux, Apache, MySQL, PHP (LAMP) sous Ubuntu : [tutoriel LAMP de DigitalOcean](https://www.digitalocean.com/community/tutorials/how-to-install-lamp-stack-on-ubuntu)
- Composer

#### Étapes

1. **Clonez le dépôt :**
    ```bash
    git clone https://github.com/vinlmoe/MyAnimLab.git
    ```
    ou
    ```bash
    git clone git@github.com:vinlmoe/MyAnimLab.git
    ```
    ```bash
    cd MyAnimLab
    ```

2. **Configurez l’environnement :**
    - Copiez `.env.example` vers `.env` :
        ```bash
        cp .env.example .env
        ```
    - Renseignez dans `.env` les paramètres de la base de données et du SMTP. Voir [Configuration](#configuration).

3. **Placez les fichiers du projet dans le dossier du serveur web :**
    - Copiez tout le contenu du dossier, fichiers cachés compris (`.htaccess`, `.env`), dans le dossier public de votre serveur web (par ex. `public_html`, `www`) :
        ```bash
        cp -a . /chemin/vers/votre/public_html/
        ```
    - Le fichier `.htaccess` empêche Apache de servir `.env`, les dépendances, les scripts de base de données, les outils, les tests et les fichiers envoyés : il exige `AllowOverride All` sur ce dossier. Vérifiez après l’installation que `https://votre-domaine/.env` répond 403 ou 404.

4. **Installez les dépendances avec Composer :**
    ```bash
    composer install
    ```

5. **Créez la base de données :**
    - Connectez-vous à votre serveur MySQL :
        ```bash
        mysql -u votre_utilisateur -p
        ```
    - Créez la base et importez le schéma avec un compte administrateur de MySQL (le schéma crée des déclencheurs) :
        ```sql
        CREATE DATABASE myvivarium;
        USE myvivarium;
        SOURCE /chemin/vers/votre/public_html/database/schema.sql;
        ```
    - Donnez au compte de l’application les seuls droits sur les données (il ne peut ni modifier le schéma ni supprimer les déclencheurs qui protègent les journaux) :
        ```sql
        GRANT SELECT, INSERT, UPDATE, DELETE, CREATE TEMPORARY TABLES ON myvivarium.* TO 'votre_utilisateur'@'localhost';
        ```
    - Vous pouvez aussi utiliser une base de données existante :
        ```sql
        SOURCE /chemin/vers/votre/public_html/database/schema.sql;
        ```

6. **Programmez des tâches cron pour `send_email.php`, `process_reminders.php` et `daily_round_alert.php` :**
    Ces scripts ne s’exécutent qu’en ligne de commande (ils refusent les requêtes web), par exemple :
    ```
    */5 * * * * php /chemin/vers/votre/public_html/process_reminders.php
    */5 * * * * php /chemin/vers/votre/public_html/send_email.php
    */5 * * * * php /chemin/vers/votre/public_html/daily_round_alert.php
    ```

7. **Réglez le propriétaire et les permissions (si nécessaire) :**
    ```bash
    sudo chown -R www-data:www-data /chemin/vers/votre/public_html
    sudo chmod -R 755 /chemin/vers/votre/public_html
    ```

#### Configuration
##### Configuration SMTP
Renseignez les variables d’environnement suivantes dans votre fichier `.env` :
```bash
SMTP_HOST=smtp.example.com
SMTP_PORT=587
SMTP_USERNAME=username
SMTP_PASSWORD=password
SMTP_ENCRYPTION=tls
SENDER_EMAIL=sender@example.com
SENDER_NAME=MyVivarium
```

##### Configuration de la base de données
Vérifiez que les identifiants de la base de données sont correctement renseignés dans `.env` :
```bash
DB_HOST=localhost
DB_USERNAME=username
DB_PASSWORD=password
DB_DATABASE=myvivarium
```

## Capture d’écran

![image](https://myvivarium.online/images/myvivarium.gif)

## Utilisation
1. Ouvrez l’application dans votre navigateur :
    ```
    http://votredomaine.fr
    ```

2. Connectez-vous avec un compte créé par un administrateur (page Gestion des utilisateurs).

3. Gérez votre laboratoire et vos utilisateurs, et suivez les conditions environnementales en temps réel.

### Administrateur par défaut

Pour la configuration initiale, utilisez les identifiants administrateur par défaut :

- **E-mail** : admin@myvivarium.online
- **Mot de passe** : P@ssw0rd

**Important** : pour des raisons de sécurité, supprimez cet administrateur par défaut et créez un nouvel administrateur après la configuration initiale.

## Rôle de chaque fichier
- `dbcon.php` : gère la connexion à la base de données et charge les traductions de l’interface.
- `i18n.php` : choix de la langue et fonctions de traduction `t()`, `tr()`, `trh()` et `trj()`.
- `lang/fr_text.php` : traductions françaises de l’interface, indexées par le texte anglais.
- `lang/fr.php` / `lang/en.php` : libellés de navigation et du profil, par clé.
- `set_locale.php` : enregistre la langue choisie dans le menu de l’en-tête.
- `config.php` : contient la configuration SMTP.
- `session_config.php` : paramètre des sessions sécurisées (cookies HttpOnly, transmission HTTPS uniquement, expiration après inactivité) et protection CSRF : `csrf_token()` donne le jeton de la session, `csrf_valid()` vérifie qu’une requête POST le porte.
- `db_lib.php` : requêtes préparées en un appel (`db_rows()`, `db_row()`, `db_value()`, `db_execute()`, `db_placeholders()` pour les listes `IN`) et messages d’erreur (`db_error_message()`, `error_message()`) : une erreur de base de données est écrite dans le journal du serveur, l’utilisateur ne voit qu’un message générique.
- `.htaccess` : n’expose par Apache que les pages et ressources de l’application (voir « Sécurité du déploiement »).
- `index.php` : point d’entrée de l’application, gère la connexion des utilisateurs.
- `register.php` : l’auto-inscription est désactivée ; redirige vers la page de connexion (les comptes sont créés par les administrateurs dans `manage_users.php`).
- `home.php` : page d’accueil avec message de bienvenue, statistiques des cages et notes générales.
- `forgot_password.php` : gère la demande de réinitialisation du mot de passe, y compris la génération et l’envoi de l’e-mail.
- `reset_password.php` : gère la réinitialisation du mot de passe.
- `confirm_email.php` : confirme l’adresse e-mail en vérifiant le jeton et en mettant à jour le statut de l’utilisateur.
- `user_profile.php` : permet aux utilisateurs de mettre à jour leur profil, leur langue et de demander une réinitialisation du mot de passe.
- `manage_users.php` : gestion des utilisateurs par les administrateurs.
- `manage_lab.php` : gestion des informations du laboratoire par les administrateurs.
- `manage_strain.php` : gestion des souches par les administrateurs.
- `manage_iacuc.php` : autorisations de projet (administrateurs) : numéro éthique, numéro d’autorisation, dates de validité et dépôt en PDF de la demande d’autorisation, de l’avis du comité d’éthique et de la décision d’autorisation. La période de chaque essai doit être comprise dans la validité de son autorisation, et aucun animal ne peut être inclus si l’autorisation est expirée ou pas encore valide (migration `023_ethics_authorizations.sql`).
- `ethics_lib.php` / `ethics_file.php` : contrôles de validité des autorisations et téléchargement des PDF, réservé aux utilisateurs connectés. Les fichiers sont rangés dans `storage/ethics/`, hors du dossier `uploads/` public ; sauvegardez ce dossier avec la base de données.
- `trial_manage.php`, `protocol_manage.php`, `experiment_manage.php` : essais, plans / protocoles et expériences ; les dates d’un plan doivent être comprises dans celles de l’essai, et celles d’une expérience dans celles du plan. La page des expériences comporte une zone « Gestion des points limites » : actions requises à traiter (avec note de résolution, ou enregistrement de l’euthanasie), alertes sans action des 7 derniers jours et actions traitées. Chaque expérience présente aussi ses « Évaluations cliniques journalières » sous forme de tableau animaux × jours (score clinique, poids ou variation de poids, code couleur, jours non saisis, détail au survol). Un clic sur une case ouvre `experiment_followup_view.php`, la consultation en lecture seule du suivi : niveau retenu pour chaque item de la grille dans son échelle complète, poids, observations et actions de point limite, avec navigation jour par jour. Chaque plan porte sa sévérité attendue (migration `025_protocol_expected_severity.sql`). Après l’enregistrement d’une sortie ou d’une euthanasie, l’application revient à la page d’origine.
- `experiment_retrospective.php` / `animal_dossier.php` : analyse rétrospective BEA (administrateurs et responsables BEA), parcourue par essai, plan puis expérience. Les classifications de sévérité réelle d’une expérience sont validées ensemble, en regard de la sévérité attendue du plan. Le dossier complet de chaque animal s’ouvre dans un nouvel onglet et est imprimable : suivis journaliers, actions de point limite, signalements sanitaires et leurs suivis, traitements, mouvements de cage.
- `animal_register.php` : registre des entrées et sorties des animaux. Il liste par ordre chronologique chaque entrée (date, provenance : né dans l’établissement, fournisseur / éleveur agréé, autre établissement utilisateur ou autre, avec le fournisseur et une référence) et chaque sortie (date, code, motif, destination). On peut filtrer par période, mouvement, espèce et lignée. Le registre donne aussi les effectifs présents au début et à la fin de la période, et exporte en CSV ou PDF ; chaque export est tracé. La provenance est obligatoire à la création des animaux. Les animaux sevrés d’une portée sont enregistrés automatiquement comme nés dans l’établissement. Pour les animaux existants, la provenance se complète depuis la fiche animal (migration `026_animal_entry_register.sql`). Le PDF est produit par `pdf_lib.php`, sans dépendance externe.
- `experiment_cage_followup.php` : suivi expérimental groupé d’une cage en essai. Une telle cage ne peut pas être validée « RAS » lors de la tournée : elle n’est validée qu’une fois le suivi du jour saisi pour chacun de ses animaux en expérience (règle appliquée à partir de la migration `024_experimental_cage_round.sql`). Les actes expérimentaux du jour (et ceux en retard) de ses animaux peuvent y être confirmés pour toute la cage en une fois ; la cage n’est validée qu’une fois ces actes tous confirmés (réalisés ou non réalisés avec motif), en plus des suivis.
- `experiment_acts.php` : actes expérimentaux d’une expérience (injection, gavage, mise d’appât, anesthésie, analgésie, prélèvement, chirurgie, pesée de l’alimentation…), accessibles par le bouton « Actes » de chaque expérience. On choisit l’acte (substance / molécule, dose, voie, consignes), éventuellement un médicament du stock avec la quantité prélevée par animal, les informations à demander lors de la réalisation (ex. aliment restant en g), les dates (période et fréquence), puis on coche les animaux concernés — tous, par cage ou un par un — dans un tableau rangé par cage. Le jour prévu, l’acte est confirmé (réalisé / non réalisé avec motif) en le cochant dans le suivi journalier de l’animal ou de sa cage ; les actes en retard restent proposés. Un médicament est alors tracé comme une administration (sans ordonnance, dans le cadre de l’expérimentation) et débité du lot choisi. Un tableau animaux × dates présente l’état de chaque acte ; la tournée signale les cages ayant des actes à confirmer ; le dossier BEA de l’animal, l’analyse rétrospective et le rapport d’expérience (`experiment_report.php`, avec un export CSV des actes) les reprennent (migration `027_experiment_acts.sql`). Une dose selon le poids (ex. 75 mg/kg) d’un produit dont la concentration est connue (ex. 100 mg/mL) fait proposer à la confirmation le volume de chaque animal d’après le poids saisi ou son dernier poids. Une confirmation peut être corrigée par la personne qui l’a faite ou un administrateur, avec un motif : ce qui avait été saisi est conservé dans l’historique, le médicament administré est marqué annulé et recrédité sur son lot, et l’acte redevient à faire (migration `028_narcotics_corrections_weight_dose.sql`).
- `narcotics_register.php` : registre des stupéfiants (produits cochés « Stupéfiant » dans la Pharmacie, où l’on renseigne aussi leur concentration) : tous les mouvements de leurs lots dans l’ordre chronologique (réceptions, administrations sur ordonnance ou lors d’un acte expérimental avec l’animal, ajustements, corrections, inventaires), avec le solde du lot et du produit, la personne et le motif ; filtrable par période, imprimable et exportable en CSV. Un écart entre le registre et le stock est signalé. Les administrateurs y enregistrent les inventaires : quantité comptée par lot, écart justifié obligatoirement, appliqué au stock et inscrit au registre.
- `training_book.php` / `training_overview.php` : livret personnel de formation de chaque personne (menu Paramètres → « Mon livret de formation ») : diplôme initial (intitulé et spécialité, niveau, établissement, date d’obtention, copie), formation réglementaire initiale par fonction de la directive 2010/63/UE (conception, application des procédures, soins, mise à mort), compétence validée après encadrement / tutorat, formation continue et autres formations, avec attestation PDF (stockée en privé, servie par `training_file.php`). Une entrée déclarée par la personne compte une fois validée par un administrateur ou un responsable BEA (refus motivé). L’obligation de formation continue (par défaut 3 jours sur 6 ans glissants, transposition française — arrêté du 1er février 2013 —, réglable par un administrateur) est vérifiée : à jour, à renouveler bientôt (échéance calculée), à compléter (dans les 6 ans suivant la première formation), non conforme. La synthèse du personnel (administrateurs et responsables BEA) classe les personnes à traiter en premier, exportable en CSV. Un bandeau prévient l’utilisateur sur les pages expérimentales s’il n’est pas formé, travaille encore sous encadrement ou est en retard de formation continue ; `process_reminders.php` envoie une fois par jour les rappels de formation continue et signale les entrées à valider (migration `029_training_records.sql`). Une personne dont la compétence pour appliquer les procédures n’est pas validée ne peut confirmer un acte expérimental comme réalisé qu’en indiquant l’encadrant présent (une personne compétente de l’établissement, ou une autre personne nommée) ; l’encadrant est conservé avec l’acte et repris dans les suivis, le dossier BEA, le rapport et son export (migration `030_act_supervisor_initial_diploma.sql`).
- `iot_sensors.php` : affiche les données des capteurs IoT de chaque salle dans des iframes.
- `annual_report.php` : bilan annuel d’utilisation des animaux (administrateurs et responsables BEA) : effectifs, procédures terminées dans l’année par sévérité réelle, utilisation par autorisation de projet, sorties, animaux non utilisés ; export CSV par tableau ou ZIP.
- `cage_dash.php` : tableau de bord commun à toutes les catégories de cages (hébergement, accouplement, expérimentation, toutes) : salle, rack, animaux vivants, recherche, filtre par salle et sélection groupée pour changer de salle / rack ou basculer entre hébergement et expérimentation. `hc_dash.php`, `bc_dash.php` et `ec_dash.php` l’ouvrent sur leur catégorie.
- `cage_label_select.php` / `cage_labels.php` : étiquettes de cages (A6, quatre par feuille A4) avec la même mise en page pour toutes les catégories ; sélection par catégorie et salle, ou impression d’une sélection depuis un tableau de bord. Les anciennes adresses `hc_/bc_slct_crd.php` et `hc_/bc_prnt_crd.php` redirigent ici. L’étiquette d’une cage d’accouplement porte un tableau « Portées — à compléter au crayon » : les portées en allaitement y sont imprimées, et des lignes vides reçoivent au crayon les naissances, morts et sevrages survenus depuis. La consigne imprimée demande de reporter chaque note dans le logiciel (QR code → portées) puis de cocher « Saisi » ; la page ouverte par le QR code d’une cage d’accouplement donne accès à l’enregistrement d’une naissance et aux décès et sevrage de chaque portée.
- `cage_location.php` : applique un changement de salle / rack ou de catégorie à une ou plusieurs cages. Les administrateurs y changent aussi en lot la propriété des cages sélectionnées sur un tableau de bord : le responsable (fonction Principal Investigator) et les utilisateurs (ajouter, remplacer ou retirer des utilisateurs) ; chaque cage modifiée est inscrite au journal d’activité avec le responsable et les utilisateurs avant et après. Une cage d’hébergement devient aussi automatiquement une cage d’expérimentation (même numéro) lorsqu’un de ses animaux est inclus dans une expérience ou qu’un animal inclus y est déplacé.
- `cage_view_parts.php` : sections Emplacement et Animaux communes à toutes les pages de cage.
- `bc_dash.php` : tableau de bord des cages d’accouplement, avec recherche et pagination.
- `bc_addn.php` : création des cages d’accouplement et des données de portée associées.
- `bc_view.php` : fiche d’une cage d’accouplement. En tête, l’encadré « Portées en cours » liste les portées non sevrées : date de naissance, âge en jours, petits vivants (morts enregistrées déduites), date de sevrage prévue (en rouge si elle est dépassée) et lien vers le sevrage. Les portées sevrées ou clôturées sont regroupées plus bas dans « Portées précédentes », des plus récentes aux plus anciennes.
- `bc_edit.php` : ancienne page de modification, retirée ; l’adresse redirige vers la fiche de la cage.
- `bc_drop.php` : suppression des cages d’accouplement et de leurs données.
- `hc_dash.php` : tableau de bord des cages d’hébergement.
- `hc_addn.php` : ajout de cages d’hébergement.
- `hc_view.php` : affichage du détail d’une cage d’hébergement.
- `hc_edit.php` : ancienne page de modification, retirée ; l’adresse redirige vers la fiche de la cage.
- `hc_drop.php` : suppression des cages d’hébergement.
- `nt_app.php` : script principal de l’application de notes.
- `nt_add.php` : ajout de notes.
- `nt_edit.php` : modification de notes.
- `nt_rmv.php` : suppression de notes.
- `header.php` : en-tête et menu de navigation de l’application, avec le sélecteur de langue.
- `footer.php` : pied de page avec le nom du laboratoire et l’année en cours.
- `message.php` : affiche les messages de session sous forme d’alertes Bootstrap.
- `logout.php` : déconnecte l’utilisateur en détruisant la session et redirige vers la page de connexion.
- `cage_files_lib.php` / `file_upload.php` / `file_download.php` : fichiers joints aux cages, dans la section « Fichiers » de leur fiche (`hc_view.php`, `bc_view.php`). Les utilisateurs de la cage et les administrateurs ajoutent et suppriment les fichiers ; tout utilisateur connecté les télécharge. Un fichier (PDF, documents Office, texte, images, 10 Mo au plus) est contrôlé sur son contenu réel : un SVG, ou une page HTML renommée en `.png` ou `.pdf`, est refusé. Il est enregistré dans `uploads/` sous un nom aléatoire. `uploads/` n’est jamais servi par Apache : `file_download.php` envoie le fichier toujours en pièce jointe. Chaque ajout et chaque suppression sont tracés dans le journal d’activité. La limite de 10 Mo est réglée par `.htaccess` (`mod_php`), `.user.ini` (PHP-FPM) et l’image Docker.
- `delete_file.php` : supprime un fichier de cage, sur le serveur et dans la base (POST avec jeton CSRF, utilisateur de la cage ou administrateur), et trace la suppression.
- `export_for_v2.php` : export JSON des données pour la version 2 (administrateurs) ; les secrets (mots de passe hachés, jetons, secrets de double authentification, corps des e-mails) en sont exclus et chaque export est tracé.
- `export_data.php` : permet aux administrateurs d’exporter toutes les tables de la base en fichiers CSV.
- `log_export.php` : export des journaux bruts (activité, événements et mouvements des animaux, contrôles et cycle de vie des cages, portées, suivi sanitaire, actions de point limite, actes expérimentaux (définitions, informations demandées, statut par animal et par date, valeurs saisies), notifications, e-mails) tels qu’enregistrés, par journal en CSV ou tous en ZIP, avec filtre de dates ; réservé aux administrateurs et tracé dans le journal d’activité.
- `manage_tasks.php` : gestion des tâches (ajout, modification, suppression).
- `get_task.php` : renvoie le détail d’une tâche à partir de son identifiant.
- `send_email.php` : traite la file des e-mails en attente ; à programmer en tâche cron.
- `demo-banner.php` : affiche un bandeau en haut de page en mode démonstration.
- `demo-credentials.php` : affiche les identifiants administrateur de démonstration sur la page de connexion.
- `demo-disclaimer.php` : affiche l’avertissement de démonstration sur la page de connexion.
- `maintenance.php` : ajoute des entrées de maintenance aux cages, avec commentaire facultatif.
- `get_reminder.php` : renvoie les rappels programmés enregistrés dans la base de données.
- `manage_reminder.php` : gestion des rappels (ajout, modification, suppression).
- `process_reminders.php` : traite les rappels programmés et crée les tâches correspondantes ; à exécuter en tâche cron. Chaque matin (heure des rappels de Gérer le laboratoire, 07:30 par défaut), il envoie aussi les rappels de sevrage : les portées à sevrer le jour même ou le lendemain sont signalées aux utilisateurs de la cage (aux administrateurs si la cage n’en a pas) ; les sevrages en retard le sont aussi aux administrateurs et responsables BEA, par notification et e-mail.
- `daily_round_alert.php` : alerte de fin de journée envoyée aux administrateurs avec les cages non vues lors de la tournée quotidienne ; à lancer par cron toutes les quelques minutes (par ex. `*/5 * * * * php /chemin/vers/daily_round_alert.php`). Elle n’est envoyée qu’une fois par jour, après l’heure définie dans Gérer le laboratoire.

## Site de démonstration

### Découvrir MyVivarium

Un site de démonstration est disponible pour découvrir les fonctionnalités de MyVivarium. Cliquez sur le bouton ci-dessous pour y accéder :

[![Visiter le site de démonstration](https://img.shields.io/badge/Visit-Demo%20Site-blue?style=for-the-badge)](https://demo.myvivarium.online)

### Identifiants d’accès

Pour vous connecter et découvrir les fonctionnalités, utilisez les identifiants suivants :

- **E-mail** :
    ```
    admin@myvivarium.online
    ```
- **Mot de passe** :
    ```
    P@ssw0rd
    ```

### Avertissement

**Avertissement** : ce site de démonstration permet de découvrir les fonctionnalités. Toutes les données sont effacées périodiquement. N’y saisissez aucune information sensible ou critique.

N’hésitez pas à explorer, tester et nous faire part de vos retours. Bonne découverte de MyVivarium !

---

## Citer ce travail

Si vous utilisez ce code, merci de citer l’article suivant :

Vidva, R., Raza, M. A., Prabhakaran, J., Sheikh, A., Sharp, A., Ott, H., Moore, A., Fleisher, C., Pitychoutis, P. M., Nguyen, T. V., & Sathyanesan, A. (2024). MyVivarium: A cloud-based lab animal colony management application with near-realtime ambient sensing. *bioRxiv*. https://doi.org/10.1101/2024.08.10.607395

### BibTeX

```bibtex
@article {Vidva2024.08.10.607395,
	author = {Vidva, Robinson and Raza, Mir Abbas and Prabhakaran, Jaswant and Sheikh, Ayesha and Sharp, Alaina and Ott, Hayden and Moore, Amelia and Fleisher, Christopher and Pitychoutis, Pothitos M. and Nguyen, Tam V. and Sathyanesan, Aaron},
	title = {MyVivarium: A cloud-based lab animal colony management application with near-realtime ambient sensing},
	elocation-id = {2024.08.10.607395},
	year = {2024},
	doi = {10.1101/2024.08.10.607395},
	publisher = {Cold Spring Harbor Laboratory},
	URL = {https://www.biorxiv.org/content/early/2024/08/10/2024.08.10.607395},
	eprint = {https://www.biorxiv.org/content/early/2024/08/10/2024.08.10.607395.full.pdf},
	journal = {bioRxiv}
}
```

## Contribuer
Les contributions pour améliorer MyVivarium sont les bienvenues. Pour contribuer :

1. Forkez le dépôt.
2. Créez une branche : `git checkout -b feature/nom-de-votre-fonctionnalite`
3. Validez vos modifications : `git commit -m 'Ajout d’une fonctionnalité'`
4. Poussez la branche : `git push origin feature/nom-de-votre-fonctionnalite`
5. Ouvrez une pull request.

## Licence
Ce projet est distribué sous licence LGPL — voir le fichier [LICENSE](LICENSE) pour plus de détails.

## Tests automatisés

L’analyse statique utilise PHPStan (`composer analyse`, configuration `phpstan.neon`, niveau 0 sans erreur). Les tests sont écrits avec PHPUnit (`composer install`, puis `vendor/bin/phpunit` ou `composer test`) :

- `tests/Unit` : fonctions sans base de données (nombres, doses selon le poids, redirections, traductions), compilation de tous les fichiers PHP, traduction française de chaque texte de l’interface, démarrage des sessions par `session_config.php`, numérotation des migrations et leur enregistrement dans `schema.sql`, valeurs SQL toujours liées (aucune requête construite avec une valeur), jetons CSRF vérifiés par `csrf_valid()`, scripts cron refusant le web, règles du `.htaccess` ;
- `tests/Integration` : actes expérimentaux (planification, confirmation, débit du stock, refus, correction et recrédit, encadrant exigé), validation des cages en essai, livrets de formation (fonctions, formation continue, échéances, diplôme), journaux en ajout seul et corrections des suivis, corrections du registre des entrées et sorties, protection des prescriptions et administrations, outil de migrations ;
- `tests/Http` : serveur PHP intégré sur la base de test, connexion réelle par la page de login, ouverture de toutes les pages par un administrateur et un utilisateur sans erreur PHP, pages refusées aux visiteurs non connectés et aux non-administrateurs, jetons CSRF, notes modifiables par leur seul auteur, expiration de session, téléchargement des fichiers de cage, correction d’un suivi avec motif, export V2 sans secrets, scripts cron refusés par HTTP, corrections du registre réservées aux administrateurs avec motif, ajout (contenu contrôlé), téléchargement et suppression des fichiers de cage.

Les tests d’intégration et HTTP utilisent un serveur MySQL / MariaDB où ils créent une base jetable à partir de `database/schema.sql` (jamais la base du `.env`) : `TEST_DB_HOST` (127.0.0.1), `TEST_DB_PORT` (3306), `TEST_DB_USER` (root), `TEST_DB_PASSWORD` (vide) et `TEST_DB_NAME` (`myanimlab_test`, qui doit contenir « test »). Sans serveur joignable, ils sont ignorés et seuls les tests unitaires s’exécutent. Le workflow `.github/workflows/tests.yml` lance sur GitHub, à chaque push, l’audit des dépendances (`composer audit`), PHPStan puis tous les tests.

## Sessions

Toutes les pages démarrent leur session par `session_config.php` : cookie HttpOnly, SameSite=Lax, Secure en HTTPS (y compris derrière un proxy qui envoie `X-Forwarded-Proto`), identifiant renouvelé toutes les 30 minutes et déconnexion après une période d’inactivité réglable par un administrateur dans Manage Lab (« Déconnexion après inactivité », 5 à 720 minutes, 30 par défaut, migration `031_session_timeout_setting.sql`). L’interrogation périodique des notifications ne compte pas comme une activité.

## Quotas d’animaux, rappels des actes, double authentification

- **Animaux autorisés par espèce** (`manage_iacuc.php`) : chaque autorisation de projet peut indiquer le nombre d’animaux autorisés par espèce. Chaque inclusion dans une expérience d’un essai rattaché est contrôlée (un animal compte une fois par projet, même réutilisé) : une inclusion qui dépasserait le nombre autorisé, une espèce non autorisée ou un animal sans lignée sont refusés. Les administrateurs et responsables BEA sont prévenus une fois à 80 % puis à 100 %. L’utilisation est affichée sur la page de l’autorisation et sur celle des expériences.
- **Rappels des actes expérimentaux** : chaque matin, à partir de l’heure réglée dans Manage Lab (07:30 par défaut), `process_reminders.php` envoie une notification et un e-mail aux personnes qui ont planifié les actes du jour et aux utilisateurs des cages concernées ; les actes en retard sont aussi signalés aux administrateurs et responsables BEA.
- **Double authentification** (`two_factor.php`, `login_2fa.php`, `auth_lib.php`) : code à 6 chiffres d’une application d’authentification (TOTP, RFC 6238) demandé après le mot de passe, dix codes de secours à usage unique (stockés hachés), 5 essais puis verrouillage 15 minutes, code jamais réutilisable. Elle est obligatoire pour les administrateurs et responsables BEA (réglage de Manage Lab) : ils la configurent à leur prochaine connexion avant d’accéder à toute autre page. Un administrateur peut la réinitialiser pour un utilisateur qui a perdu son téléphone. Migration `032_quotas_act_reminders_two_factor.sql`.

## Terrariums et populations non identifiées

`terrarium_dash.php`, `terrarium_view.php`, `terrarium_lib.php` (menu Animaux → « Terrariums et populations », migration `033_terrarium_populations.sql`).

- **Population** : chaque terrarium (code, lignée ou espèce, salle, origine et référence, responsable) suit une population non identifiée individuellement. L’estimation actuelle est celle du dernier recensement approximatif (avec fourchette basse / haute et méthode), corrigée des introductions (avec leur référence de lot ou de fournisseur), mortalités, transferts et captures enregistrés depuis. La courbe des recensements est affichée.
- **Traitements de population** : antiparasitaire, antibiotique, désinfection… avec dose, voie, motif, prescripteur et référence d’ordonnance. Un médicament du stock débite son lot (mouvement de stock lié au traitement, visible dans le registre des stupéfiants). Un délai d’attente peut être fixé : pendant ce délai, aucune capture pour une expérimentation n’est possible.
- **Suivi sanitaire** : observations (état général, signes, animaux malades), paramètres d’ambiance (température, humidité, autres) et dépistages sur prélèvements (type, nombre d’animaux prélevés, agents recherchés, laboratoire, référence ; résultat enregistré à réception). Un contrôle anormal ou un dépistage positif est notifié au responsable (ou aux administrateurs) et signalé sur la liste ; un contrôle d’ambiance ne lève pas une alerte, une nouvelle observation normale ou un dépistage négatif si.
- **Captures** : les animaux capturés deviennent des animaux identifiés de la lignée, numérotés automatiquement à la suite (identifiants affichés avant validation ; origine « capturé dans une population de l’établissement » au registre des entrées). Une capture se répartit par cage : une ligne par cage (nouvelle cage d’hébergement ou cage existante, nombre d’animaux, sexe), avec un remplissage rapide (ex. 3 cages de 4 animaux) et le poids individuel de chaque animal (facultatif, repris comme poids de référence en cas d’inclusion et affiché sur la fiche de l’animal ; migration `034_terrarium_capture_weight.sql`), et peuvent être inclus aussitôt dans une expérimentation (contrôles de l’autorisation de projet et des animaux autorisés, poids de référence).
- **Rappels** : `process_reminders.php` signale une fois par semaine au plus les recensements et contrôles sanitaires en retard selon les fréquences du terrarium (30 et 90 jours par défaut, 0 = pas de rappel).
- Les journaux, traitements et contrôles sanitaires figurent dans l’export brut des journaux (`log_export.php`).

## Sécurité du déploiement et mises à jour de la base

- **Fichiers servis** : le dossier de l’application est la racine du site. Le `.htaccess` refuse `.env` et les fichiers cachés, `composer.*`, les fichiers `.sql`, `.sh`, `.md`, les bibliothèques (`*_lib.php`, `dbcon.php`, `config.php`…) et les scripts cron, et répond 404 pour `vendor/`, `database/`, `tools/`, `setup/`, `tests/`, `docker/`, `docs/`, `lang/`, `storage/` et `uploads/`. `setup/setup.sh` et l’image Docker locale activent `AllowOverride All`. L’installation donne au compte MySQL de l’application les seuls droits sur les données, rend `.env` lisible par Apache seul et crée `uploads/` en 750.
- **Journaux en ajout seul** : le journal d’activité, les événements et mouvements des animaux, les événements de portées et de terrariums, les mouvements de stock, les corrections d’actes et de suivis refusent toute modification ou suppression au niveau de la base (déclencheurs, comme le registre sanitaire, les contrôles et le cycle de vie des cages). Seule la suppression d’un animal ou d’un utilisateur par un administrateur retire, par cascade, les lignes qui lui sont rattachées.
- **Correction d’un suivi expérimental** : enregistrer de nouveau un jour déjà saisi est une correction. Le motif est obligatoire ; les valeurs remplacées (poids, scores de la grille, observations, auteur et date de saisie) sont conservées et affichées dans la consultation du suivi (« Corrections de ce suivi »), le jour est marqué « corrigé » dans l’historique, et la correction est tracée dans le journal d’activité et l’export brut des journaux (migration `035_followup_revisions_append_only_logs.sql`).
- **Mises à jour de la base** : `php tools/migrate.php` applique, dans l’ordre, les migrations de `database/migrations` qui ne le sont pas encore et les enregistre dans la table `schema_migrations` (`--status` les liste). `database/schema.sql` crée une base à jour. Pour une base créée avant cet outil, indiquez une fois la dernière migration déjà appliquée : `php tools/migrate.php --baseline=034`. Les migrations créent des tables et des déclencheurs : renseignez `MIGRATION_DB_USERNAME` et `MIGRATION_DB_PASSWORD` (dans `.env` ou l’environnement) avec un compte administrateur de la base.
- **Corrections du registre des entrées et sorties** : un administrateur corrige l’entrée d’un animal (provenance, fournisseur, référence, date) ou sa sortie (code et donc statut, date, circonstances, destination) depuis sa fiche, avec un motif obligatoire. Les valeurs remplacées et les nouvelles sont conservées dans `record_corrections` (ajout seul) et affichées sur la fiche et le dossier BEA de l’animal. L’animal porte la mention « corrigé » dans le registre, et les corrections figurent dans l’export brut des journaux. Les inclusions en expérience clôturées par la sortie suivent sa date et son code (`corrections_lib.php`, migration `036_register_corrections_prescriptions_protected.sql`).
- **Prescriptions et administrations** : une prescription et ses animaux ne peuvent plus être modifiés ni supprimés au niveau de la base (seul le statut peut évoluer). Une administration de médicament ne peut pas être supprimée et ne peut être qu’annulée une fois, avec un motif ; l’administration d’origine reste telle qu’enregistrée.
