<?php

// Included by tests/run.php; exercises the real controller using isolated mocks.
require_once __DIR__ . '/security-capsule.php';
require_once dirname(__DIR__) . '/modules/addons/nfse_nacional/lib/NfseController.php';

class SecurityCertManager extends CertManager
{
    public int $uploads = 0;
    public string $message = '';
    public function __construct() {}
    public function getStatus(): array { return ['configured' => false]; }
    public function getMeta(): array { return []; }
    public function isReady(): bool { return false; }
    public function exists(): bool { return false; }
    public function upload(array $file, string $password): array
    {
        ++$this->uploads;
        return ['success' => false, 'message' => $this->message];
    }
}

class SecurityNfseService extends NfseService
{
    public int $emissions = 0;
    public function __construct() {}
    public function log($tipo, $acao, $msg, $dados = null, $invoiceId = null) {}
    public function emitirParaFatura($invoiceId, array $options = []): array
    {
        ++$this->emissions;
        return ['success' => false, 'message' => 'MOCK'];
    }
}

function captureSecurity(callable $render): string
{
    ob_start();
    try { $render(); return ob_get_contents(); }
    finally { ob_end_clean(); }
}

$reflection = new ReflectionClass(NfseController::class);
$controller = $reflection->newInstanceWithoutConstructor();
$cert = new SecurityCertManager();
$service = new SecurityNfseService();
foreach (['vars' => [], 'config' => [], 'modulelink' => 'addonmodules.php?module=nfse_nacional',
    'certMgr' => $cert, 'service' => $service] as $name => $value) {
    $reflection->getProperty($name)->setValue($controller, $value);
}
$flash = $reflection->getMethod('flash');
$hostile = '<img src=x onerror="alert(1)"> \' & " <script>alert(2)</script>';
$escaped = htmlspecialchars($hostile, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

foreach ([false, true] as $back) {
    $html = captureSecurity(fn() => $flash->invoke($controller, $hostile, 'danger', $back));
    check(str_contains($html, $escaped), 'flash escapa tags, aspas e ampersand com link=' . (int)$back);
    check(!str_contains($html, '<img') && !str_contains($html, '<script'), 'flash nao permite markup da mensagem');
    check(str_contains($html, '<button type="button" class="close"'), 'botao legitimo de fechar preservado');
    check(str_contains($html, '<a href="javascript:history.back()">Volte</a>') === $back, 'link legitimo separado da mensagem');
}
$invalidUtf8 = "falha \xC3\x28 <script>";
$html = captureSecurity(fn() => $flash->invoke($controller, $invalidUtf8, 'danger'));
check(str_contains($html, "falha \xEF\xBF\xBD(") && str_contains($html, '&lt;script&gt;'), 'UTF-8 invalido usa substituicao sem apagar mensagem');
$html = captureSecurity(fn() => $flash->invoke($controller, 'x', 'danger" onclick="alert(1)'));
check(!str_contains($html, 'onclick') && str_contains($html, 'alert-info'), 'tipo de alerta limitado a classes conhecidas');

$_SERVER['REQUEST_METHOD'] = 'POST';
$_GET = [];
$_SESSION['nfse_nacional_csrf'] = 'valid-token';
$_POST = ['salvar_cliente' => 1, 'client_id' => 1, 'emissao_modo' => 'default', 'nfse_csrf_token' => 'valid-token'];
\WHMCS\Database\Capsule::$deleteError = $hostile;
$html = captureSecurity(fn() => $controller->clientes());
check(str_contains($html, $escaped) && !str_contains($html, '<img'), 'clientes escapa excecao ampla do banco');
\WHMCS\Database\Capsule::$deleteError = null;
$_POST = ['salvar_cliente' => 1, 'client_id' => 1, 'nfse_csrf_token' => 'wrong'];
$writes = \WHMCS\Database\Capsule::$writes;
$html = captureSecurity(fn() => $controller->clientes());
check(str_contains($html, 'Sessao expirada ou token invalido.') && str_contains($html, '>Volte</a>'), 'clientes preserva link apenas em erro CSRF tipado');
check(\WHMCS\Database\Capsule::$writes === $writes, 'clientes CSRF rejeitado nao grava no banco simulado');

foreach ([null, 'wrong', []] as $token) {
    $_SESSION['nfse_nacional_csrf'] = 'expected';
    $_POST = ['upload_cert' => 1, 'nfse_csrf_token' => $token];
    $html = captureSecurity(fn() => $controller->uploadCert());
    check(str_contains($html, 'Sessao expirada ou token invalido.'), 'upload exibe falha CSRF para ' . get_debug_type($token));
    check(str_contains($html, '<a href="javascript:history.back()">Volte</a>'), 'upload preserva link Volte');
    check($cert->uploads === 0, 'CSRF rejeitado nao chama upload');
}
$_SESSION['nfse_nacional_csrf'] = 'valid-token';
$_POST = ['upload_cert' => 1, 'nfse_csrf_token' => 'valid-token'];
$cert->message = $invalidUtf8 . $hostile;
$html = captureSecurity(fn() => $controller->uploadCert());
check($cert->uploads === 1 && str_contains($html, $escaped) && !str_contains($html, '<img'), 'upload com CSRF valido escapa mensagem do certificado');
check(str_contains($html, "\xEF\xBF\xBD"), 'upload preserva texto com UTF-8 invalido');

$_POST = ['exportar' => 1, 'nfse_csrf_token' => 'wrong'];
$html = captureSecurity(fn() => $controller->exportar());
check(str_contains($html, 'Sessao expirada ou token invalido.') && str_contains($html, '>Volte</a>'), 'exportar rejeita CSRF e preserva link');
$_POST = [];
$html = captureSecurity(fn() => $controller->exportar('Nenhum arquivo foi gerado. Falhas: ' . $hostile, 'warning'));
check(str_contains($html, $escaped) && !str_contains($html, '&amp;lt;img'), 'falhas de exportacao escapadas uma vez');
$_SESSION['nfse_nacional_csrf'] = 'valid-token';
$_POST = ['exportar' => 1, 'nfse_csrf_token' => 'valid-token', 'data_inicio' => 'invalid'];
$html = captureSecurity(fn() => $controller->exportar());
check(str_contains($html, 'Datas invalidas.'), 'exportar mantem validacao de datas');

// Execute the actual ZIP failure aggregation. No certificate exists in this
// unique storage location; an empty verification code also prevents API lookup.
if (class_exists(ZipArchive::class)) {
    $reflection->getProperty('config')->setValue($controller, ['storage_path' => sys_get_temp_dir() . '/nfse-security-' . bin2hex(random_bytes(8))]);
    \WHMCS\Database\Capsule::$exportRows = [(object) [
        'invoice_id' => $invalidUtf8 . $hostile, 'numero_nfse' => '1', 'emitida_em' => '2026-09-01',
        'xml_enviado' => '<DPS/>', 'xml_retorno' => '', 'codigo_verificacao' => '',
        'n_dps' => '1', 'valor' => 100, 'status' => 'emitida',
    ]];
    $_SESSION['nfse_nacional_csrf'] = 'valid-token';
    $_POST = ['exportar' => 1, 'nfse_csrf_token' => 'valid-token', 'formato' => 'pdf',
        'data_inicio' => '2026-09-01', 'data_fim' => '2026-09-30'];
    $html = captureSecurity(fn() => $controller->exportar());
    check(str_contains($html, 'Nenhum arquivo foi gerado') && str_contains($html, $escaped), 'fluxo ZIP real agrega e escapa falhas PDF');
    check(!str_contains($html, '&amp;lt;img') && str_contains($html, "\xEF\xBF\xBD"), 'falhas ZIP sem escape duplo e com substituicao UTF-8');
    \WHMCS\Database\Capsule::$exportRows = [];
} else {
    echo "SKIP regressao de agregacao ZIP: ext-zip ausente\n";
}

$_POST = ['invoice_id' => 1, 'nfse_csrf_token' => 'wrong'];
$html = captureSecurity(fn() => $controller->emitir());
check(str_contains($html, 'Sessao expirada ou token invalido.') && str_contains($html, '>Volte</a>'), 'emitir rejeita CSRF e preserva markup estatico');
check($service->emissions === 0, 'nenhuma emissao/API simulada em rejeicoes CSRF');
