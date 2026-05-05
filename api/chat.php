<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$config = is_file(__DIR__ . '/config.php') ? require __DIR__ . '/config.php' : [];
$apiKey = trim((string) ($config['openai_api_key'] ?? getenv('OPENAI_API_KEY') ?: ''));
$model = trim((string) ($config['model'] ?? 'gpt-5.4-mini'));

if ($apiKey === '') {
    http_response_code(500);
    echo json_encode(['error' => 'OpenAI API key is missing']);
    exit;
}

$rawBody = file_get_contents('php://input');
$payload = json_decode($rawBody ?: '', true);

if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON payload']);
    exit;
}

$message = trim((string) ($payload['message'] ?? ''));
$history = $payload['history'] ?? [];

if ($message === '') {
    http_response_code(422);
    echo json_encode(['error' => 'Message is required']);
    exit;
}

if (!is_array($history)) {
    $history = [];
}

$history = array_slice($history, -6);
$historyLines = [];

foreach ($history as $item) {
    if (!is_array($item)) {
        continue;
    }

    $role = ($item['role'] ?? '') === 'assistant' ? 'Assistant' : 'User';
    $content = trim((string) ($item['content'] ?? ''));

    if ($content !== '') {
        $historyLines[] = $role . ': ' . $content;
    }
}

$siteContext = implode("\n", [
    'You are Bimkidz AI, the assistant for Bima Junestifa portfolio website.',
    'Reply in Indonesian unless the user explicitly asks for another language.',
    'Be warm, concise, and helpful.',
    'You help visitors understand Bima Junestifa, a junior web developer.',
    'Relevant portfolio facts:',
    '- Name: Bima Junestifa.',
    '- Focus: junior web developer, UI practice, practical web projects, and AI-assisted workflow.',
    '- GitHub: https://github.com/bimajunestifa',
    '- LinkedIn: https://www.linkedin.com/in/bima-junestifa-9723b433a/',
    '- Instagram: https://www.instagram.com/bima_junestifa17/',
    '- Highlight projects include Website Toko Buku, Sistem Perpustakaan Sekolah, Aplikasi To-Do List, E-Commerce Sederhana, and Web Cafe Smart.',
    '- Certificates include Dicoding Git & GitHub and RevoU HTML, CSS, Tailwind, and JavaScript.',
    'If asked for contact or links, use the portfolio data above.',
    'If asked something outside the portfolio, still answer briefly and clearly.',
]);

$userPrompt = $siteContext . "\n\nRecent conversation:\n" . implode("\n", $historyLines) . "\n\nLatest user message:\n" . $message;

$requestBody = [
    'model' => $model,
    'input' => $userPrompt,
    'temperature' => 0.7,
    'max_output_tokens' => 300,
];

$ch = curl_init('https://api.openai.com/v1/responses');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey,
    ],
    CURLOPT_POSTFIELDS => json_encode($requestBody, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    CURLOPT_TIMEOUT => 45,
]);

$responseBody = curl_exec($ch);
$curlError = curl_error($ch);
$statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);

if ($responseBody === false) {
    http_response_code(502);
    echo json_encode(['error' => 'Failed to connect to OpenAI: ' . $curlError]);
    exit;
}

$responseData = json_decode($responseBody, true);

if ($statusCode >= 400) {
    $errorMessage = $responseData['error']['message'] ?? 'OpenAI request failed';
    http_response_code($statusCode);
    echo json_encode(['error' => $errorMessage]);
    exit;
}

$reply = '';

if (isset($responseData['output']) && is_array($responseData['output'])) {
    foreach ($responseData['output'] as $item) {
        if (($item['type'] ?? '') !== 'message' || !isset($item['content']) || !is_array($item['content'])) {
            continue;
        }

        foreach ($item['content'] as $contentPart) {
            $type = $contentPart['type'] ?? '';
            if (($type === 'output_text' || $type === 'text') && !empty($contentPart['text'])) {
                $reply .= (string) $contentPart['text'];
            }
        }
    }
}

if ($reply === '' && isset($responseData['output_text']) && is_string($responseData['output_text'])) {
    $reply = $responseData['output_text'];
}

$reply = trim($reply);

if ($reply === '') {
    http_response_code(502);
    echo json_encode(['error' => 'No text reply returned by OpenAI']);
    exit;
}

echo json_encode([
    'reply' => $reply,
    'model' => $model,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
