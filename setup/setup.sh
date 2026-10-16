#!/bin/bash

# === Variables - Set your repository and application directory ===
REPO_URL="https://github.com/myvivarium/MyVivarium.git"
APP_DIR="/var/www/html"
DB_NAME="myvivarium"
DB_USER="myvivarium"
DB_PASSWORD=""

# === Start of Script ===
echo "Bienvenue dans le script de déploiement de MyVivarium !"
echo

# Inform user of fixed database name and user
echo "Nom de base de données fixé à '$DB_NAME' et utilisateur de la base à '$DB_USER'."
echo

# === Prompt for Required Details ===
read -sp "Saisissez le mot de passe de la base de données : " DB_PASSWORD
echo
echo

read -p "Saisissez votre e-mail (pour le certificat SSL et le SMTP) : " EMAIL
echo

read -p "Saisissez votre nom de domaine (ou l’IP du serveur si vous n’utilisez pas de domaine) : " DOMAIN
echo

# === Step 0: Create deployuser if it doesn't exist ===
if id "deployuser" &>/dev/null; then
    echo "L’utilisateur 'deployuser' existe déjà."
else
    echo "Création de l’utilisateur 'deployuser'…"
    sudo adduser --disabled-password --gecos "" deployuser
    sudo usermod -aG www-data deployuser
    sudo chown -R deployuser:www-data $APP_DIR
    echo "Utilisateur 'deployuser' créé et configuré."
fi
echo

# === Step 1: Check and Install Required Packages ===
echo "Vérification et installation des paquets nécessaires…"
declare -a packages=("git" "composer" "unzip" "apache2" "mysql-server" "certbot" "python3-certbot-apache")
for pkg in "${packages[@]}"; do
    if ! dpkg -l | grep -q $pkg; then
        echo "Installation de $pkg…"
        sudo apt install -y $pkg
    else
        echo "$pkg est déjà installé."
    fi
done
echo

# === Step 2: Clone the Repository if Needed ===
if [ ! -f "$APP_DIR/setup/setup.sh" ]; then
    echo "Clonage du dépôt depuis $REPO_URL…"
    sudo rm -rf $APP_DIR/*  # Clear any default files
    sudo git clone $REPO_URL $APP_DIR
    echo "Dépôt cloné dans $APP_DIR."
else
    echo "Dépôt déjà cloné dans $APP_DIR."
fi
cd $APP_DIR
echo

# === Step 3: Install Composer Dependencies ===
echo "Installation des dépendances Composer…"
sudo -u deployuser composer install --no-interaction --no-scripts
echo

# === Step 4: Configure Environment Variables ===
if [ ! -f ".env" ]; then
    echo "Création du fichier .env à partir de l’exemple…"
    cp .env.example .env
fi
echo

# === Step 5: Update .env with Database and SMTP Configurations ===
echo "Configuration de la base de données et du SMTP dans le fichier .env…"
sed -i "s/DB_DATABASE=.*/DB_DATABASE=$DB_NAME/" .env
sed -i "s/DB_USERNAME=.*/DB_USERNAME=$DB_USER/" .env
sed -i "s/DB_PASSWORD=.*/DB_PASSWORD=$DB_PASSWORD/" .env

echo "Mise à jour des paramètres SMTP dans le fichier .env…"
echo

# Prompt for SMTP settings and update or add them in .env
read -p "Saisissez l’hôte SMTP (ex. smtp.gmail.com) : " SMTP_HOST
sed -i "s/^SMTP_HOST=.*/SMTP_HOST=$SMTP_HOST/" .env || echo "SMTP_HOST=$SMTP_HOST" >> .env
echo

read -p "Saisissez le port SMTP (ex. 587) : " SMTP_PORT
sed -i "s/^SMTP_PORT=.*/SMTP_PORT=$SMTP_PORT/" .env || echo "SMTP_PORT=$SMTP_PORT" >> .env
echo

read -p "Saisissez l’identifiant SMTP (ex. $EMAIL) : " SMTP_USERNAME
sed -i "s/^SMTP_USERNAME=.*/SMTP_USERNAME=$SMTP_USERNAME/" .env || echo "SMTP_USERNAME=$SMTP_USERNAME" >> .env
echo

read -sp "Saisissez le mot de passe SMTP : " SMTP_PASSWORD
echo
sed -i "s/^SMTP_PASSWORD=.*/SMTP_PASSWORD=$SMTP_PASSWORD/" .env || echo "SMTP_PASSWORD=$SMTP_PASSWORD" >> .env
echo

read -p "Saisissez le chiffrement SMTP (ex. tls) : " SMTP_ENCRYPTION
sed -i "s/^SMTP_ENCRYPTION=.*/SMTP_ENCRYPTION=$SMTP_ENCRYPTION/" .env || echo "SMTP_ENCRYPTION=$SMTP_ENCRYPTION" >> .env
echo

read -p "Saisissez l’adresse e-mail de l’expéditeur (ex. $EMAIL) : " SENDER_EMAIL
sed -i "s/^SENDER_EMAIL=.*/SENDER_EMAIL=$SENDER_EMAIL/" .env || echo "SENDER_EMAIL=$SENDER_EMAIL" >> .env
echo

read -p "Saisissez le nom de l’expéditeur (ex. MyVivarium) : " SENDER_NAME
sed -i "s/^SENDER_NAME=.*/SENDER_NAME=$SENDER_NAME/" .env || echo "SENDER_NAME=$SENDER_NAME" >> .env
echo

# === Step 6: Set Up MySQL Database and Import Schema ===
echo "Création de la base MySQL '$DB_NAME' avec l’utilisateur '$DB_USER'…"
sudo mysql -u root <<MYSQL_SCRIPT
CREATE DATABASE IF NOT EXISTS $DB_NAME;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASSWORD';
GRANT ALL PRIVILEGES ON $DB_NAME.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
MYSQL_SCRIPT
echo

echo "Import du schéma de la base de données…"
if [ -f "database/schema.sql" ]; then
    sudo mysql -u $DB_USER -p$DB_PASSWORD $DB_NAME < database/schema.sql
    echo "Schéma de la base de données importé."
else
    echo "Erreur : fichier schema.sql introuvable dans le dossier /database."
fi
echo

# === Step 7: Set Permissions ===
echo "Configuration des permissions pour Apache…"
sudo chown -R www-data:www-data $APP_DIR
sudo chmod -R 755 $APP_DIR
echo

# === Step 8: Set Up Apache Virtual Host ===
echo "Configuration de l’hôte virtuel Apache pour $DOMAIN…"
VHOST_CONFIG="/etc/apache2/sites-available/$DOMAIN.conf"
sudo bash -c "cat > $VHOST_CONFIG" <<EOL
<VirtualHost *:80>
    ServerAdmin $EMAIL
    ServerName $DOMAIN
    DocumentRoot $APP_DIR

    <Directory $APP_DIR>
        Options FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog \${APACHE_LOG_DIR}/$DOMAIN_error.log
    CustomLog \${APACHE_LOG_DIR}/$DOMAIN_access.log combined
</VirtualHost>
EOL

sudo a2ensite $DOMAIN.conf
sudo systemctl reload apache2
echo

# === Step 9: Obtain SSL Certificate (Optional) ===
if [ "$DOMAIN" != "" ]; then
    echo "Obtention du certificat SSL pour $DOMAIN…"
    sudo certbot --apache --non-interactive --agree-tos -m $EMAIL -d $DOMAIN
fi

sudo systemctl reload apache2

# === Step 10: Set Up Cron Jobs for PHP Scripts ===
echo "Configuration des tâches cron exécutant send_email.php et process_reminders.php chaque minute…"

# Define paths for the PHP scripts
SEND_EMAIL_PATH="$APP_DIR/send_email.php"
PROCESS_REMINDERS_PATH="$APP_DIR/process_reminders.php"

# Add cron jobs
(crontab -l 2>/dev/null; echo "* * * * * /usr/bin/php $SEND_EMAIL_PATH > /dev/null 2>&1") | crontab -
(crontab -l 2>/dev/null; echo "* * * * * /usr/bin/php $PROCESS_REMINDERS_PATH > /dev/null 2>&1") | crontab -
(crontab -l 2>/dev/null; echo "*/5 * * * * /usr/bin/php $APP_DIR/daily_round_alert.php > /dev/null 2>&1") | crontab -

echo "Les tâches cron sont configurées."
echo

echo
echo "Déploiement terminé ! Votre application est accessible à :"
echo " - http://$DOMAIN (sans SSL)"
echo " - https://$DOMAIN (avec SSL si activé)"
