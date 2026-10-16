![Logo](https://myvivarium.online/images/logo1.jpg)

# MyVivarium

![Statut du projet](https://img.shields.io/badge/status-active-brightgreen) [![Licence LGPL](https://img.shields.io/badge/License-LGPL--3.0-blue.svg)](https://choosealicense.com/licenses/lgpl-3.0/)

[![Visiter le site de démonstration](https://img.shields.io/badge/Visit-Demo%20Site-blue?style=for-the-badge)](https://demo.myvivarium.online)

![PHP](https://img.shields.io/badge/php-%23777BB4.svg?&style=for-the-badge&logo=php&logoColor=white) ![HTML](https://img.shields.io/badge/html5-%23E34F26.svg?&style=for-the-badge&logo=html5&logoColor=white) ![CSS](https://img.shields.io/badge/css3-%231572B6.svg?&style=for-the-badge&logo=css3&logoColor=white) ![JavaScript](https://img.shields.io/badge/javascript-%23F7DF1E.svg?&style=for-the-badge&logo=javascript&logoColor=black) ![Font Awesome](https://img.shields.io/badge/font%20awesome-%23339AF0.svg?&style=for-the-badge&logo=font-awesome&logoColor=white) ![Bootstrap](https://img.shields.io/badge/bootstrap-%23563D7C.svg?&style=for-the-badge&logo=bootstrap&logoColor=white)

MyVivarium est une plateforme en ligne pour gérer efficacement votre animalerie. Elle propose notamment l’inscription des utilisateurs, la gestion des profils et du laboratoire, ainsi que la surveillance environnementale en temps réel grâce à des capteurs IoT.

## Sommaire
- [Fonctionnalités](#fonctionnalités)
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
   curl -O https://raw.githubusercontent.com/myvivarium/MyVivarium/main/setup/setup.sh
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
    git clone https://github.com/myvivarium/MyVivarium.git
    ```
    ou
    ```bash
    git clone git@github.com:myvivarium/MyVivarium.git
    ```
    ```bash
    cd MyVivarium
    ```

2. **Configurez l’environnement :**
    - Copiez `.env.example` vers `.env` :
        ```bash
        cp .env.example .env
        ```
    - Renseignez dans `.env` les paramètres de la base de données et du SMTP. Voir [Configuration](#configuration).

3. **Placez les fichiers du projet dans le dossier du serveur web :**
    - Déplacez tout le contenu du dossier MyVivarium dans le dossier public de votre serveur web (par ex. `public_html`, `www`) :
        ```bash
        mv * /chemin/vers/votre/public_html/
        cp .env /chemin/vers/votre/public_html/
        ```

4. **Installez les dépendances avec Composer :**
    ```bash
    composer install
    ```

5. **Créez la base de données :**
    - Connectez-vous à votre serveur MySQL :
        ```bash
        mysql -u votre_utilisateur -p
        ```
    - Créez la base et importez le schéma :
        ```sql
        CREATE DATABASE myvivarium;
        USE myvivarium;
        SOURCE /chemin/vers/votre/public_html/database/schema.sql;
        ```
    - Vous pouvez aussi utiliser une base de données existante :
        ```sql
        SOURCE /chemin/vers/votre/public_html/database/schema.sql;
        ```

6. **Programmez des tâches cron pour `send_email.php` et `process_reminders.php` :**
    Suivez les instructions du [blog Cloudways](https://www.cloudways.com/blog/schedule-cron-jobs-in-php/#run-a-cron-job-in-php) pour programmer une tâche cron exécutant `send_email.php`.

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
- `session_config.php` : paramètre des sessions sécurisées (cookies HttpOnly, transmission HTTPS uniquement, protection CSRF, expiration après 30 minutes). Voir les commentaires du fichier pour le déploiement.
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
- `experiment_cage_followup.php` : suivi expérimental groupé d’une cage en essai. Une telle cage ne peut pas être validée « RAS » lors de la tournée : elle n’est validée qu’une fois le suivi du jour saisi pour chacun de ses animaux en expérience (règle appliquée à partir de la migration `024_experimental_cage_round.sql`). Les actes expérimentaux du jour (et ceux en retard) de ses animaux peuvent y être confirmés pour toute la cage en une fois.
- `experiment_acts.php` : actes expérimentaux d’une expérience (injection, gavage, mise d’appât, anesthésie, analgésie, prélèvement, chirurgie, pesée de l’alimentation…), accessibles par le bouton « Actes » de chaque expérience. On choisit l’acte (substance / molécule, dose, voie, consignes), éventuellement un médicament du stock avec la quantité prélevée par animal, les informations à demander lors de la réalisation (ex. aliment restant en g), les dates (période et fréquence), puis on coche les animaux concernés — tous, par cage ou un par un — dans un tableau rangé par cage. Le jour prévu, l’acte est confirmé (réalisé / non réalisé avec motif) en le cochant dans le suivi journalier de l’animal ou de sa cage ; les actes en retard restent proposés. Un médicament est alors tracé comme une administration (sans ordonnance, dans le cadre de l’expérimentation) et débité du lot choisi. Un tableau animaux × dates présente l’état de chaque acte ; la tournée signale les cages ayant des actes à confirmer, et le dossier BEA de l’animal les reprend (migration `027_experiment_acts.sql`).
- `iot_sensors.php` : affiche les données des capteurs IoT de chaque salle dans des iframes.
- `annual_report.php` : bilan annuel d’utilisation des animaux (administrateurs et responsables BEA) : effectifs, procédures terminées dans l’année par sévérité réelle, utilisation par autorisation de projet, sorties, animaux non utilisés ; export CSV par tableau ou ZIP.
- `cage_dash.php` : tableau de bord commun à toutes les catégories de cages (hébergement, accouplement, expérimentation, toutes) : salle, rack, animaux vivants, recherche, filtre par salle et sélection groupée pour changer de salle / rack ou basculer entre hébergement et expérimentation. `hc_dash.php`, `bc_dash.php` et `ec_dash.php` l’ouvrent sur leur catégorie.
- `cage_label_select.php` / `cage_labels.php` : étiquettes de cages (A6, quatre par feuille A4) avec la même mise en page pour toutes les catégories ; sélection par catégorie et salle, ou impression d’une sélection depuis un tableau de bord. Les anciennes adresses `hc_/bc_slct_crd.php` et `hc_/bc_prnt_crd.php` redirigent ici.
- `cage_location.php` : applique un changement de salle / rack ou de catégorie à une ou plusieurs cages. Une cage d’hébergement devient aussi automatiquement une cage d’expérimentation (même numéro) lorsqu’un de ses animaux est inclus dans une expérience ou qu’un animal inclus y est déplacé.
- `cage_view_parts.php` : sections Emplacement et Animaux communes à toutes les pages de cage.
- `bc_dash.php` : tableau de bord des cages d’accouplement, avec recherche et pagination.
- `bc_addn.php` : création des cages d’accouplement et des données de portée associées.
- `bc_view.php` : affichage du détail d’une cage d’accouplement.
- `bc_edit.php` : modification d’une cage d’accouplement, y compris les portées et l’envoi de fichiers.
- `bc_drop.php` : suppression des cages d’accouplement et de leurs données.
- `hc_dash.php` : tableau de bord des cages d’hébergement.
- `hc_addn.php` : ajout de cages d’hébergement.
- `hc_view.php` : affichage du détail d’une cage d’hébergement.
- `hc_edit.php` : modification d’une cage d’hébergement.
- `hc_drop.php` : suppression des cages d’hébergement.
- `nt_app.php` : script principal de l’application de notes.
- `nt_add.php` : ajout de notes.
- `nt_edit.php` : modification de notes.
- `nt_rmv.php` : suppression de notes.
- `header.php` : en-tête et menu de navigation de l’application, avec le sélecteur de langue.
- `footer.php` : pied de page avec le nom du laboratoire et l’année en cours.
- `message.php` : affiche les messages de session sous forme d’alertes Bootstrap.
- `logout.php` : déconnecte l’utilisateur en détruisant la session et redirige vers la page de connexion.
- `delete_file.php` : supprime les fichiers envoyés, sur le serveur et dans la base de données.
- `export_data.php` : permet aux administrateurs d’exporter toutes les tables de la base en fichiers CSV.
- `log_export.php` : export des journaux bruts (activité, événements et mouvements des animaux, contrôles et cycle de vie des cages, portées, suivi sanitaire, actions de point limite, notifications, e-mails) tels qu’enregistrés, par journal en CSV ou tous en ZIP, avec filtre de dates ; réservé aux administrateurs et tracé dans le journal d’activité.
- `manage_tasks.php` : gestion des tâches (ajout, modification, suppression).
- `get_task.php` : renvoie le détail d’une tâche à partir de son identifiant.
- `send_email.php` : traite la file des e-mails en attente ; à programmer en tâche cron.
- `demo-banner.php` : affiche un bandeau en haut de page en mode démonstration.
- `demo-credentials.php` : affiche les identifiants administrateur de démonstration sur la page de connexion.
- `demo-disclaimer.php` : affiche l’avertissement de démonstration sur la page de connexion.
- `maintenance.php` : ajoute des entrées de maintenance aux cages, avec commentaire facultatif.
- `get_reminder.php` : renvoie les rappels programmés enregistrés dans la base de données.
- `manage_reminder.php` : gestion des rappels (ajout, modification, suppression).
- `process_reminders.php` : traite les rappels programmés et crée les tâches correspondantes ; à exécuter en tâche cron.
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
