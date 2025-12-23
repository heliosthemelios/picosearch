import pymysql  # Bibliothèque pour se connecter à MySQL
import requests  # Bibliothèque pour faire des requêtes HTTP
from bs4 import BeautifulSoup  # Bibliothèque pour parser le HTML/XML
from urllib.parse import urlparse, urljoin  # Utilitaires pour manipuler les URLs
from collections import deque  # File d'attente (FIFO) pour le crawling BFS
import time  # Pour les pauses entre les requêtes
import random  # Pour ajouter de la variabilité aux délais
import argparse  # Pour gérer les arguments en ligne de commande
from dotenv import load_dotenv  # Pour charger les variables d'environnement
import os  # Pour accéder aux variables d'environnement

# Charger les variables d'environnement depuis le fichier .env
# Cherche dans plusieurs emplacements possibles
env_paths = [
    '/home/documents/.env',  # Production
    os.path.join(os.path.dirname(__file__), '.env'),  # Local
]
for env_path in env_paths:
    if os.path.exists(env_path):
        load_dotenv(env_path)
        break

# --- MOTS CLÉS ---
# Ensemble de mots clés liés à l'art pour identifier les pages pertinentes
ART_KEYWORDS = {
    "art","musee","galerie","photographie","peinture","artiste","dessin", "gallery", "museum", "painting", "sculpture", "drawing",
    "exhibition", "artist", "illustration", "installation", "contemporary", "modern art", "fine art", "visual art", "art fair", "art show", "art collection",
    "art history", "art critique", "art review", "art blog", "art news", "art market", "art auction", "art event", "art festival",
    "art workshop", "art class", "art education", "art therapy", "street art", "digital art", "conceptual art", "performance art",
    "abstract art", "realism", "impressionism", "surrealism", "cubism", "pop art", "renaissance art", "baroque art", "romanticism"
}

# --- LISTE NOIRE (Pour éviter de perdre du temps sur ces sites) ---
# Domaines qui ne nous intéressent pas (réseaux sociaux, moteurs de recherche, etc.)
BLACKLIST = {
    #"linkedin.com", "facebook.com", "twitter.com", "instagram.com", 
    #"pinterest.com", "youtube.com", "google.com", "amazon.com", "t.co",
    #"wikipedia.org", "reddit.com", "tumblr.com", "flickr.com", "vk.com",
    #"etsy.com", "deviantart.com", "behance.net", "artstation.com"
}

# --- CONFIGURATION ---
MAX_QUEUE_SIZE = 500  # Taille maximale de la file d'attente
MAX_PER_SITE = 30  # Nombre maximal de pages à crawler par domaine

# Initialise la connexion à la base de données MySQL et crée la table si elle n'existe pas
def init_db_mysql():
    # Connexion à la base de données MySQL avec les identifiants depuis .env
    conn = pymysql.connect(
        host=os.getenv('DB_HOST', 'localhost'),
        user=os.getenv('DB_USER', 'root'),
        password=os.getenv('DB_PASSWORD'),
        database=os.getenv('DB_NAME', 'pico'),
        charset="utf8mb4", cursorclass=pymysql.cursors.Cursor, autocommit=True
    )
    cursor = conn.cursor()
    # Crée la table 'links' pour stocker les URLs, titres et extraits trouvés
    cursor.execute("""
        CREATE TABLE IF NOT EXISTS links (
            id INT AUTO_INCREMENT PRIMARY KEY,
            url TEXT UNIQUE,
            title TEXT,
            snippet TEXT
        )""")
    return conn, cursor

# Vérifie si un domaine (netloc) fait partie de la liste blanche
def whitelisted(netloc, whitelist):
    # Retourne True si le domaine correspond à l'un des domaines de la liste blanche
    return any(netloc.endswith(d) for d in whitelist)

# Vérifie si l'URL contient des chemins liés à l'art
def looks_like_art_url(url):
    # Cherche des mots clés d'art dans le chemin de l'URL
    return any(k in urlparse(url).path.lower() for k in ART_KEYWORDS)

# Détermine si une page HTML contient du contenu lié à l'art basé sur la densité de mots clés
def is_art_page(html):
    # Parse le HTML et extrait le texte
    soup = BeautifulSoup(html, "html.parser")
    text = soup.get_text(" ", strip=True).lower()
    words = text.split()
    if not words: return False
    # Compte le nombre de mots clés présents et calcule le ratio
    hits = sum(text.count(k) for k in ART_KEYWORDS)
    # Retourne True si plus de 0.2% des mots sont des mots clés d'art
    return hits / len(words) > 0.002

# Extrait un extrait pertinent du HTML (autour d'un mot clé d'art)
def extract_snippet(html, max_len=300):
    # Parse le HTML et extrait le texte
    soup = BeautifulSoup(html, "html.parser")
    text = soup.get_text(" ", strip=True)
    # Cherche le premier mot clé d'art et retourne un extrait autour
    for keyword in ART_KEYWORDS:
        index = text.lower().find(keyword)
        if index != -1:
            return text[max(0, index - 80):min(len(text), index + 220)].strip()
    # Si aucun mot clé trouvé, retourne les premiers caractères
    return text[:max_len].strip()

# Charge les URLs de départ depuis un fichier texte et construit la liste blanche
def load_seeds(filepath):
    # Initialise les listes pour les URLs de départ et la liste blanche
    start_urls, whitelist = [], set()
    # Lit le fichier ligne par ligne
    with open(filepath, encoding="utf-8") as f:
        for raw in f:
            line = raw.strip()
            # Ignore les lignes vides et les commentaires
            if line and not line.startswith("#"):
                # Ajoute "https://" si le protocole est absent
                url = line if line.startswith("http") else "https://" + line
                start_urls.append(url)
                # Ajoute le domaine à la liste blanche
                whitelist.add(urlparse(url).netloc)
    return start_urls, whitelist

# Fonction principale de crawling : parcourt les pages web à partir desURLs de départ
def crawl(start_urls, whitelist):
    # Initialise la base de données
    conn, cur = init_db_mysql()
    # Récupère les URLs déjà visitées depuis la DB
    cur.execute("SELECT url FROM links")
    visited = {row[0] for row in cur.fetchall()}
    
    # File d'attente (deque) pour le crawling BFS
    queue = deque(start_urls)
    # Ensemble pour éviter les doublons dans la queue
    queued = set(start_urls)
    # Compte le nombre de pages visitées par domaine (pour respecter MAX_PER_SITE)
    counts_per_site = {} 

    # Boucle principale : traite chaque URL de la queue
    while queue:
        url = queue.popleft()
        # Saute si déjà visitée
        if url in visited: continue
        
        # Extrait le domaine de l'URL
        domain = urlparse(url).netloc
        
        # FILTRE 1 : Blacklist - ignore les domaines blacklistés
        if any(social in domain for social in BLACKLIST):
            continue
            
        # FILTRE 2 : Limite par site - ne crawle pas plus de MAX_PER_SITE par domaine
        if counts_per_site.get(domain, 0) >= MAX_PER_SITE:
            continue

        # Marque l'URL comme visitée
        visited.add(url)
        print(f"Crawling: {url} | Site: {counts_per_site.get(domain, 0)+1}/{MAX_PER_SITE}")

        try:
            # Effectue une requête HTTP avec timeout et user-agent personnalisé
            r = requests.get(url, timeout=10, headers={"User-Agent": "pico-spider/1.0 (+https://picosearch.ca )"})
            # Ignore les réponses non 200
            if r.status_code != 200: continue

            html = r.text
            # Filtre : n'accepte que les pages contenant du contenu d'art
            if not is_art_page(html): continue

            # Incrémente le compteur pour ce domaine
            counts_per_site[domain] = counts_per_site.get(domain, 0) + 1

            # Parse la page et extrait le titre
            soup = BeautifulSoup(html, "html.parser")
            title = soup.title.string.strip() if soup.title else "Sans titre"
            # Extrait un snippet pertinent
            snippet = extract_snippet(html)

            # Insère l'URL, le titre et l'extrait dans la base de données (IGNORE les doublons)
            cur.execute(
                "INSERT IGNORE INTO links (url, title, snippet) VALUES (%s, %s, %s)",
                (url, title, snippet)
            )

            # Traite tous les liens trouvés dans la page
            for a in soup.find_all("a", href=True):
                # Convertit les URLs relatives en absolues
                link = urljoin(url, a["href"])
                netloc = urlparse(link).netloc

                # Ne pas ajouter si blacklisté ou si quota atteint
                if not any(s in netloc for s in BLACKLIST):
                    if counts_per_site.get(netloc, 0) < MAX_PER_SITE:
                        # Ajoute le lien si : whitelisted OU ressemble à une URL d'art
                        if (whitelisted(netloc, whitelist) or looks_like_art_url(link)):
                            # Ajoute à la queue si pas déjà visitée/en queue et queue pas pleine
                            if link not in visited and link not in queued:
                                if len(queue) < MAX_QUEUE_SIZE:
                                    queue.append(link)
                                    queued.add(link)

            # Attente aléatoire pour respecter le serveur et éviter le bannissement
            time.sleep(random.uniform(0.5, 1.0))

        except Exception:
            # Continue en cas d'erreur (timeout, parsing, etc.)
            continue

    # Ferme la connexion à la base de données
    cur.close()
    conn.close()
    print("Terminé.")

# Point d'entrée du script
if __name__ == "__main__":
    # Analyse les arguments en ligne de commande
    parser = argparse.ArgumentParser()
    # Argument optionnel : chemin du fichier de graines (par défaut: seeds.txt)
    parser.add_argument("--seeds", default="seeds.txt")
    args = parser.parse_args()
    try:
        # Charge les URLs de départ et la liste blanche
        urls, white = load_seeds(args.seeds)
        # Démarre le crawling
        crawl(urls, white)
    except Exception as e:
        print(f"Erreur: {e}")