# PicoSearch 🎨

PicoSearch est un moteur de recherche spécialisé dans l'art et la culture, avec une fonctionnalité de recherche de texte et d'images. Il utilise des web crawlers personnalisés pour indexer des pages web et des images liées au domaine artistique.

## 🌟 Fonctionnalités

- **Recherche textuelle** : Recherche de pages web indexées avec mots-clés multiples
- **Recherche d'images** : Découverte et indexation d'images d'art
- **Crawling intelligent** : Utilise des mots-clés spécifiques au domaine artistique pour cibler les pages pertinentes
- **Respect du robots.txt** : Le spider respecte les règles d'exclusion des sites web
- **Interface web simple et élégante** : Interface utilisateur en PHP avec design épuré

## 📋 Prérequis

- **Python 3.x**
- **PHP 7.4+**
- **MySQL/MariaDB**
- **Serveur web** (Apache, Nginx, ou serveur PHP intégré)

### Dépendances Python

```bash
pip install pymysql requests beautifulsoup4 mysql-connector-python python-dotenv
```

## 🚀 Installation

### 1. Cloner le projet

```bash
git clone https://github.com/votre-username/picosearch.git
cd picosearch
```

### 2. Configuration de la base de données

Créez une base de données MySQL :

```sql
CREATE DATABASE pico CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

### 3. Configuration des variables d'environnement

Le projet utilise un fichier `.env` pour sécuriser les identifiants de base de données.

#### Développement local

```bash
# Copier le fichier d'exemple
cp .env.example .env

# Éditer le fichier .env avec vos identifiants
nano .env
```

Contenu du fichier `.env` :
```env
DB_HOST=localhost
DB_NAME=pico
DB_USER=votre_utilisateur
DB_PASSWORD=votre_mot_de_passe
USER_AGENT=pico-spider/1.0 (+https://votresite.com)
```

#### Déploiement en production

**⚠️ IMPORTANT pour la sécurité :**

Le fichier `.env` doit être placé **hors du répertoire web** pour éviter tout accès public.

```bash
# 1. Copier les fichiers PHP dans le répertoire web
sudo cp index.php images.php styles.css env_loader.php /var/www/html/

# 2. Copier les scripts Python où vous voulez (ex: home)
cp spider.py spiderimage.py ~/picosearch/

# 3. Créer le fichier .env HORS du répertoire web
sudo nano /var/www/.env
```

Ajoutez vos identifiants dans `/var/www/.env` :
```env
DB_HOST=localhost
DB_NAME=pico
DB_USER=votre_utilisateur
DB_PASSWORD=votre_mot_de_passe_securise
USER_AGENT=pico-spider/1.0 (+https://votresite.com)
```

```bash
# 4. Sécuriser les permissions du fichier .env
sudo chmod 600 /var/www/.env
sudo chown www-data:www-data /var/www/.env

# 5. Créer le dossier images avec les bonnes permissions
sudo mkdir -p /var/www/html/images
sudo chown www-data:www-data /var/www/html/images
sudo chmod 775 /var/www/html/images

# 6. Copier le fichier .htaccess pour bloquer l'accès aux fichiers sensibles
sudo cp .htaccess /var/www/html/

# 7. VÉRIFIER que .env n'est PAS accessible publiquement
curl https://votresite.com/.env
# Doit retourner 403 ou 404
```

### 4. Structure de déploiement recommandée

```
/var/www/
├── .env                       ← Fichier de configuration (SÉCURISÉ, hors web)
└── html/                      ← Répertoire web public
    ├── .htaccess             ← Protection des fichiers sensibles
    ├── index.php             ← Interface de recherche
    ├── images.php            ← Interface de recherche d'images
    ├── env_loader.php        ← Chargeur de variables d'environnement
    ├── styles.css
    └── images/               ← Images téléchargées

/home/utilisateur/picosearch/  ← Scripts Python (peuvent être ailleurs)
├── spider.py
└── spiderimage.py
```

### 5. Lancer le serveur (développement local)

```bash
php -S localhost:8000
```

Accédez ensuite à `http://localhost:8000/index.php`

## 📖 Utilisation

### Crawler de texte (spider.py)

Lance le crawler pour indexer des pages web :

```bash
# Depuis le répertoire où se trouve spider.py
python3 spider.py --seed "https://www.example-art-site.com"

# Crawl avec nombre maximum de pages
python3 spider.py --seed "https://www.example-art-site.com" --max-pages 100

# Depuis n'importe où (si dans /home/utilisateur/picosearch/)
python3 ~/picosearch/spider.py --seed "https://www.example-art-site.com"
```

**Options disponibles :**
- `--seed` : URL de départ pour le crawling
- `--max-pages` : Nombre maximum de pages à crawler (défaut: pas de limite)

**Note :** Le script cherche automatiquement le fichier `.env` dans `/var/www/.env` en production ou dans son propre répertoire en local.

### Crawler d'images (spiderimage.py)

Lance le crawler pour télécharger et indexer des images :

```bash
python3 spiderimage.py
```

Le script :
- Lit les URLs de départ depuis la table `links`
- Télécharge les images pertinentes
- Stocke les images dans le dossier `/var/www/html/images/`
- Enregistre les métadonnées dans la table `images`

**Note :** Assurez-vous que l'utilisateur exécutant le script a les permissions d'écriture dans `/var/www/html/images/`

### Interface de recherche

1. **Recherche textuelle** : Accédez à `index.php`
   - Saisissez vos mots-clés séparés par des espaces
   - Les résultats combinent la recherche dans le titre, l'URL et le snippet

2. **Recherche d'images** : Accédez à `images.php`
   - Recherchez parmi les images indexées
   - Visualisez les images avec leurs informations

## 🗂️ Structure du projet

### Développement local
```
picosearch/
├── .env                   # Variables d'environnement (NON versionné)
├── .env.example           # Template de configuration
├── .gitignore            # Fichiers à ignorer par Git
├── .htaccess             # Protection Apache
├── spider.py             # Crawler de pages web
├── spiderimage.py        # Crawler d'images
├── index.php             # Interface de recherche textuelle
├── images.php            # Interface de recherche d'images
├── env_loader.php        # Chargeur de variables d'environnement
├── styles.css            # Feuilles de style
├── debug_env.php         # Script de débogage (à supprimer en production)
├── migrate_unique_index.php # Script de migration de base de données
├── README.md             # Documentation
└── images/               # Dossier des images téléchargées
```

### Production (serveur web)
```
/var/www/
├── .env                       ← Configuration sécurisée (HORS répertoire web)
└── html/
    ├── .htaccess
    ├── index.php
    ├── images.php
    ├── env_loader.php
    ├── styles.css
    └── images/

/home/utilisateur/picosearch/  ← Scripts Python
├── spider.py
└── spiderimage.py
```

## 🗄️ Schéma de base de données

### Table `links`
Stocke les pages web indexées :
- `id` : Identifiant unique
- `url` : URL de la page (unique)
- `title` : Titre de la page
- `snippet` : Extrait du contenu

### Table `images`
Stocke les images indexées :
- `id` : Identifiant unique
- `url` : URL source de l'image
- `alt_text` : Texte alternatif
- `filename` : Nom du fichier local
- `page_url` : URL de la page contenant l'image

## 🎯 Mots-clés ciblés

Le moteur se concentre sur le domaine artistique avec des mots-clés comme :
- Art, musée, galerie
- Photographie, peinture, sculpture
- Artiste, exposition, installation
- Art contemporain, art moderne
- Et bien d'autres termes liés à l'art

## 🚫 Liste noire

Pour éviter de crawler des sites non pertinents, ces domaines sont exclus :
- Réseaux sociaux (Facebook, Twitter, Instagram, etc.)
- Moteurs de recherche
- Sites de commerce en ligne

## ⚙️ Configuration avancée

### Paramètres du crawler (spider.py)

```python
MAX_QUEUE_SIZE = 100   # Taille max de la file d'attente
MAX_PER_SITE = 30      # Pages max par domaine
```

### Paramètres du crawler d'images (spiderimage.py)

```python
IMAGES_PER_PAGE = 50   # Nombre max d'images par page
```

## 🔧 Migration de base de données

Pour appliquer des migrations sur la base de données, utilisez :

```bash
php migrate_unique_index.php
```

## 📝 Notes importantes

- **Respect des sites web** : Les crawlers respectent les fichiers `robots.txt` et incluent des délais entre les requêtes
- **Performance** : Pour de grandes quantités de données, envisagez d'indexer avec des services comme Elasticsearch
- **Sécurité** : 
  - ⚠️ **CRITIQUE** : Ne versionnez JAMAIS le fichier `.env` sur Git
  - Le fichier `.env` doit être placé **hors** du répertoire web public (`/var/www/` et non `/var/www/html/`)
  - Utilisez des mots de passe forts et uniques pour MySQL
  - Le fichier `.gitignore` est configuré pour protéger automatiquement `.env` et les fichiers sensibles
  - Vérifiez toujours que `https://votresite.com/.env` retourne 403/404
- **Permissions** :
  - `.env` doit avoir les permissions `600` (lecture/écriture propriétaire uniquement)
  - Le dossier `images/` doit être accessible en écriture par PHP et Python

## 🔒 Checklist de sécurité avant déploiement

- [ ] Le fichier `.env` est dans `/var/www/` (PAS dans `/var/www/html/`)
- [ ] Les permissions du `.env` sont `600`
- [ ] Le `.env` n'est PAS accessible via navigateur (test: `curl https://votresite.com/.env`)
- [ ] Le fichier `.env.example` ne contient PAS de vrais identifiants
- [ ] Les fichiers `.py` ne sont PAS dans `/var/www/html/`
- [ ] Le fichier `debug_env.php` a été supprimé du serveur
- [ ] Le `.htaccess` est présent dans `/var/www/html/`
- [ ] Git ignore bien le fichier `.env` (vérifier avec `git status`)

## 🤝 Contribution

Les contributions sont les bienvenues ! N'hésitez pas à ouvrir une issue ou soumettre une pull request.

## 📄 Licence

Ce projet est sous licence MIT - voir le fichier LICENSE pour plus de détails.

## 👤 Auteur

Eric Bertrand

## 🔗 Liens utiles

- [Documentation BeautifulSoup](https://www.crummy.com/software/BeautifulSoup/bs4/doc/)
- [Documentation Requests](https://docs.python-requests.org/)
- [Documentation PyMySQL](https://pymysql.readthedocs.io/)

---

⭐ Si ce projet vous plaît, n'hésitez pas à lui donner une étoile sur GitHub !
