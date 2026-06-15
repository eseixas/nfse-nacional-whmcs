<?php
if (!defined("WHMCS")) { die("This file cannot be accessed directly"); }

/**
 * CertManager
 * Gerencia o upload, armazenamento seguro e leitura do certificado A1 (.pfx/.p12)
 * O certificado e armazenado dentro do addon, fora do webroot de arquivos estaticos,
 * com .htaccess bloqueando acesso direto.
 */
class CertManager
{
    private const EXPIRING_DAYS = 30;

    private $certDir;
    private $legacyCertDir;
    private $certFile;
    private $metaFile;

    public function __construct(array $config = array())
    {
        $this->legacyCertDir = __DIR__ . '/../certs';
        $this->certDir  = $this->resolveCertDir($config);
        $this->certFile = $this->certDir . '/cert.pfx';
        $this->metaFile = $this->certDir . '/cert_meta.json';
    }

    /**
     * Processa o upload do certificado enviado via formulario
     *
     * @param array  $file     $_FILES['cert_file']
     * @param string $password Senha do certificado
     * @return array ['success' => bool, 'message' => string]
     */
    public function upload(array $file, string $password): array
    {
        if (!function_exists('encrypt')) {
            return [
                'success' => false,
                'message' => 'Criptografia do WHMCS indisponivel. Carregue o addon dentro do WHMCS antes de gravar um novo certificado.',
            ];
        }

        if (empty($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) {
            return ['success' => false, 'message' => 'Erro no upload: ' . $this->uploadError($file['error'] ?? -1)];
        }

        // Valida extensão
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['pfx', 'p12'])) {
            return ['success' => false, 'message' => 'Arquivo invalido. Use .pfx ou .p12'];
        }

        // Valida tamanho (max 500KB)
        if ($file['size'] > 512000) {
            return ['success' => false, 'message' => 'Certificado muito grande. Maximo 500KB.'];
        }

        $content = file_get_contents($file['tmp_name']);

        // Testa leitura do certificado com a senha
        $certs = [];
        if (!openssl_pkcs12_read($content, $certs, $password)) {
            return ['success' => false, 'message' => 'Não foi possível ler o certificado. Verifique a senha informada.'];
        }

        // Valida validade do certificado
        $certInfo = openssl_x509_parse($certs['cert']);
        $validTo  = $certInfo['validTo_time_t'] ?? 0;

        if ($validTo < time()) {
            return ['success' => false, 'message' => 'Certificado VENCIDO em ' . date('d/m/Y', $validTo) . '. Renove antes de continuar.'];
        }

        // Garante que o diretorio existe e esta protegido
        if (!is_dir($this->certDir)) {
            mkdir($this->certDir, 0700, true);
        }
        file_put_contents($this->certDir . '/.htaccess', "Require all denied\nDeny from all\n");
        file_put_contents($this->certDir . '/index.php', '<?php // silence');

        // Salva o arquivo
        if (file_put_contents($this->certFile, $content) === false) {
            return ['success' => false, 'message' => 'Erro ao salvar certificado no servidor. Verifique permissões da pasta.'];
        }
        chmod($this->certFile, 0600);

        // Salva a senha criptografada + metadados
        $meta = [
            'filename'   => $file['name'],
            'uploaded_at'=> date('Y-m-d H:i:s'),
            'valid_to'   => date('Y-m-d', $validTo),
            'subject'    => $certInfo['subject']['CN'] ?? '',
            'password'   => $this->encryptPassword($password),
            'password_encryption' => function_exists('encrypt') ? 'whmcs' : 'legacy',
        ];
        file_put_contents($this->metaFile, json_encode($meta, JSON_PRETTY_PRINT));
        chmod($this->metaFile, 0600);

        $diasRestantes = ceil(($validTo - time()) / 86400);

        return [
            'success' => true,
            'message' => 'Certificado enviado com sucesso! Valido ate ' . date('d/m/Y', $validTo) . ' (' . $diasRestantes . ' dias).',
            'meta'    => $meta,
        ];
    }

    /**
     * Verifica se existe um certificado carregado
     */
    public function exists(): bool
    {
        return $this->activePaths() !== null;
    }

    /**
     * Certificado configurado, legivel e dentro da validade operacional.
     */
    public function isReady(): bool
    {
        $status = $this->getStatus();
        return in_array($status['state'], ['valid', 'expiring'], true);
    }

    /**
     * Status operacional do certificado lendo o PFX real (nao apenas o JSON em cache).
     */
    public function getStatus(): array
    {
        $paths = $this->activePaths();
        $storedMeta = [];
        $encryptedPassword = '';
        if ($paths !== null) {
            $storedMeta = json_decode((string)file_get_contents($paths['meta']), true) ?? [];
            $encryptedPassword = (string)($storedMeta['password'] ?? '');
            unset($storedMeta['password']);
        }

        $status = [
            'configured'     => $paths !== null,
            'readable'       => false,
            'state'          => 'missing',
            'valid_from'     => null,
            'valid_to'       => null,
            'days_remaining' => null,
            'subject'        => $storedMeta['subject'] ?? '',
            'filename'       => $storedMeta['filename'] ?? '',
            'uploaded_at'    => $storedMeta['uploaded_at'] ?? '',
            'storage'        => ($paths && $paths['dir'] === $this->legacyCertDir) ? 'legacy' : 'protected',
            'error'          => null,
        ];

        if ($paths === null) {
            $status['error'] = 'Certificado digital nao configurado.';
            return $status;
        }

        $pfxContent = file_get_contents($paths['cert']);
        if ($pfxContent === false || $pfxContent === '') {
            $status['state'] = 'unreadable';
            $status['error'] = 'Nao foi possivel ler o arquivo do certificado.';
            return $status;
        }

        $password = $this->decryptPassword($encryptedPassword);
        $certs = [];
        if (!openssl_pkcs12_read($pfxContent, $certs, $password)) {
            $status['state'] = 'unreadable';
            $status['error'] = 'Nao foi possivel abrir o certificado. Verifique a senha ou envie o arquivo novamente.';
            return $status;
        }

        $info = openssl_x509_parse($certs['cert']);
        $validFrom = (int)($info['validFrom_time_t'] ?? 0);
        $validTo   = (int)($info['validTo_time_t'] ?? 0);
        $now       = time();

        $status['readable'] = true;
        $status['valid_from'] = $validFrom ? date('Y-m-d H:i:s', $validFrom) : null;
        $status['valid_to'] = $validTo ? date('Y-m-d', $validTo) : null;
        $status['subject'] = $info['subject']['CN'] ?? ($storedMeta['subject'] ?? '');

        if ($validFrom > $now) {
            $status['state'] = 'not_yet_valid';
            $status['error'] = 'Certificado ainda nao entrou em vigor em ' . date('d/m/Y H:i', $validFrom) . '.';
            return $status;
        }

        if ($validTo <= $now) {
            $status['state'] = 'expired';
            $status['days_remaining'] = (int)ceil(($validTo - $now) / 86400);
            $status['error'] = 'Certificado digital vencido em ' . date('d/m/Y', $validTo) . '. Renove o certificado.';
            return $status;
        }

        $daysRemaining = (int)ceil(($validTo - $now) / 86400);
        $status['days_remaining'] = $daysRemaining;
        $status['state'] = ($daysRemaining <= self::EXPIRING_DAYS) ? 'expiring' : 'valid';
        return $status;
    }

    /**
     * Retorna metadados do certificado atual (sem a senha)
     */
    public function getMeta(): array
    {
        $status = $this->getStatus();
        if (!$status['configured']) {
            return [];
        }

        $meta = [
            'filename'       => $status['filename'],
            'uploaded_at'    => $status['uploaded_at'],
            'valid_to'       => $status['valid_to'],
            'valid_from'     => $status['valid_from'],
            'subject'        => $status['subject'],
            'storage'        => $status['storage'],
            'state'          => $status['state'],
            'days_remaining' => $status['days_remaining'],
            'readable'       => $status['readable'],
            'error'          => $status['error'],
        ];

        return $meta;
    }

    /**
     * Retorna o caminho do arquivo PFX
     */
    public function getCertPath(): string
    {
        $paths = $this->activePaths();
        return $paths['cert'] ?? $this->certFile;
    }

    /**
     * Retorna a senha descriptografada
     */
    public function getPassword(): string
    {
        $paths = $this->activePaths();
        if ($paths === null) {
            throw new \Exception('Nenhum certificado carregado. Faca o upload em Addons -> NFSE Nacional -> Certificado Digital.');
        }
        $meta = json_decode(file_get_contents($paths['meta']), true);
        return $this->decryptPassword($meta['password'] ?? '');
    }

    /**
     * L?? e retorna os dados do certificado (chave + cert) prontos para uso
     */
    public function read(): array
    {
        $status = $this->getStatus();
        if ($status['state'] === 'missing') {
            throw new \Exception('Certificado digital não encontrado. Faça o upload primeiro.');
        }
        if (!$status['readable']) {
            throw new \Exception($status['error'] ?: 'Erro ao ler certificado. A senha pode estar incorreta ou o arquivo corrompido.');
        }
        if (!$this->isReady()) {
            throw new \Exception($status['error'] ?: 'Certificado digital indisponivel para emissao.');
        }

        $pfxContent = file_get_contents($this->getCertPath());
        $password   = $this->getPassword();
        $certs      = [];

        if (!openssl_pkcs12_read($pfxContent, $certs, $password)) {
            throw new \Exception('Erro ao ler certificado. A senha pode estar incorreta ou o arquivo corrompido.');
        }

        return $certs;
    }

    /**
     * Remove o certificado armazenado
     */
    public function delete(): void
    {
        if (file_exists($this->certFile)) unlink($this->certFile);
        if (file_exists($this->metaFile)) unlink($this->metaFile);
        $legacyCert = $this->legacyCertDir . '/cert.pfx';
        $legacyMeta = $this->legacyCertDir . '/cert_meta.json';
        if ($legacyCert !== $this->certFile && file_exists($legacyCert)) unlink($legacyCert);
        if ($legacyMeta !== $this->metaFile && file_exists($legacyMeta)) unlink($legacyMeta);
    }

    private function encryptPassword(string $password): string
    {
        return 'whmcs:' . encrypt($password);
    }

    private function decryptPassword(string $encrypted): string
    {
        if (strpos($encrypted, 'whmcs:') === 0) {
            $payload = substr($encrypted, 6);
            return function_exists('decrypt') ? (decrypt($payload) ?: '') : '';
        }

        if (function_exists('decrypt')) {
            $plain = decrypt($encrypted);
            if ($plain !== false && $plain !== '') {
                return $plain;
            }
        }

        $key   = hash('sha256', php_uname('n') . __DIR__, true);
        $parts = explode('::', base64_decode($encrypted), 2);
        if (count($parts) !== 2) return '';
        return openssl_decrypt($parts[1], 'AES-256-CBC', $key, 0, $parts[0]) ?: '';
    }

    private function resolveCertDir(array $config): string
    {
        $base = trim((string)($config['storage_path'] ?? ''));
        if ($base === '') {
            return $this->legacyCertDir;
        }

        if (defined('ROOTDIR')) {
            $base = str_replace(['{ROOTDIR}', '%ROOTDIR%'], ROOTDIR, $base);
        }
        $base = rtrim($base, "/\\");
        if ($base === '') {
            return $this->legacyCertDir;
        }

        return $base . DIRECTORY_SEPARATOR . 'certs';
    }

    private function activePaths(): ?array
    {
        $primary = [
            'dir'  => $this->certDir,
            'cert' => $this->certFile,
            'meta' => $this->metaFile,
        ];
        if (file_exists($primary['cert']) && file_exists($primary['meta'])) {
            return $primary;
        }

        $legacy = [
            'dir'  => $this->legacyCertDir,
            'cert' => $this->legacyCertDir . '/cert.pfx',
            'meta' => $this->legacyCertDir . '/cert_meta.json',
        ];
        if (file_exists($legacy['cert']) && file_exists($legacy['meta'])) {
            return $legacy;
        }

        return null;
    }

    private function uploadError(int $code): string
    {
        switch ($code) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:  return 'Arquivo muito grande';
            case UPLOAD_ERR_PARTIAL:    return 'Upload incompleto';
            case UPLOAD_ERR_NO_FILE:    return 'Nenhum arquivo enviado';
            case UPLOAD_ERR_NO_TMP_DIR: return 'Diretorio temporario ausente';
            case UPLOAD_ERR_CANT_WRITE: return 'Sem permissao de escrita';
            default:                    return 'Erro desconhecido (codigo ' . $code . ')';
        }
    }
}
