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
    private $certDir;
    private $certFile;
    private $metaFile;

    public function __construct()
    {
        $this->certDir  = __DIR__ . '/../certs';
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
        if (empty($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) {
            return ['success' => false, 'message' => 'Erro no upload: ' . $this->uploadError($file['error'] ?? -1)];
        }

        // Valida extensaoo
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
            return ['success' => false, 'message' => 'Naoo foi poss??vel ler o certificado. Verifique a senha informada.'];
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
            return ['success' => false, 'message' => 'Erro ao salvar certificado no servidor. Verifique permissoeses da pasta.'];
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
        return file_exists($this->certFile) && file_exists($this->metaFile);
    }

    /**
     * Retorna metadados do certificado atual (sem a senha)
     */
    public function getMeta(): array
    {
        if (!file_exists($this->metaFile)) {
            return [];
        }
        $meta = json_decode(file_get_contents($this->metaFile), true) ?? [];
        unset($meta['password']); // nunca retorna a senha
        return $meta;
    }

    /**
     * Retorna o caminho do arquivo PFX
     */
    public function getCertPath(): string
    {
        return $this->certFile;
    }

    /**
     * Retorna a senha descriptografada
     */
    public function getPassword(): string
    {
        if (!file_exists($this->metaFile)) {
            throw new \Exception('Nenhum certificado carregado. Faca o upload em Addons -> NFS-e BH -> Certificado Digital.');
        }
        $meta = json_decode(file_get_contents($this->metaFile), true);
        return $this->decryptPassword($meta['password'] ?? '');
    }

    /**
     * L?? e retorna os dados do certificado (chave + cert) prontos para uso
     */
    public function read(): array
    {
        if (!$this->exists()) {
            throw new \Exception('Certificado digital naoo encontrado. Faca o upload primeiro.');
        }

        $pfxContent = file_get_contents($this->certFile);
        $password   = $this->getPassword();
        $certs      = [];

        if (!openssl_pkcs12_read($pfxContent, $certs, $password)) {
            throw new \Exception('Erro ao ler certificado. A senha pode estar incorreta ou o arquivo corrompido.');
        }

        // Verifica validade
        $info    = openssl_x509_parse($certs['cert']);
        $validTo = $info['validTo_time_t'] ?? 0;
        if ($validTo < time()) {
            throw new \Exception('Certificado digital VENCIDO em ' . date('d/m/Y H:i', $validTo) . '. Renove o certificado.');
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
    }

    private function encryptPassword(string $password): string
    {
        if (function_exists('encrypt')) {
            return 'whmcs:' . encrypt($password);
        }

        $key = hash('sha256', php_uname('n') . __DIR__, true);
        $iv  = random_bytes(16);
        $enc = openssl_encrypt($password, 'AES-256-CBC', $key, 0, $iv);
        return base64_encode($iv . '::' . $enc);
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
