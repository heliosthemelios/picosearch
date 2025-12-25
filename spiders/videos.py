import requests
from bs4 import BeautifulSoup
import mysql.connector
from urllib.parse import urljoin, urlparse
import os
import time
from dotenv import load_dotenv
from langdetect import detect, LangDetectException

# 1. Chargement de l'environnement (identique à sites.py)
env_paths = [
    '/var/www/.env', 
    os.path.join(os.path.dirname(__file__), '..', '.env'),
    os.path.join(os.path.dirname(__file__), '.env'),
]
for env_path in env_paths:
    if os.path.exists(env_path):
        load_dotenv(env_path)
        break

def is_french_or_english(html):
    """Vérifie si la page est en FR ou EN."""
    try:
        soup = BeautifulSoup(html, "html.parser")
        text = soup.get_text(" ", strip=True)
        if len(text) < 50: return False
        lang = detect(text)
        return lang in ['fr', 'en']
    except:
        return False

def crawl_videos():
    db_config = {
        'host': os.getenv('DB_HOST', 'localhost'),
        'user': os.getenv('DB_USER', 'root'),
        'password': os.getenv('DB_PASSWORD'),
        'database': os.getenv('DB_NAME', 'pico')
    }

    # Mots-clés ciblés pour l'art et les vlogs
    ART_KEYWORDS = ['vlog', 'portfolio', 'studio', 'art process', 'peinture', 'dessin', 'artiste', 'exposition']
    HEADERS = {'User-Agent': os.getenv('USER_AGENT', 'pico-video-spider/1.0')}

    try:
        conn = mysql.connector.connect(**db_config)
        cursor = conn.cursor()

        # Création de la table dédiée aux vidéos
        cursor.execute("""
            CREATE TABLE IF NOT EXISTS videos (
                id INT AUTO_INCREMENT PRIMARY KEY,
                url TEXT UNIQUE,
                title VARCHAR(255),
                platform VARCHAR(50),
                page_url TEXT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        """)

        # Lecture des sources dans seeds.txt
        seeds = []
        seeds_path = os.path.join(os.path.dirname(__file__), 'seeds.txt')
        if os.path.exists(seeds_path):
            with open(seeds_path, 'r') as f:
                seeds = [line.strip() for line in f if line.strip() and not line.startswith('[')]

        for seed_url in seeds:
            print(f"🔍 Analyse : {seed_url}")
            try:
                res = requests.get(seed_url, headers=HEADERS, timeout=10)
                if res.status_code != 200: continue
                
                html = res.text
                # FILTRE 1 : Langue
                if not is_french_or_english(html): continue
                
                # FILTRE 2 : Mots-clés (Pertinence Art)
                if not any(kw in html.lower() for kw in ART_KEYWORDS): continue

                soup = BeautifulSoup(html, 'html.parser')
                page_title = soup.title.string.strip() if soup.title else seed_url

                # Extraction des iframes vidéo
                for iframe in soup.find_all('iframe', src=True):
                    src = iframe['src']
                    video_url = None
                    platform = None

                    if 'youtube.com/embed/' in src or 'youtube-nocookie.com/embed/' in src:
                        video_url = src.split('?')[0]
                        platform = 'YouTube'
                    elif 'player.vimeo.com/video/' in src:
                        video_url = src.split('?')[0]
                        platform = 'Vimeo'
                    elif 'dailymotion.com/embed/video/' in src:
                        video_url = src.split('?')[0]
                        platform = 'Dailymotion'

                    if video_url:
                        # On ajoute le préfixe https: si manquant (//www...)
                        if video_url.startswith('//'): video_url = 'https:' + video_url
                        
                        cursor.execute(
                            "INSERT IGNORE INTO videos (url, title, platform, page_url) VALUES (%s, %s, %s, %s)",
                            (video_url, page_title, platform, seed_url)
                        )
                        conn.commit()
                        print(f"  ✅ Vidéo {platform} ajoutée !")

            except Exception as e:
                print(f"  ❌ Erreur sur {seed_url}: {e}")

    finally:
        if 'conn' in locals() and conn.is_connected():
            cursor.close()
            conn.close()

if __name__ == "__main__":
    crawl_videos()