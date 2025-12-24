"""
Script de migration pour ajouter la colonne 'langue' à la table 'links'
"""
import pymysql
from dotenv import load_dotenv
import os

# Charger les variables d'environnement depuis le fichier .env
env_paths = [
    '/home/documents/.env',  # Production
    os.path.join(os.path.dirname(__file__), '.env'),  # Local
]
for env_path in env_paths:
    if os.path.exists(env_path):
        load_dotenv(env_path)
        break

def migrate():
    """Ajoute la colonne 'langue' à la table 'links' si elle n'existe pas déjà"""
    try:
        # Connexion à la base de données
        conn = pymysql.connect(
            host=os.getenv('DB_HOST', 'localhost'),
            user=os.getenv('DB_USER', 'root'),
            password=os.getenv('DB_PASSWORD'),
            database=os.getenv('DB_NAME', 'pico'),
            charset="utf8mb4",
            cursorclass=pymysql.cursors.Cursor,
            autocommit=True
        )
        cursor = conn.cursor()
        
        # Vérifier si la colonne existe déjà
        cursor.execute("""
            SELECT COUNT(*) 
            FROM information_schema.COLUMNS 
            WHERE TABLE_SCHEMA = %s 
            AND TABLE_NAME = 'links' 
            AND COLUMN_NAME = 'langue'
        """, (os.getenv('DB_NAME', 'pico'),))
        
        exists = cursor.fetchone()[0]
        
        if exists:
            print("✓ La colonne 'langue' existe déjà dans la table 'links'")
        else:
            # Ajouter la colonne 'langue'
            print("Ajout de la colonne 'langue' à la table 'links'...")
            cursor.execute("""
                ALTER TABLE links 
                ADD COLUMN langue VARCHAR(10) DEFAULT NULL
            """)
            print("✓ Colonne 'langue' ajoutée avec succès!")
        
        cursor.close()
        conn.close()
        
    except Exception as e:
        print(f"❌ Erreur lors de la migration: {e}")
        raise

if __name__ == "__main__":
    migrate()
