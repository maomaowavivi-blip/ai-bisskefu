<?php
/**
 * api/helpers/order_candidate.php
 *
 * v3.15.4 — 统一订单号提取入口
 *
 * 设计:把"从一段文字里找订单号"这件事抽出来,所有文本来源
 *   (text 原文 / OCR 识别文本 / 后续 ASR 文本)
 * 都过同一个闸门,行为一致。
 *
 * 失败 fail-open: 即使把手机号/身份证误识成订单号,宿家 API
 * 也会因订单不存在返 null,走兜底文案,不泄露任何凭证。
 */

declare(strict_types=1);

if (!function_exists('extractOrderCandidate')) {
    /**
     * 从任意文本中提取首个 8-30 位连续数字串作为订单候选
     *
     * @param string $text 输入文本(text 原文 / OCR 识别结果 / ASR 转写)
     * @return string  订单号候选(8-30 位数字);无则空字符串
     */
    function extractOrderCandidate(string $text): string
    {
        if ($text === '') {
            return '';
        }
        // 8-30 位连续数字串,允许被任意字符包围(中文/标点/空格)
        // \b 在 PHP PCRE 下也对中文字符有效
        if (preg_match('/\b(\d{8,30})\b/', $text, $m) === 1) {
            return $m[1];
        }
        return '';
    }
}

if (!function_exists('ocrImageFromUrl')) {
    /**
     * 下载企微图片并调本地 RapidOCR 识别文字
     *
     * v3.15.4:图片消息不再被直接 skip,改为下载 + OCR + 复用闸门
     *
     * 流程:
     *   1. pic_url 是企微的临时图片链接(3 天有效),直接 curl 下载
     *   2. base64 编码后 POST 到 127.0.0.1:9003/ocr
     *   3. 提取所有识别行,拼接成纯文本
     *
     * 失败行为:任何一步失败都返空字符串 + 错误日志,不抛异常
     *   → 上层走 LLM 路径,客户至少收到文字回复(可能无关但比装死好)
     *
     * @param \PDO $db DB 连接(预留:未来可能从 DB 读 OCR 服务地址,目前硬编码)
     * @param string $picUrl 企微回调里的 image.pic_url
     * @param string $from 客户 external_userid(仅用于日志)
     * @return string OCR 识别出的纯文本(多行用 \n 拼接);失败返 ''
     */
    function ocrImageFromUrl(\PDO $db, string $picUrl, string $from): string
    {
        if ($picUrl === '') return '';

        $ocrEndpoint = 'http://127.0.0.1:9003/ocr';
        $tmpFile = '';

        try {
            // 1. 下载企微临时图片
            $tmpFile = tempnam(sys_get_temp_dir(), 'kf_ocr_');
            $ch = curl_init($picUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
            ]);
            $imgData = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200 || $imgData === false || strlen($imgData) < 100) {
                error_log("[ocrImageFromUrl] download failed http=$httpCode size=" . strlen($imgData));
                return '';
            }
            // 企微客服图片大小限制 10MB
            if (strlen($imgData) > 10 * 1024 * 1024) {
                error_log("[ocrImageFromUrl] image too large size=" . strlen($imgData));
                return '';
            }
            file_put_contents($tmpFile, $imgData);

            // 2. base64 + data URL(rapidocr_web 要求 data:image/...;base64,XXX 格式)
            $b64 = base64_encode($imgData);
            // 简单识别 mime — 企微客服图片通常是 jpg/png
            $mime = 'image/png';
            if (str_starts_with($imgData, "\xFF\xD8\xFF")) $mime = 'image/jpeg';
            elseif (str_starts_with($imgData, "\x89PNG")) $mime = 'image/png';
            elseif (str_starts_with($imgData, "GIF8")) $mime = 'image/gif';
            elseif (str_starts_with($imgData, "RIFF") && substr($imgData, 8, 4) === 'WEBP') $mime = 'image/webp';
            $dataUrl = "data:$mime;base64,$b64";

            // 3. POST 到 RapidOCR
            $ch = curl_init($ocrEndpoint);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_POSTFIELDS => json_encode(['file' => $dataUrl]),
                CURLOPT_TIMEOUT => 30,
                CURLOPT_CONNECTTIMEOUT => 3,
            ]);
            $resp = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr = curl_error($ch);
            curl_close($ch);

            if ($httpCode !== 200 || !$resp) {
                error_log("[ocrImageFromUrl] rapidocr http=$httpCode err=$curlErr");
                return '';
            }

            $data = json_decode($resp, true);
            // rapidocr_web 返回 {"rec_res": "[[0, \"text\", \"score\"], ...]"}
            // rec_res 是字符串,需二次解析
            $recResStr = $data['rec_res'] ?? '';
            if ($recResStr === '') return '';
            $rows = json_decode($recResStr, true);
            if (!is_array($rows)) return '';

            $lines = [];
            foreach ($rows as $row) {
                // row 格式: [idx, text, score]
                if (is_array($row) && isset($row[1]) && is_string($row[1]) && $row[1] !== '') {
                    $lines[] = $row[1];
                }
            }
            $text = implode("\n", $lines);
            error_log("[ocrImageFromUrl] ok user=" . substr(sha1($from), 0, 10)
                . " lines=" . count($lines) . " text_len=" . strlen($text));
            return $text;

        } catch (\Throwable $e) {
            error_log("[ocrImageFromUrl] exception: " . $e->getMessage());
            return '';
        } finally {
            if ($tmpFile !== '' && file_exists($tmpFile)) {
                @unlink($tmpFile);
            }
        }
    }
}