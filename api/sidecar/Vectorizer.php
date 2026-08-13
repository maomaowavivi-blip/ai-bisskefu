<?php

require_once dirname(__DIR__) . '/SidecarConfig.php';
require_once __DIR__ . '/ChunkBuilder.php';
require_once __DIR__ . '/SidecarSearch.php';

class Vectorizer {
    public static function vectorizePending(PDO $db, int $batchSize = 50): array {
        ChunkBuilder::stats($db);
        $stmt = $db->prepare("SELECT id, chunk_text FROM ai_knowledge_chunk
            WHERE embedding_status = 'pending' ORDER BY id ASC LIMIT ?");
        $stmt->bindValue(1, $batchSize, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();
        if (!$rows) return ['count' => 0, 'msg' => '无待向量化条目'];

        $texts = [];
        $ids = [];
        foreach ($rows as $row) {
            $texts[] = mb_substr($row['chunk_text'], 0, 2000);
            $ids[] = (int)$row['id'];
            $db->prepare("INSERT INTO ai_vector_task (chunk_id, task_status) VALUES (?, 'pending')")->execute([(int)$row['id']]);
        }

        try {
            $vectors = SidecarSearch::embedTexts($texts, 'db');
        } catch (Exception $e) {
            foreach ($ids as $id) {
                $db->prepare("UPDATE ai_knowledge_chunk SET embedding_status = 'failed' WHERE id = ?")->execute([$id]);
                $db->prepare("UPDATE ai_vector_task SET task_status = 'failed', error_message = ? WHERE chunk_id = ? ORDER BY id DESC LIMIT 1")
                    ->execute([$e->getMessage(), $id]);
            }
            throw $e;
        }

        $model = envVal('MINIMAX_EMBEDDING_MODEL', 'embo-01');
        $update = $db->prepare('UPDATE ai_knowledge_chunk SET embedding_vector = ?, embedding_model = ?, embedding_status = "done", embedding_updated_at = NOW() WHERE id = ?');
        $n = 0;
        foreach ($ids as $i => $id) {
            if (!isset($vectors[$i])) continue;
            $update->execute([json_encode($vectors[$i], JSON_UNESCAPED_UNICODE), $model, $id]);
            $db->prepare("UPDATE ai_vector_task SET task_status = 'done' WHERE chunk_id = ? ORDER BY id DESC LIMIT 1")->execute([$id]);
            $n++;
        }
        return ['count' => $n];
    }
}
