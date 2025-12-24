import requests
from bs4 import BeautifulSoup
import mysql.connector
from urllib.parse import urljoin, urlparse, urldefrag
from urllib.robotparser import RobotFileParser
import os
import time
from dotenv import load_dotenv

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

def crawl_spider():
    # --- CONFIGURATION ---
    db_config = {
        'host': os.getenv('DB_HOST', 'localhost'),
        'user': os.getenv('DB_USER', 'root'),
        'password': os.getenv('DB_PASSWORD'),
        'database': os.getenv('DB_NAME', 'pico')
    }
    
    # Mots-clés utilisés pour décider si une page ou une image est pertinente
    KEYWORDS = ['photographie', 'art', 'peinture', 'exposition', 'galerie', 'museum', 'artiste',
                'drawing', 'illustration', 'sculpture', 'contemporary', 'modern art', 'visual art',
                'art fair', 'art show', 'art collection', 'art history', 'art critique', 'art review',
                'art blog', 'art news', 'art market', 'art auction', 'art event', 'art festival',]
    # Header simple pour simuler un navigateur (éviter certains blocages basiques)
    HEADERS = {'User-Agent': os.getenv('USER_AGENT', 'pico-spider/1.0 (+https://picosearch.ca )')}
    # Nombre maximum d'images à télécharger par page
    IMAGES_PER_PAGE = 10
    
    # Structures pour gérer la file d'attente du crawler (to_visit) et les pages déjà vues (visited)
    # On utilise des `set` pour éviter les doublons et accélérer les tests d'appartenance
    to_visit = set()
    visited = set()
    seeds = []  # Liste séparée pour les seeds à traiter en priorité

    # Cache des parsers robots.txt par domaine
    robots_cache = {}

    def get_robots_parser_for(url: str) -> RobotFileParser:
        """Retourne (et met en cache) le parser robots.txt pour l'URL."""
        parsed = urlparse(url)
        scheme = parsed.scheme or 'http'
        netloc = parsed.netloc
        if not netloc:
            # Pour les URLs relatives, on ne peut pas résoudre sans base;
            # autoriser par défaut.
            rfp = RobotFileParser()
            rfp.parse([])
            return rfp
        if netloc in robots_cache:
            return robots_cache[netloc]
        robots_url = f"{scheme}://{netloc}/robots.txt"
        rfp = RobotFileParser()
        rfp.set_url(robots_url)
        try:
            rfp.read()
        except Exception:
            # Si robots.txt inaccessible, se comporte comme autorisé par défaut
            pass
        robots_cache[netloc] = rfp
        return rfp

    def is_allowed(url: str) -> bool:
        """Vérifie via robots.txt si l'URL est autorisée pour notre User-Agent."""
        rfp = get_robots_parser_for(url)
        ua = HEADERS.get('User-Agent', '*')
        try:
            return rfp.can_fetch(ua, url)
        except Exception:
            return True

    # 1. Charger les graines (seeds)
    if os.path.exists('seeds.txt'):
        with open('seeds.txt', 'r') as file:
            for line in file:
                url = line.strip()
                if url: seeds.append(url)
    else:
        print("❌ 'seeds.txt' introuvable.")
        return

    try:
        conn = mysql.connector.connect(**db_config)
        cursor = conn.cursor()
        # Connexion OK, on affiche combien de seeds ont été chargées
        print(f"✅ Connecté à MySQL. {len(seeds)} sites de départ.\n")

        # Ensure images table has title and alt columns (create table if missing)
        def ensure_images_schema():
            # Create base table if it doesn't exist
            try:
                cursor.execute("""
                    CREATE TABLE IF NOT EXISTS images (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        url TEXT UNIQUE,
                        data LONGBLOB
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
                """)
            except Exception:
                pass

            # Add title column if missing
            try:
                cursor.execute(
                    "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=%s AND TABLE_NAME='images' AND COLUMN_NAME='title'",
                    (db_config['database'],)
                )
                if cursor.fetchone()[0] == 0:
                    cursor.execute("ALTER TABLE images ADD COLUMN title VARCHAR(255) DEFAULT NULL")
            except Exception:
                pass

            # Add alt column if missing
            try:
                cursor.execute(
                    "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=%s AND TABLE_NAME='images' AND COLUMN_NAME='alt'",
                    (db_config['database'],)
                )
                if cursor.fetchone()[0] == 0:
                    cursor.execute("ALTER TABLE images ADD COLUMN alt VARCHAR(255) DEFAULT NULL")
            except Exception:
                pass

        ensure_images_schema()

        visited = set()
        pages_processed = 0
        limit = 5000  # Limite augmentée pour explorer plus largement après les seeds

        # Charger les URLs d'images déjà en base pour éviter de les re-télécharger (persistance inter-runs)
        seen_images = set()
        try:
            cursor.execute("SELECT url FROM images")
            for (u,) in cursor.fetchall():
                if u:
                    seen_images.add(u)
            if seen_images:
                print(f"🧠 Mémoire d'images chargée: {len(seen_images)} URL(s) déjà connues.")
        except Exception as e:
            print(f"⚠️ Impossible de charger la liste des images existantes: {e}")

        # PHASE 1 : Traiter TOUS les seeds en priorité
        print("📌 PHASE 1 : Traitement prioritaire des seeds...")
        for seed_url in seeds:
            if seed_url in visited:
                continue
            
            print(f"🔍 [{pages_processed+1}] Seed : {seed_url}")
            visited.add(seed_url)
            pages_processed += 1

            try:
                # Respecter robots.txt avant de récupérer la page seed
                if not is_allowed(seed_url):
                    print(f"   ⛔ Bloqué par robots.txt: {seed_url}")
                    continue

                time.sleep(0.5)
                response = requests.get(seed_url, headers=HEADERS, timeout=10)
                if response.status_code != 200:
                    continue
                
                soup = BeautifulSoup(response.text, 'html.parser')
                domain = urlparse(seed_url).netloc

                # --- Recherche d'images (limité à IMAGES_PER_PAGE) ---
                page_text = soup.get_text().lower()
                relevant_page = any(kw in page_text for kw in KEYWORDS)

                image_count = 0
                for img in soup.find_all('img'):
                    if image_count >= IMAGES_PER_PAGE:
                        break
                    img_url = img.get('src')
                    # raw values to store
                    img_alt_raw = img.get('alt') or None
                    img_title_raw = img.get('title') or None
                    # lowercase alt for keyword matching
                    img_alt = (img_alt_raw or "").lower()

                    if img_url and (relevant_page or any(kw in img_alt for kw in KEYWORDS)):
                        # Normaliser l'URL (résoudre relative + retirer fragment)
                        full_img_url = urldefrag(urljoin(seed_url, img_url))[0]

                        # Déduplication persistante: ne jamais repasser sur la même image
                        if full_img_url in seen_images:
                            continue

                        # Vérifier robots.txt pour l'image
                        if not is_allowed(full_img_url):
                            continue

                        try:
                            img_res = requests.get(full_img_url, headers=HEADERS, timeout=5)
                            if img_res.status_code == 200:
                                sql = "INSERT IGNORE INTO images (url, data, title, alt) VALUES (%s, %s, %s, %s)"
                                cursor.execute(sql, (full_img_url, img_res.content, img_title_raw, img_alt_raw))
                                conn.commit()
                                image_count += 1
                                seen_images.add(full_img_url)
                        except:
                            continue

                # --- Récupérer les liens pour exploration ultérieure ---
                for a_tag in soup.find_all('a', href=True):
                    link = a_tag['href']
                    full_link = urljoin(seed_url, link)

                    if urlparse(full_link).netloc == domain and full_link not in visited:
                        # Prefiltrer via robots.txt
                        if is_allowed(full_link):
                            to_visit.add(full_link)

            except Exception as e:
                print(f"   ❌ Erreur sur {seed_url}: {e}")

        # PHASE 2 : Explorer les liens trouvés jusqu'à la limite
        print(f"\n📌 PHASE 2 : Exploration des liens trouvés (limite : {limit} pages)...\n")
        while to_visit and pages_processed < limit:
            current_url = to_visit.pop()
            if current_url in visited:
                continue
            
            print(f"🔍 [{pages_processed+1}/{limit}] Exploration : {current_url}")
            visited.add(current_url)
            pages_processed += 1

            try:
                # Respecter robots.txt avant de récupérer la page
                if not is_allowed(current_url):
                    print(f"   ⛔ Bloqué par robots.txt: {current_url}")
                    continue

                time.sleep(0.5)
                response = requests.get(current_url, headers=HEADERS, timeout=10)
                if response.status_code != 200:
                    continue
                
                soup = BeautifulSoup(response.text, 'html.parser')
                domain = urlparse(current_url).netloc

                # --- Recherche d'images (limité à IMAGES_PER_PAGE) ---
                page_text = soup.get_text().lower()
                relevant_page = any(kw in page_text for kw in KEYWORDS)

                image_count = 0
                for img in soup.find_all('img'):
                    if image_count >= IMAGES_PER_PAGE:
                        break
                    img_url = img.get('src')
                    # raw values to store
                    img_alt_raw = img.get('alt') or None
                    img_title_raw = img.get('title') or None
                    # lowercase alt for keyword matching
                    img_alt = (img_alt_raw or "").lower()

                    if img_url and (relevant_page or any(kw in img_alt for kw in KEYWORDS)):
                        # Normaliser l'URL (résoudre relative + retirer fragment)
                        full_img_url = urldefrag(urljoin(current_url, img_url))[0]

                        # Déduplication persistante: ne jamais repasser sur la même image
                        if full_img_url in seen_images:
                            continue

                        # Vérifier robots.txt pour l'image
                        if not is_allowed(full_img_url):
                            continue

                        try:
                            img_res = requests.get(full_img_url, headers=HEADERS, timeout=5)
                            if img_res.status_code == 200:
                                sql = "INSERT IGNORE INTO images (url, data, title, alt) VALUES (%s, %s, %s, %s)"
                                cursor.execute(sql, (full_img_url, img_res.content, img_title_raw, img_alt_raw))
                                conn.commit()
                                image_count += 1
                                seen_images.add(full_img_url)
                        except:
                            continue

                # --- Récupérer les nouveaux liens ---
                for a_tag in soup.find_all('a', href=True):
                    link = a_tag['href']
                    full_link = urljoin(current_url, link)

                    if urlparse(full_link).netloc == domain and full_link not in visited:
                        # Prefiltrer via robots.txt
                        if is_allowed(full_link):
                            to_visit.add(full_link)

            except Exception as e:
                print(f"   ❌ Erreur sur {current_url}: {e}")

        # Résumé à la fin de l'exécution
        print(f"\n✨ Mission terminée ! {pages_processed} pages scannées ({len(seeds)} seeds + {pages_processed - len(seeds)} liens exploités).")

    except mysql.connector.Error as err:
        # Erreurs de connexion / requêtes MySQL
        print(f"❌ Erreur MySQL : {err}")
    finally:
        if 'conn' in locals() and conn.is_connected():
            cursor.close()
            conn.close()

if __name__ == "__main__":
    crawl_spider()
