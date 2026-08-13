<?php

require_once dirname(__DIR__) . '/SidecarConfig.php';

class SidecarSearch {
    public static function search(PDO $db, int $aiRoomId, string $question, bool $isVerified, int $limit = 5): array {
        $allowed = [SidecarConfig::PERM_PUBLIC];
        if ($isVerified) {
            $allowed[] = SidecarConfig::PERM_GUEST;
        }
        $in = implode(',', array_fill(0, count($allowed), '?'));
        $params = array_merge([$aiRoomId], $allowed);

        $semantic = self::semanticSearch($db, $aiRoomId, $question, $allowed, $limit);
        if (!empty($semantic)) return $semantic;

        $terms = self::terms($question);
        if (empty($terms)) return [];

        $conds = [];
        $p = [$aiRoomId];
        foreach ($allowed as $perm) $p[] = $perm;
        foreach ($terms as $t) {
            $conds[] = '(chunk_text LIKE ? OR chunk_title LIKE ?)';
            $like = '%' . $t . '%';
            $p[] = $like;
            $p[] = $like;
        }
        $p[] = $limit;
        $sql = "SELECT id, chunk_title, chunk_text, source_type, permission_level
                FROM ai_knowledge_chunk
                WHERE ai_room_id = ? AND permission_level IN ($in) AND (" . implode(' OR ', $conds) . ")
                ORDER BY id DESC LIMIT ?";
        $stmt = $db->prepare($sql);
        $stmt->execute($p);
        return $stmt->fetchAll() ?: [];
    }

    private static function semanticSearch(PDO $db, int $aiRoomId, string $question, array $allowed, int $limit): array {
        try {
            $vectors = self::embedTexts([$question], 'query');
            if (empty($vectors)) return [];
            $queryVec = $vectors[0];
        } catch (Exception $e) {
            return [];
        }

        $in = implode(',', array_fill(0, count($allowed), '?'));
        $params = array_merge([$aiRoomId], $allowed);
        $stmt = $db->prepare("SELECT id, chunk_title, chunk_text, source_type, permission_level, embedding_vector
            FROM ai_knowledge_chunk
            WHERE ai_room_id = ? AND permission_level IN ($in)
              AND embedding_status = 'done' AND embedding_vector IS NOT NULL AND embedding_vector != ''");
        $stmt->execute($params);
        $rows = $stmt->fetchAll() ?: [];
        $scored = [];
        foreach ($rows as $row) {
            $vec = json_decode($row['embedding_vector'], true);
            if (!is_array($vec)) continue;
            $score = self::cosine($queryVec, $vec);
            if ($score >= SidecarConfig::SEMANTIC_THRESHOLD) {
                $row['score'] = $score;
                $scored[] = $row;
            }
        }
        usort($scored, fn($a, $b) => ($b['score'] <=> $a['score']));
        return array_slice($scored, 0, $limit);
    }

    public static function embedTexts(array $texts, string $type = 'db'): array {
        require_once dirname(__DIR__) . '/config.php';
        $apiKey = envVal('MINIMAX_API_KEY', '');
        if (!$apiKey) {
            try {
                $main = getDB();
                $apiKey = trim(strval(pcGet($main, 'ai.api_key.minimax_backup', '')));
                if (!$apiKey) $apiKey = trim(strval(pcGet($main, 'ai.api_key', '')));
            } catch (Exception $e) {}
        }
        if (!$apiKey) throw new Exception('Embedding API Key 未配置（需 MiniMax Key）');

        $apiUrl = envVal('MINIMAX_EMBEDDING_URL', 'https://api.minimaxi.com/v1/embeddings');
        $model = envVal('MINIMAX_EMBEDDING_MODEL', 'embo-01');
        $ch = curl_init($apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['model' => $model, 'texts' => $texts, 'type' => $type], JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);
        $json = json_decode($resp, true);
        return $json['vectors'] ?? [];
    }

    private static function cosine(array $a, array $b): float {
        $dot = 0; $na = 0; $nb = 0;
        $len = min(count($a), count($b));
        for ($i = 0; $i < $len; $i++) {
            $x = floatval($a[$i]); $y = floatval($b[$i]);
            $dot += $x * $y; $na += $x * $x; $nb += $y * $y;
        }
        $d = sqrt($na) * sqrt($nb);
        return $d > 0 ? $dot / $d : 0;
    }

    private static function terms(string $q): array {
        $q = trim(mb_substr($q, 0, 100));
        $terms = preg_split('/[\s,，、？?！!。]+/u', $q);
        $terms = array_filter($terms, fn($t) => mb_strlen($t) >= 2);
        if (mb_strlen($q) > 2) {
            for ($i = 0; $i < mb_strlen($q) - 1; $i++) {
                $terms[] = mb_substr($q, $i, 2);
            }
        }
        return array_unique(array_values($terms));
    }
}
