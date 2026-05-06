<?php
/**
 * rdstation-callback.php — Troca o code OAuth pelo par access_token / refresh_token
 *
 * Implante este arquivo em:
 *   https://medtronicdiabeteslatam.com/reposicao-sensor-br/rdstation-callback.php
 *
 * Fluxo de uso (uma única vez por instalação):
 *   1. Gere a URL de autorização no RD Station:
 *      https://app.rdstation.com.br/api/platform/auth/dialog
 *        ?client_id=7c563c52-753f-476b-8f0b-5095d79b3bf0
 *        &redirect_uri=https://medtronicdiabeteslatam.com/reposicao-sensor-br/rdstation-callback.php
 *      Abra esta URL no navegador como administrador da conta RD Station.
 *   2. O RD Station redireciona para este callback com ?code=XXXX
 *   3. Este script troca o code pelos tokens e os salva em rd_tokens.json
 *   4. A partir daí, submit.php renova automaticamente o token quando expirar.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

$config = [
    'rd_client_id'     => RD_CLIENT_ID,
    'rd_client_secret' => RD_CLIENT_SECRET,
    'rd_redirect_uri'  => RD_REDIRECT_URI,
    // Caminho absoluto para rd_tokens.json — ajuste conforme o servidor
    'tokens_file'      => __DIR__ . '/rd_tokens.json',
];

$code = $_GET['code'] ?? '';

if (empty($code)) {
    $authUrl = 'https://app.rdstation.com.br/api/platform/auth/dialog'
        . '?client_id=' . urlencode($config['rd_client_id'])
        . '&redirect_uri=' . urlencode($config['rd_redirect_uri']);

    echo '<!doctype html><html lang="pt-BR"><head><meta charset="UTF-8">'
       . '<title>RD Station — Autorização</title></head><body>'
       . '<h2>Autorização RD Station</h2>'
       . '<p>Clique no botão abaixo para autorizar o acesso da aplicação à conta do RD Station.</p>'
       . '<p><a href="' . htmlspecialchars($authUrl, ENT_QUOTES) . '" style="'
       . 'display:inline-block;padding:12px 24px;background:#2754ec;color:#fff;'
       . 'border-radius:6px;text-decoration:none;font-family:sans-serif;">'
       . 'Autorizar acesso ao RD Station</a></p>'
       . '</body></html>';
    exit;
}

// Troca o code pelo token
$ch = curl_init('https://api.rd.services/auth/token');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode([
        'client_id'     => $config['rd_client_id'],
        'client_secret' => $config['rd_client_secret'],
        'redirect_uri'  => $config['rd_redirect_uri'],
        'code'          => $code,
        'grant_type'    => 'authorization_code',
    ]),
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_SSL_VERIFYPEER => true,
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$tokens = json_decode($response ?: '{}', true);

if ($httpCode === 200 && !empty($tokens['access_token'])) {
    $tokens['expires_at'] = time() + (int)($tokens['expires_in'] ?? 86400);
    file_put_contents($config['tokens_file'], json_encode($tokens, JSON_PRETTY_PRINT), LOCK_EX);
    chmod($config['tokens_file'], 0600);

    echo '<!doctype html><html lang="pt-BR"><head><meta charset="UTF-8">'
       . '<title>Autorização concluída</title></head><body style="font-family:sans-serif;padding:40px;">'
       . '<h2 style="color:#2e7d32;">✅ Autorização concluída com sucesso!</h2>'
       . '<p>Os tokens foram salvos. O formulário já pode registrar leads no RD Station.</p>'
       . '<p><small>access_token expira em: '
       . date('d/m/Y H:i:s', $tokens['expires_at']) . '</small></p>'
       . '</body></html>';
} else {
    http_response_code(502);
    echo '<!doctype html><html lang="pt-BR"><head><meta charset="UTF-8">'
       . '<title>Erro na autorização</title></head><body style="font-family:sans-serif;padding:40px;">'
       . '<h2 style="color:#c0392b;">Erro ao obter token</h2>'
       . '<pre>' . htmlspecialchars($response ?: 'Sem resposta', ENT_QUOTES) . '</pre>'
       . '</body></html>';
}
