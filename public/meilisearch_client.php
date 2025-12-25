<?php
/**
 * Client Meilisearch simplifié pour PicoSearch
 */

class MeilisearchClient {
    private $host;
    private $key;
    
    public function __construct($host, $key) {
        $this->host = rtrim($host, '/');
        $this->key = $key;
    }
    
    /**
     * Effectue une recherche dans un index
     * 
     * @param string $index_name Nom de l'index
     * @param string $query Texte de recherche
     * @param array $options Options supplémentaires (filter, limit, offset, etc.)
     * @return array Résultats de recherche
     */
    public function search($index_name, $query, $options = []) {
        $endpoint = "/indexes/$index_name/search";
        
        $data = [
            'q' => $query,
            'limit' => $options['limit'] ?? 20,
            'offset' => $options['offset'] ?? 0
        ];
        
        // Ajouter les filtres si présents
        if (isset($options['filter'])) {
            $data['filter'] = $options['filter'];
        }
        
        // Ajouter les attributs à récupérer
        if (isset($options['attributesToRetrieve'])) {
            $data['attributesToRetrieve'] = $options['attributesToRetrieve'];
        }
        
        $result = $this->request('POST', $endpoint, $data);
        
        return [
            'hits' => $result['hits'] ?? [],
            'total' => $result['estimatedTotalHits'] ?? 0,
            'processingTimeMs' => $result['processingTimeMs'] ?? 0
        ];
    }
    
    /**
     * Recherche avec plusieurs mots-clés (mode AND ou OR)
     * 
     * @param string $index_name Nom de l'index
     * @param string $query_string Chaîne de recherche (peut contenir plusieurs mots)
     * @param array $fields Champs dans lesquels chercher
     * @param string $mode 'or' ou 'and'
     * @param array $options Options supplémentaires
     * @return array Résultats de recherche
     */
    public function multiWordSearch($index_name, $query_string, $fields = [], $mode = 'or', $options = []) {
        // Meilisearch gère nativement les recherches multi-mots
        // Le mode AND/OR est géré par matchingStrategy
        
        $endpoint = "/indexes/$index_name/search";
        
        // Convertir offset/limit en page/hitsPerPage (offset est déprécié)
        $hitsPerPage = $options['hitsPerPage'] ?? $options['limit'] ?? 20;
        $page = 1;
        if (isset($options['offset']) && $options['offset'] > 0) {
            $page = (int)ceil(($options['offset'] + 1) / $hitsPerPage);
        } elseif (isset($options['page'])) {
            $page = (int)$options['page'];
            if ($page < 1) $page = 1;
        }
        
        $data = [
            'q' => $query_string,
            'page' => $page,
            'hitsPerPage' => $hitsPerPage,
            'matchingStrategy' => $mode === 'and' ? 'all' : 'last' // 'all' = AND, 'last' = OR
        ];
        
        // Attributs spécifiques à rechercher
        if (!empty($fields)) {
            $data['attributesToSearchOn'] = $fields;
        }
        
        // Filtres
        if (isset($options['filter'])) {
            $filters = $options['filter'];
            // Si c'est un array, combiner avec AND
            if (is_array($filters) && !empty($filters)) {
                $data['filter'] = implode(' AND ', $filters);
            } elseif (is_string($filters) && !empty($filters)) {
                $data['filter'] = $filters;
            }
        }
        
        $result = $this->request('POST', $endpoint, $data);
        
        return [
            'hits' => $result['hits'] ?? [],
            'total' => $result['estimatedTotalHits'] ?? 0,
            'processingTimeMs' => $result['processingTimeMs'] ?? 0
        ];
    }
    
    /**
     * Requête HTTP vers Meilisearch
     */
    private function request($method, $endpoint, $data = null) {
        $url = $this->host . $endpoint;
        $ch = curl_init($url);
        
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->key
        ];
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        
        if ($data !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($http_code >= 400) {
            error_log("Meilisearch error ($http_code): $response");
            return ['hits' => [], 'estimatedTotalHits' => 0];
        }
        
        return json_decode($response, true) ?? [];
    }
}
