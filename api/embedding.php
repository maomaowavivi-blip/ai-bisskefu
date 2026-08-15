<?php
// api/embedding.php
// 向量嵌入服务 - MiniMax Embedding API
//
// POST /api/embedding.php?action=vectorize       向量化单条知识
// POST /api/embedding.php?action=batch_vectorize  批量向量化
// POST /api/embedding.php?action=search           语义搜索

require_once __DIR__ . '/config.php';

define('EMBEDDING_MODEL', 'embo-01');

function getEmbeddingApiKey() {
    // v3.7:优先读 Qwen/DashScope key,保留 MiniMax fallback 兼容
    try {
        $db = getDB();
        $key = trim(strval(pcGet($db, 'ai.api_key.qwen_embedding', '')));
        if ($key) return $key;
        // 兼容旧路径
        $key = trim(strval(pcGet($db, 'ai.api_key.minimax_backup', '')));
        if ($key) return $key;
        $key = trim(strval(pcGet($db, 'ai.api_key', '')));
        if ($key) return $key;
    } catch (Exception $e) {}
    // 最后兼容 .env
    return envVal('MINIMAX_API_KEY', '');
}

function callEmbeddingAPI(array $texts, string $type = 'db'): array {
    $apiKey = getEmbeddingApiKey();
    if (!$apiKey) {
        throw new RuntimeException('AI未配置：请在 .env 或 platform_config 配置 MINIMAX_API_KEY');
    }

    // v3.7:支持多 provider - 按 DB 配置切换;优先 DashScope(Qwen),保留 MiniMax fallback
    try {
        $db = getDB();
        $provider = trim(strval(pcGet($db, 'ai.embedding_provider', 'dashscope')));
        $apiUrl = trim(strval(pcGet($db, 'ai.embedding_api_url', 'https://dashscope.aliyuncs.com/compatible-mode/v1/embeddings')));
        $model = trim(strval(pcGet($db, 'ai.embedding_model', 'text-embedding-v3')));
    } catch (Exception $e) {
        // 没有 DB 时使用默认值
        $provider = 'dashscope';
        $apiUrl = 'https://dashscope.aliyuncs.com/compatible-mode/v1/embeddings';
        $model = 'text-embedding-v3';
    }

    if ($provider === 'dashscope') {
        // 阿里兼容 OpenAI 协议
        $body = [
            'model' => $model,
            'input' => $texts,                       // 字段名是 input 不是 texts
            'encoding_format' => 'float',
        ];
    } else {
        // 原 MiniMax 兼容路径
        $body = ['model' => $model, 'texts' => $texts, 'type' => $type];
    }

    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
    ]);

    $resp = curl_exec($ch);
    $errNo = curl_errno($ch);
    $errStr = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!$resp || $errNo) {
        throw new RuntimeException('Embedding请求失败：' . $errStr);
    }
    if ($httpCode !== 200) {
        throw new RuntimeException('Embedding API返回异常：HTTP ' . $httpCode . ' ' . substr($resp, 0, 200));
    }

    $json = json_decode($resp, true);
    if (!is_array($json)) {
        throw new RuntimeException('Embedding返回非JSON');
    }

    if ($provider === 'dashscope') {
        // DashScope 返回格式:{ data: [{embedding: [...]}] }
        if (!isset($json['data']) || !is_array($json['data'])) {
            throw new RuntimeException('Embedding API错误：' . substr($resp, 0, 200));
        }
        $vectors = [];
        foreach ($json['data'] as $d) {
            $vectors[] = $d['embedding'] ?? [];
        }
    } else {
        // MiniMax 返回格式:{ vectors: [...] }
        if (isset($json['base_resp']['status_code']) && $json['base_resp']['status_code'] !== 0) {
            throw new RuntimeException('Embedding API错误：' . ($json['base_resp']['status_msg'] ?? '未知错误'));
        }
        $vectors = $json['vectors'] ?? [];
    }

    if (empty($vectors)) {
        throw new RuntimeException('Embedding返回为空');
    }

    return $vectors;
}

function cosineSimilarity(array $vecA, array $vecB): float {
    // v3.7:防御性维度检查 - 不同维度直接返 0,避免混存 1024/1536 时算错
    if (count($vecA) !== count($vecB)) {
        return 0.0;
    }
    $dot = 0;
    $normA = 0;
    $normB = 0;
    $len = count($vecA);
    for ($i = 0; $i < $len; $i++) {
        $a = floatval($vecA[$i] ?? 0);
        $b = floatval($vecB[$i] ?? 0);
        $dot += $a * $b;
        $normA += $a * $a;
        $normB += $b * $b;
    }
    $denom = sqrt($normA) * sqrt($normB);
    return $denom > 0 ? $dot / $denom : 0;
}

/**
 * 进程内 KB 语义检索（供 chat.php 直接调用，避免 HTTP 自调用）
 *
 * @return array{list:array,source:string}
 */
function kbSemanticSearch(PDO $db, string $query, int $limit = 5, float $threshold = 0.5): array {
    $query = trim($query);
    if ($query === '') {
        return ['list' => [], 'source' => 'empty'];
    }

    require_once __DIR__ . '/PromptEngine.php';

    try {
        $vectors = callEmbeddingAPI([$query], 'query');
        $queryVec = $vectors[0];
    } catch (Throwable $e) {
        error_log('kbSemanticSearch embed: ' . $e->getMessage());
        return ['list' => PromptEngine::searchKnowledge($db, $query, $limit), 'source' => 'fallback'];
    }

    $stmt = $db->query("SELECT id, question, answer, keywords, embedding_vector, hit_count FROM kb_entries WHERE status = 1 AND embedding_vector IS NOT NULL AND embedding_vector != ''");
    $entries = $stmt->fetchAll();
    if (empty($entries)) {
        return ['list' => PromptEngine::searchKnowledge($db, $query, $limit), 'source' => 'fallback'];
    }

    $scored = [];
    foreach ($entries as $entry) {
        $vec = json_decode($entry['embedding_vector'], true);
        if (!is_array($vec)) {
            continue;
        }
        $score = cosineSimilarity($queryVec, $vec);
        if ($score >= $threshold) {
            $scored[] = [
                'id' => intval($entry['id']),
                'question' => $entry['question'],
                'answer' => $entry['answer'],
                'keywords' => $entry['keywords'],
                'score' => round($score, 4),
                'hit_count' => intval($entry['hit_count']),
            ];
        }
    }
    usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);
    return ['list' => array_slice($scored, 0, $limit), 'source' => 'semantic'];
}

if (basename($_SERVER['SCRIPT_NAME'] ?? '') !== 'embedding.php') {
    return;
}

$action = $_GET['action'] ?? '';
$body   = getBody();
$db     = getDB();

if ($action === 'vectorize') {
    $id = intval($body['id'] ?? 0);
    if (!$id) fail('参数错误：缺少条目ID');

    $stmt = $db->prepare("SELECT id, question, answer, keywords FROM kb_entries WHERE id = ? AND status = 1");
    $stmt->execute([$id]);
    $entry = $stmt->fetch();
    if (!$entry) fail('条目不存在或已禁用');

    $text = $entry['question'];
    if ($entry['keywords']) $text .= ' ' . $entry['keywords'];
    if ($entry['answer'])   $text .= ' ' . strip_tags($entry['answer']);

    try {
        $vectors = callEmbeddingAPI([mb_substr($text, 0, 2000)]);
    } catch (Throwable $e) {
        fail($e->getMessage(), 500);
    }

    $stmt = $db->prepare("UPDATE kb_entries SET embedding_vector = ?, embedding_updated_at = NOW() WHERE id = ?");
    $stmt->execute([json_encode($vectors[0], JSON_UNESCAPED_UNICODE), $id]);

    ok(['id' => $id], '向量化成功');
}

if ($action === 'batch_vectorize') {
    // v3.7:阿里 text-embedding-v3 batch 上限 10(实测),原 50 改 10
    $stmt = $db->query("SELECT id, question, answer, keywords FROM kb_entries WHERE status = 1 AND (embedding_vector IS NULL OR embedding_vector = '') ORDER BY id ASC LIMIT 10");
    $entries = $stmt->fetchAll();

    if (empty($entries)) ok(['count' => 0, 'msg' => '所有条目已向量化']);

    $texts = [];
    $idMap = [];
    foreach ($entries as $entry) {
        $text = $entry['question'];
        if ($entry['keywords']) $text .= ' ' . $entry['keywords'];
        if ($entry['answer'])   $text .= ' ' . strip_tags($entry['answer']);
        $texts[] = mb_substr($text, 0, 2000);
        $idMap[] = $entry['id'];
    }

    try {
        $vectors = callEmbeddingAPI($texts);
    } catch (Throwable $e) {
        fail($e->getMessage(), 500);
    }

    $updateStmt = $db->prepare("UPDATE kb_entries SET embedding_vector = ?, embedding_updated_at = NOW() WHERE id = ?");
    foreach ($idMap as $i => $id) {
        if (isset($vectors[$i])) {
            $updateStmt->execute([json_encode($vectors[$i], JSON_UNESCAPED_UNICODE), $id]);
        }
    }

    ok(['count' => count($idMap)], '向量化完成：共 ' . count($idMap) . ' 条');
}

if ($action === 'search') {
    $query = trim($body['query'] ?? '');
    $limit = min(10, max(1, intval($body['limit'] ?? 5)));
    $threshold = floatval($body['threshold'] ?? 0.5);

    if (!$query) fail('请输入搜索内容');

    $result = kbSemanticSearch($db, $query, $limit, $threshold);
    ok(['list' => $result['list'], 'source' => $result['source']]);
}

fail('未知操作');
