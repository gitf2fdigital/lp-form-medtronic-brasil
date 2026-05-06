<?php
/**
 * submit.php — Handler de submissão do formulário Guardian™
 *
 * Responsabilidades:
 *   1. Recebe e sanitiza os dados JSON do formulário
 *   2. Autentica com o RD Station via OAuth 2.0 (API v2)
 *   3. Registra a conversão no RD Station
 *   4. Envia e-mail de notificação para o atendimento
 *   5. Retorna JSON { success: true|false, message: "..." }
 *
 * IMPORTANTE: este arquivo deve ficar FORA do alcance público ou
 * protegido por .htaccess — nunca exponha client_secret no front-end.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

/* ──────────────────────────────────────────────────────────────
   HEADERS
────────────────────────────────────────────────────────────── */
header('Content-Type: application/json; charset=UTF-8');

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowed = ['https://medtronicdiabeteslatam.com', 'http://localhost'];
if (in_array($origin, $allowed, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
}
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método não permitido.']);
    exit;
}

/* ──────────────────────────────────────────────────────────────
   CONFIGURAÇÃO
   ⚠ Após configurar, regenere o client_secret no painel do RD Station.
────────────────────────────────────────────────────────────── */
$config = [
    // RD Station OAuth 2.0
    'rd_client_id'     => RD_CLIENT_ID,
    'rd_client_secret' => RD_CLIENT_SECRET,
    'rd_redirect_uri'  => RD_REDIRECT_URI,
    'rd_identifier'    => 'reposicao-sensor-guardian-br',

    // Arquivo onde os tokens OAuth são persistidos (fora do webroot se possível)
    'tokens_file'      => __DIR__ . '/rd_tokens.json',

    // E-mail de atendimento
    'email_to'         => 'atendimento.diabetes@medtronic.com',
    'email_from'       => 'noreply@medtronicdiabeteslatam.com',
    'email_from_name'  => 'Formulário Medtronic Guardian™',
];

/* ──────────────────────────────────────────────────────────────
   LÊ E VALIDA O PAYLOAD JSON
────────────────────────────────────────────────────────────── */
$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Payload inválido.']);
    exit;
}

function clean(mixed $v): string
{
    return htmlspecialchars(trim((string)($v ?? '')), ENT_QUOTES, 'UTF-8');
}

$required = ['nome_completo', 'email', 'cpf', 'telefone', 'endereco',
             'cidade', 'estado', 'cep', 'serie_bomba', 'serie_transmissor',
             'modelo_bomba', 'data_colocacao', 'horario_colocacao',
             'local_aplicacao', 'data_problema', 'horario_problema'];

foreach ($required as $field) {
    if (empty($data[$field])) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => "Campo obrigatório ausente: {$field}"]);
        exit;
    }
}

// Sanitiza todos os campos recebidos
$f = array_map('clean', $data);

// Labels legíveis para os valores dos radios
$labelSensor  = ($f['modelo_sensor'] === 'guardian3')
    ? 'Guardian™ 3 (MMT-7020C1)'
    : 'Guardian™ 4 (MMT-7040C8)';

$labelProblema = match ($f['tipo_problema']) {
    'sangue'     => 'Sangue no local de aplicação',
    'calibracao' => 'Calibração rejeitada',
    'trocar'     => 'Trocar sensor',
    'atualizacao'=> 'Atualização do sensor',
    default      => $f['tipo_problema'],
};

$labelBomba = ($f['modelo_bomba'] === 'minimed780g')
    ? 'MiniMed™ 780G'
    : 'MiniMed™ 640G';

/* ──────────────────────────────────────────────────────────────
   UTILITÁRIO: requisição cURL
────────────────────────────────────────────────────────────── */
function curlPost(string $url, array $body, array $headers = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($body),
        CURLOPT_HTTPHEADER     => array_merge(
            ['Content-Type: application/json', 'Accept: application/json'],
            $headers
        ),
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    curl_close($ch);

    return [
        'body'      => json_decode($response ?: '{}', true) ?? [],
        'http_code' => $httpCode,
        'error'     => $error,
    ];
}

/* ──────────────────────────────────────────────────────────────
   GERENCIAMENTO DE TOKENS RD STATION (OAuth 2.0)
────────────────────────────────────────────────────────────── */
function loadTokens(string $file): array
{
    if (!file_exists($file)) {
        return [];
    }
    $tokens = json_decode(file_get_contents($file), true);
    return is_array($tokens) ? $tokens : [];
}

function saveTokens(string $file, array $tokens): void
{
    file_put_contents($file, json_encode($tokens, JSON_PRETTY_PRINT), LOCK_EX);
    chmod($file, 0600);
}

function refreshAccessToken(array $config, string $refreshToken): array
{
    $result = curlPost('https://api.rd.services/auth/token', [
        'client_id'     => $config['rd_client_id'],
        'client_secret' => $config['rd_client_secret'],
        'refresh_token' => $refreshToken,
        'grant_type'    => 'refresh_token',
    ]);

    if ($result['http_code'] === 200 && !empty($result['body']['access_token'])) {
        return $result['body'];
    }

    return [];
}

function getValidAccessToken(array $config): string
{
    $tokens = loadTokens($config['tokens_file']);

    if (empty($tokens)) {
        // Tokens ainda não foram gerados — complete o fluxo OAuth via callback primeiro
        return '';
    }

    // Verifica se o access_token ainda é válido (com margem de 5 min)
    $expiresAt = (int)($tokens['expires_at'] ?? 0);
    if ($expiresAt > time() + 300) {
        return $tokens['access_token'];
    }

    // Renova com o refresh_token
    if (!empty($tokens['refresh_token'])) {
        $newTokens = refreshAccessToken($config, $tokens['refresh_token']);
        if (!empty($newTokens['access_token'])) {
            $newTokens['expires_at'] = time() + (int)($newTokens['expires_in'] ?? 86400);
            saveTokens($config['tokens_file'], $newTokens);
            return $newTokens['access_token'];
        }
    }

    return '';
}

/* ──────────────────────────────────────────────────────────────
   1. ENVIA CONVERSÃO PARA O RD STATION (API v2)
────────────────────────────────────────────────────────────── */
$rdSuccess = false;
$rdError   = '';

$accessToken = getValidAccessToken($config);

if ($accessToken) {
    $rdPayload = [
        'event_type'   => 'CONVERSION',
        'event_family' => 'CDP',
        'payload'      => [
            'conversion_identifier'  => $config['rd_identifier'],
            'email'                  => $f['email'],
            'name'                   => $f['nome_completo'],
            'mobile_phone'           => $f['telefone'],
            'cf_cpf'                 => $f['cpf'],
            'cf_modelo_sensor'       => $labelSensor,
            'cf_tipo_problema'       => $labelProblema,
            'cf_serie_bomba'         => $f['serie_bomba'],
            'cf_serie_transmissor'   => $f['serie_transmissor'],
            'cf_modelo_bomba'        => $labelBomba,
            'cf_endereco'            => $f['endereco'],
            'cf_cidade'              => $f['cidade'],
            'cf_estado'              => $f['estado'],
            'cf_cep'                 => $f['cep'],
            'cf_data_colocacao'      => $f['data_colocacao'],
            'cf_horario_colocacao'   => $f['horario_colocacao'],
            'cf_local_aplicacao'     => $f['local_aplicacao'],
            'cf_lote_sensor'         => $f['lote_sensor'] ?? '',
            'cf_data_problema'       => $f['data_problema'],
            'cf_horario_problema'    => $f['horario_problema'],
        ],
    ];

    $rdResult = curlPost(
        'https://api.rd.services/platform/events',
        $rdPayload,
        ['Authorization: Bearer ' . $accessToken]
    );

    if (in_array($rdResult['http_code'], [200, 201, 204], true)) {
        $rdSuccess = true;
    } else {
        $rdError = 'HTTP ' . $rdResult['http_code'] . ' — ' . ($rdResult['error'] ?: json_encode($rdResult['body']));
        error_log('[RD Station] Erro: ' . $rdError);
    }
} else {
    // Sem token disponível: tenta fallback pela API v1.3 (conversões públicas)
    // Descomente e preencha o token público se quiser usar esse fallback:
    // $rdError = 'Token OAuth não disponível. Complete o fluxo de autorização.';
    error_log('[RD Station] Access token indisponível — OAuth não autorizado ainda.');
}

/* ──────────────────────────────────────────────────────────────
   2. ENVIA E-MAIL DE ATENDIMENTO
────────────────────────────────────────────────────────────── */
$emailSuccess = false;

$subject = '=?UTF-8?B?' . base64_encode('Nova Solicitação de Reposição – Sensor Guardian™ | Medtronic') . '?=';

$lote = !empty($f['lote_sensor']) ? $f['lote_sensor'] : '—';

$body = "Nova solicitação de reposição de sensor Guardian™ recebida.\n\n";
$body .= str_repeat('─', 55) . "\n";
$body .= "DADOS DO USUÁRIO\n";
$body .= str_repeat('─', 55) . "\n";
$body .= "Nome completo:        {$f['nome_completo']}\n";
$body .= "E-mail:               {$f['email']}\n";
$body .= "CPF:                  {$f['cpf']}\n";
$body .= "Telefone:             {$f['telefone']}\n";
$body .= "Endereço:             {$f['endereco']}\n";
$body .= "Cidade/Estado:        {$f['cidade']} / {$f['estado']}\n";
$body .= "CEP:                  {$f['cep']}\n\n";
$body .= str_repeat('─', 55) . "\n";
$body .= "DISPOSITIVOS\n";
$body .= str_repeat('─', 55) . "\n";
$body .= "Modelo do sensor:     {$labelSensor}\n";
$body .= "Situação relatada:    {$labelProblema}\n";
$body .= "Modelo da bomba:      {$labelBomba}\n";
$body .= "Nº série da bomba:    {$f['serie_bomba']}\n";
$body .= "Nº série transmissor: {$f['serie_transmissor']}\n\n";
$body .= str_repeat('─', 55) . "\n";
$body .= "INFORMAÇÕES DO SENSOR\n";
$body .= str_repeat('─', 55) . "\n";
$body .= "Data de aplicação:    {$f['data_colocacao']} às {$f['horario_colocacao']}\n";
$body .= "Local de aplicação:   {$f['local_aplicacao']}\n";
$body .= "Lote do sensor:       {$lote}\n";
$body .= "Data do ocorrido:     {$f['data_problema']} às {$f['horario_problema']}\n\n";
$body .= str_repeat('─', 55) . "\n";
$body .= "RD Station:           " . ($rdSuccess ? 'Registrado ✓' : 'FALHOU — ' . $rdError) . "\n";
$body .= str_repeat('─', 55) . "\n";
$body .= "\nMensagem gerada automaticamente pelo formulário de reposição.\n";

$headers  = "From: {$config['email_from_name']} <{$config['email_from']}>\r\n";
$headers .= "Reply-To: {$f['email']}\r\n";
$headers .= "MIME-Version: 1.0\r\n";
$headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
$headers .= "Content-Transfer-Encoding: 8bit\r\n";
$headers .= "X-Mailer: Medtronic-FormHandler/1.0\r\n";

$emailSuccess = mail($config['email_to'], $subject, $body, $headers);

if (!$emailSuccess) {
    error_log('[Email] Falha ao enviar e-mail de atendimento.');
}

/* ──────────────────────────────────────────────────────────────
   RESPOSTA FINAL
────────────────────────────────────────────────────────────── */
// Considera sucesso se ao menos um dos canais registrou
if ($rdSuccess || $emailSuccess) {
    http_response_code(200);
    echo json_encode([
        'success'      => true,
        'rd_station'   => $rdSuccess,
        'email'        => $emailSuccess,
        'message'      => 'Solicitação recebida com sucesso.',
    ]);
} else {
    http_response_code(502);
    echo json_encode([
        'success'  => false,
        'message'  => 'Erro ao processar o envio. Tente novamente.',
    ]);
}
