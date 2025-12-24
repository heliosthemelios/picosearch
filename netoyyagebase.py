import mysql.connector
from langdetect import detect, detect_langs

# Configuration de la connexion
db_config = {
    'user': 'helios',
    'password': 'Je suis de la terre1.',
    'host': 'localhost',
    'database': 'pico'
}

def clean_database():
    try:
        conn = mysql.connector.connect(**db_config)
        cursor = conn.cursor(dictionary=True)

        # 1. Récupérer les données (id et le texte à analyser)
        cursor.execute("SELECT id, contenu_texte FROM sites")
        rows = cursor.fetchall()

        ids_a_supprimer = []

        for row in rows:
            texte = row['contenu_texte']
            
            if texte and len(texte.strip()) > 10:
                try:
                    langue = detect(texte)
                    # On garde uniquement 'fr' et 'en'
                    if langue not in ['fr', 'en']:
                        ids_a_supprimer.append(row['id'])
                except:
                    # Si la détection échoue, on peut choisir de supprimer par sécurité
                    ids_a_supprimer.append(row['id'])

        # 2. Suppression par lots (plus rapide)
        if ids_a_supprimer:
            format_strings = ','.join(['%s'] * len(ids_a_supprimer))
            query = f"DELETE FROM sites WHERE id IN ({format_strings})"
            cursor.execute(query, tuple(ids_a_supprimer))
            conn.commit()
            print(f"Suppression terminée : {len(ids_a_supprimer)} sites supprimés.")
        else:
            print("Aucun site à supprimer.")

    except mysql.connector.Error as err:
        print(f"Erreur : {err}")
    finally:
        if conn.is_connected():
            cursor.close()
            conn.close()

clean_database()