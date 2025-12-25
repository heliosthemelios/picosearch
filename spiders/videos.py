import requests
from bs4 import BeautifulSoup
import mysql.connector
from urllib.parse import urljoin, urlparse, quote
import os
import time
import json
import re
from collections import deque
from dotenv import load_dotenv
from langdetect import detect, LangDetectException

# Chargement de l'environnement
env_paths = ['/var/www/.env', os.path.join(os.path.dirname(__file__), '..', '.env'), os.path.join(os.path.dirname(__file__), '.env')]
for env_path in env_paths:
    if os.path.exists(env_path):
        load_dotenv(env_path)
        break

def is_french_or_english(text):
    """Vérifie si le texte est en FR ou EN."""
    try:
        if len(text) < 50: return False
        lang = detect(text)
        return lang in ['fr', 'en']
    except:
        return False

def search_youtube_art(headers):
    """Recherche des vidéos de nombreux termes artistiques sur YouTube."""
    videos = []
    search_keywords = [
        'art', 'vlog art', 'parcours artiste', 'tutorial art', 'speedpaint', 
        'digital art', 'speedart', 'timelapse art', 'drawing tutorial', 'painting tutorial',
        'art process', 'making of art', 'artist studio', 'creative process', 'illustration'

    ]
    
    try:
        search_url = "https://www.youtube.com/results"
        
        for keyword in search_keywords:
            params = {'search_query': keyword}
            
            print(f"🎬 Recherche YouTube pour '{keyword}'...")
            try:
                res = requests.get(search_url, params=params, headers=headers, timeout=7)
                if res.status_code == 200:
                    soup = BeautifulSoup(res.text, 'html.parser')
                    
                    # Méthode 1 : Extraction via les attributs data-video-id
                    for video_elem in soup.find_all(attrs={'data-video-id': True}):
                        video_id = video_elem.get('data-video-id')
                        if video_id:
                            video_url = f"https://www.youtube.com/watch?v={video_id}"
                            if video_url not in videos:
                                videos.append(video_url)
                    
                    # Méthode 2 : Extraction via les liens href contenant /watch?v=
                    for a in soup.find_all('a', href=True):
                        href = a['href']
                        if '/watch?v=' in href:
                            # Extraire l'URL complète
                            video_url = urljoin('https://www.youtube.com', href)
                            if video_url not in videos:
                                videos.append(video_url)
                    
                    # Méthode 3 : Extraction du JSON des données initiales
                    for script in soup.find_all('script'):
                        if script.string:
                            try:
                                # Chercher les videoId dans le script
                                script_text = script.string
                                if 'videoId' in script_text:
                                    # Extraire tous les videoId avec une regex
                                    video_ids = re.findall(r'"videoId":"([a-zA-Z0-9_-]{11})"', script_text)
                                    for vid in video_ids:
                                        video_url = f"https://www.youtube.com/watch?v={vid}"
                                        if video_url not in videos:
                                            videos.append(video_url)
                            except:
                                pass
                    
                    time.sleep(1)  # Pause plus longue entre les requêtes
            except Exception as e:
                print(f"  ⚠ Erreur pour '{keyword}': {e}")
        
        # Suppression des doublons et limite à 1000 vidéos au total
        videos = list(dict.fromkeys(videos))[:1000]
        print(f"  ✓ {len(videos)} vidéos trouvées sur YouTube au total\n")
    except Exception as e:
        print(f"  ❌ Erreur YouTube: {e}\n")
    
    return videos

def search_vimeo_art(headers):
    """Recherche des vidéos art sur Vimeo."""
    videos = []
    try:
        search_url = "https://vimeo.com/search"
        params = {'q': 'art', 'sort': 'relevant'}
        
        print("🎬 Recherche Vimeo pour 'art'...")
        res = requests.get(search_url, params=params, headers=headers, timeout=7)
        if res.status_code == 200:
            soup = BeautifulSoup(res.text, 'html.parser')
            
            # Recherche des liens de vidéos Vimeo
            for a in soup.find_all('a', href=True):
                href = a['href']
                if '/video/' in href and not any(x in href for x in ['#', 'sort', 'page']):
                    video_url = urljoin('https://vimeo.com', href)
                    if video_url not in videos:
                        videos.append(video_url)
            
            # Limite à 10 vidéos
            videos = videos[:10]
            print(f"  ✓ {len(videos)} vidéos trouvées sur Vimeo")
    except Exception as e:
        print(f"  ❌ Erreur Vimeo: {e}")
    
    return videos

def search_dailymotion_art(headers):
    """Recherche des vidéos art sur Dailymotion."""
    videos = []
    try:
        search_url = "https://www.dailymotion.com/search/art"
        
        print("🎬 Recherche Dailymotion pour 'art'...")
        res = requests.get(search_url, headers=headers, timeout=7)
        if res.status_code == 200:
            soup = BeautifulSoup(res.text, 'html.parser')
            
            # Recherche des vidéos Dailymotion
            for a in soup.find_all('a', href=True):
                href = a['href']
                if '/video/' in href:
                    video_url = urljoin('https://www.dailymotion.com', href)
                    if video_url not in videos and 'dailymotion.com/video' in video_url:
                        videos.append(video_url)
            
            # Limite à 10 vidéos
            videos = videos[:10]
            print(f"  ✓ {len(videos)} vidéos trouvées sur Dailymotion")
    except Exception as e:
        print(f"  ❌ Erreur Dailymotion: {e}")
    
    return videos

def crawl_videos():
    db_config = {
        'host': os.getenv('DB_HOST', 'localhost'),
        'user': os.getenv('DB_USER', 'root'),
        'password': os.getenv('DB_PASSWORD'),
        'database': os.getenv('DB_NAME', 'pico')
    }

    ART_KEYWORDS = ['vlog', 'portfolio', 'studio', 'art process', 'peinture', 'dessin', 'artiste', 'exposition', 'gallery']
    HEADERS = {'User-Agent': os.getenv('USER_AGENT', 'pico-video-spider/1.0')}
    
    # --- CONFIGURATION DU CRAWLING ---
    MAX_LINKS_TOTAL = 1000  # Limite de 1000 liens au total
    links_processed = 0
    
    try:
        conn = mysql.connector.connect(**db_config)
        cursor = conn.cursor()

        # Initialisation de la table
        cursor.execute("""
            CREATE TABLE IF NOT EXISTS videos (
                id INT AUTO_INCREMENT PRIMARY KEY,
                url TEXT UNIQUE,
                title VARCHAR(255),
                platform VARCHAR(50),
                page_url TEXT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        """)

        # ===== PHASE 1 : RECHERCHE DIRECTE SUR LES PLATEFORMES DE ART =====
        print("\n" + "="*60)
        print("PHASE 1 : Recherche de vidéos ART sur les plateformes")
        print("="*60 + "\n")
        
        art_videos = []
        
        # Recherche YouTube
        youtube_videos = search_youtube_art(HEADERS)
        for video_url in youtube_videos:
            try:
                cursor.execute(
                    "INSERT IGNORE INTO videos (url, title, platform, page_url) VALUES (%s, %s, %s, %s)",
                    (video_url, 'art - YouTube', 'YouTube', 'search_art')
                )
                conn.commit()
                art_videos.append(video_url)
                links_processed += 1
            except:
                pass
        
        # Recherche Vimeo
        vimeo_videos = search_vimeo_art(HEADERS)
        for video_url in vimeo_videos:
            try:
                cursor.execute(
                    "INSERT IGNORE INTO videos (url, title, platform, page_url) VALUES (%s, %s, %s, %s)",
                    (video_url, 'art - Vimeo', 'Vimeo', 'search_art')
                )
                conn.commit()
                art_videos.append(video_url)
                links_processed += 1
            except:
                pass
        
        # Recherche Dailymotion
        dailymotion_videos = search_dailymotion_art(HEADERS)
        for video_url in dailymotion_videos:
            try:
                cursor.execute(
                    "INSERT IGNORE INTO videos (url, title, platform, page_url) VALUES (%s, %s, %s, %s)",
                    (video_url, 'art - Dailymotion', 'Dailymotion', 'search_art')
                )
                conn.commit()
                art_videos.append(video_url)
                links_processed += 1
            except:
                pass
        
        print(f"\n✅ {len(art_videos)} vidéos ART trouvées au total\n")

        # ===== PHASE 2 : CRAWL TRADITIONNEL =====
        print("="*60)
        print("PHASE 2 : Crawl traditionnel des sites seeds")
        print("="*60 + "\n")

        # Chargement des seeds
        seeds = []
        seeds_path = os.path.join(os.path.dirname(__file__), 'seeds.txt')
        if os.path.exists(seeds_path):
            with open(seeds_path, 'r') as f:
                seeds = [line.strip() for line in f if line.strip() and not line.startswith('[')]

        queue = deque(seeds)
        visited = set()

        while queue and links_processed < MAX_LINKS_TOTAL:
            current_url = queue.popleft()
            if current_url in visited: continue
            
            visited.add(current_url)
            links_processed += 1
            print(f"🔍 [{links_processed}/{MAX_LINKS_TOTAL}] Analyse : {current_url}")

            try:
                res = requests.get(current_url, headers=HEADERS, timeout=7)
                if res.status_code != 200: continue
                
                soup = BeautifulSoup(res.text, 'html.parser')
                page_text = soup.get_text(" ", strip=True)

                # FILTRE : Langue et Pertinence
                if not is_french_or_english(page_text): continue
                if not any(kw in page_text.lower() for kw in ART_KEYWORDS): continue

                page_title = soup.title.string.strip() if soup.title else current_url

                # 1. Extraction des vidéos (Iframes)
                for iframe in soup.find_all('iframe', src=True):
                    src = iframe['src']
                    video_url = None
                    platform = None

                    if 'youtube' in src: platform = 'YouTube'
                    elif 'vimeo' in src: platform = 'Vimeo'
                    elif 'dailymotion' in src: platform = 'Dailymotion'

                    if platform:
                        if src.startswith('//'): src = 'https:' + src
                        video_url = src.split('?')[0]
                        cursor.execute(
                            "INSERT IGNORE INTO videos (url, title, platform, page_url) VALUES (%s, %s, %s, %s)",
                            (video_url, page_title, platform, current_url)
                        )
                        conn.commit()

                # 2. Extraction des nouveaux liens (pour continuer le crawl)
                # On ne reste que sur le même domaine pour éviter de s'éparpiller
                domain = urlparse(current_url).netloc
                for a in soup.find_all('a', href=True):
                    link = urljoin(current_url, a['href'])
                    if urlparse(link).netloc == domain and link not in visited:
                        if len(queue) < (MAX_LINKS_TOTAL - links_processed):
                            queue.append(link)

                time.sleep(0.5) # Pause pour respecter le serveur

            except Exception as e:
                print(f"  ❌ Erreur : {e}")

    finally:
        if 'conn' in locals() and conn.is_connected():
            cursor.close()
            conn.close()

if __name__ == "__main__":
    crawl_videos()