<?php
if (!defined("WHMCS")) { die("This file cannot be accessed directly"); }

/**
 * NfseApiClient
 * Cliente REST para a API NFSe Nacional (SefinNacional)
 *
 * Endpoint emissao: POST /nfse
 * Autenticacao: mTLS com certificado A1 ICP-Brasil
 * Body: XML da DPS assinado, comprimido com gzip (binario puro)
 * Content-Type: application/octet-stream
 *
 * Ambientes:
 *   Producao Restrita: https://sefin.producaorestrita.nfse.gov.br/SefinNacional/
 *   Producao:          https://sefin.nfse.gov.br/SefinNacional/
 */
class NfseApiClient
{
    const URL_PRODUCAO          = 'https://sefin.nfse.gov.br/SefinNacional/';
    const URL_PRODUCAO_RESTRITA = 'https://sefin.producaorestrita.nfse.gov.br/SefinNacional/';

    private $baseUrl;
    private $certPath;
    private $certPassword;
    private $ambiente;

    public function __construct(
        $ambiente,
        $certPath,
        $certPassword
    ) {
        // Normaliza o valor do ambiente: WHMCS dropdown pode retornar "key=label"
        // Ex: "producao_restrita=Producao Restrita (Testes)" -> "producao_restrita"
        $ambienteNorm = (strpos($ambiente, '=') !== false)
            ? trim(explode('=', $ambiente)[0])
            : trim($ambiente);

        $this->ambiente      = $ambienteNorm;
        $this->certPath      = $certPath;
        $this->certPassword  = $certPassword;
        $this->baseUrl       = ($ambienteNorm === 'producao')
            ? self::URL_PRODUCAO
            : self::URL_PRODUCAO_RESTRITA;
    }

    /**
     * Emite NFS-e enviando a DPS assinada
     * POST /nfse
     * Body: XML gzipado como binario puro
     * Content-Type: application/octet-stream
     */
    public function emitir($xmlDpsAssinado, $cnpjPrestador)
    {
        // POST /nfse
        // Formato CONFIRMADO pelo forum ACBr e projetos open source funcionando:
        //   Content-Type: application/json
        //   Body: {"dpsXmlGZipB64": "H4sI..."}
        //
        // O campo se chama exatamente "dpsXmlGZipB64"
        // Referencia (exemplo real funcionando):
        //   projetoacbr.com.br: '{"dpsXmlGZipB64":"H4sIAAAAAAAAA71Wx..."}'
        //
        // ERRADO: json_encode($base64) => "H4sI..."  (string, nao objeto)
        // ERRADO: Content-Encoding: gzip com binario puro
        $endpoint = $this->baseUrl . 'nfse';
        $bodyGzip  = gzencode($xmlDpsAssinado, 9);
        $bodyB64   = base64_encode($bodyGzip);
        $bodyJson  = json_encode(array('dpsXmlGZipB64' => $bodyB64));

        return $this->post($endpoint, $bodyJson, 'application/json', false);
    }

    /**
     * Cancela NFS-e via evento de cancelamento
     * POST /nfse/{chaveAcesso}/eventos
     */
    public function cancelar($xmlCancelamento, $chaveAcesso)
    {
        // URL usa 50 digitos SEM prefixo "NFS"
        $chaveUrl = preg_match('/^NFS(.{50})$/i', $chaveAcesso, $mx) ? $mx[1] : $chaveAcesso;
        $chaveUrl = substr(trim($chaveUrl), 0, 50);
        $endpoint  = $this->baseUrl . 'nfse/' . urlencode($chaveUrl) . '/eventos';
        $bodyGzip  = gzencode($xmlCancelamento, 9);
        $bodyB64   = base64_encode($bodyGzip);

        // Formato confirmado: application/json com XML gzipado em base64
        // O servidor retorna 500 para notas ja canceladas (bug SEFIN) 
        // e 415 para qualquer outro content-type
        $bodyJson = json_encode(array('evCancNFSeXmlGZipB64' => $bodyB64));
        $result   = $this->post($endpoint, $bodyJson, 'application/json', false);

        if (!$result['success']) {
            $code = $result['http_code'] ?? '?';
            if ($code == 500) {
                $result['error'] = 'Erro no servidor SEFIN (HTTP 500). ' .
                    'Possivel causa: nota ja cancelada, ou bug conhecido do servidor de producao_restrita. ' .
                    'Verifique no Emissor Nacional se a nota ja consta como cancelada. ' .
                    'Detalhe: ' . $result['error'];
            }
        }

        return $result;
    }

    /**
     * Consulta NFS-e pela chave de acesso
     * GET /nfse/{chaveAcesso}
     */
    public function consultarPorChave($chaveAcesso)
    {
        $endpoint = $this->baseUrl . 'nfse/' . urlencode($chaveAcesso);
        $result   = $this->get($endpoint);

        // Post-processa: extrai o XML da NFSe da resposta
        if ($result['success'] && !empty($result['raw'])) {
            $body = $result['raw'];
            // Tenta descomprimir gzip
            if (strlen($body) > 2 && ord($body[0]) === 0x1f && ord($body[1]) === 0x8b) {
                $dec = @gzdecode($body);
                if ($dec !== false) {
                    $body = $dec;
                }
            }
            // Tenta JSON com nfseXmlGZipB64
            $json = json_decode($body, true);
            if (!empty($json['nfseXmlGZipB64'])) {
                $nfseGzip = base64_decode($json['nfseXmlGZipB64']);
                $nfseXml  = @gzdecode($nfseGzip);
                if ($nfseXml === false) { $nfseXml = $nfseGzip; }
                $result['nfse_xml'] = $nfseXml;
                if (preg_match('/<nNFSe[^>]*>(.*?)<\/nNFSe>/s', $nfseXml, $m)) {
                    $result['numero_nfse'] = trim($m[1]);
                }
                if (preg_match('/<infNFSe\s[^>]*Id="([^"]+)"/', $nfseXml, $m)) {
                    $result['chave_acesso'] = trim($m[1]);
                }
            }
            // Se body ja for XML valido (sem JSON wrapper), usa diretamente
            if (empty($result['nfse_xml']) && strpos($body, '<NFSe') !== false) {
                $result['nfse_xml'] = $body;
                if (preg_match('/<nNFSe[^>]*>(.*?)<\/nNFSe>/s', $body, $m)) {
                    $result['numero_nfse'] = trim($m[1]);
                }
                if (preg_match('/<infNFSe\s[^>]*Id="([^"]+)"/', $body, $m)) {
                    $result['chave_acesso'] = trim($m[1]);
                }
            }
        }
        return $result;
    }

    /**
     * Consulta NFS-e pelo ID do DPS (usado para recuperar nota apos E0014)
     * GET /nfse/dps/{idDps}
     */
    public function consultarPorIdDps($idDps)
    {
        $endpoint = $this->baseUrl . 'nfse/dps/' . urlencode($idDps);
        $result   = $this->get($endpoint);

        // Se a consulta retornar o XML da NFSe, extrai numero e chave
        if ($result['success'] && !empty($result['raw'])) {
            $body = $result['raw'];
            // Tenta descomprimir gzip
            if (strlen($body) > 2 && ord($body[0]) === 0x1f && ord($body[1]) === 0x8b) {
                $dec = @gzdecode($body);
                if ($dec !== false) {
                    $body = $dec;
                }
            }
            // Tenta JSON com nfseXmlGZipB64
            $json = json_decode($body, true);
            if (!empty($json['nfseXmlGZipB64'])) {
                $nfseGzip = base64_decode($json['nfseXmlGZipB64']);
                $nfseXml  = @gzdecode($nfseGzip);
                if ($nfseXml === false) { $nfseXml = $nfseGzip; }
                $result['nfse_xml'] = $nfseXml;
                if (preg_match('/<nNFSe[^>]*>(.*?)<\/nNFSe>/s', $nfseXml, $m)) {
                    $result['numero_nfse'] = trim($m[1]);
                }
                if (preg_match('/<infNFSe\s[^>]*Id="([^"]+)"/', $nfseXml, $m)) {
                    $result['chave_acesso'] = trim($m[1]);
                }
            }
        }
        return $result;
    }

    // --- Metodos internos ----------------------------------------------------

    private function post($endpoint, $body, $contentType, $isGzip = false)
    {
        return $this->callDirect('POST', $endpoint, $body, $contentType, $isGzip);
    }

    private function get($endpoint)
    {
        return $this->callDirect('GET', $endpoint, '', '');
    }

    // --- Chamada direta ------------------------------------------------------

    private function callDirect($method, $endpoint, $body, $contentType, $isGzip = false)
    {
        $headers = array(
            'Accept: application/xml, application/json, */*',
        );

        if (!empty($contentType)) {
            $headers[] = 'Content-Type: ' . $contentType;
        }

        if ($isGzip) {
            $headers[] = 'Content-Encoding: gzip';
        }

        if (!empty($body)) {
            $headers[] = 'Content-Length: ' . strlen($body);
        }

        $opts = array(
            CURLOPT_URL             => $endpoint,
            CURLOPT_CUSTOMREQUEST   => $method,
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_TIMEOUT         => 60,
            CURLOPT_CONNECTTIMEOUT  => 15,
            CURLOPT_HTTPHEADER      => $headers,
            CURLOPT_FOLLOWLOCATION  => true,
            CURLOPT_SSLVERSION      => CURL_SSLVERSION_TLSv1_2,
            CURLOPT_SSLCERT         => $this->certPath,
            CURLOPT_SSLCERTPASSWD   => $this->certPassword,
            CURLOPT_SSLCERTTYPE     => 'P12',
        );

        if (!empty($body)) {
            $opts[CURLOPT_POSTFIELDS] = $body;
        }

        $icpBundle = __DIR__ . '/../certs/icp_brasil.pem';
        // O icp_brasil.pem contem CAs ICP-Brasil para verificacao do CERTIFICADO DO CLIENTE.
        // O SERVIDOR (sefin.nfse.gov.br) usa certificado Sectigo, que esta no bundle do sistema.
        // Portanto: verificar servidor com CA do sistema (default curl), NAO com icp_brasil.pem.
        // CURLOPT_SSLCERT ja cuida do certificado do cliente (mTLS).
        $opts[CURLOPT_SSL_VERIFYPEER] = true;
        $opts[CURLOPT_SSL_VERIFYHOST] = 2;
        // Nao definir CURLOPT_CAINFO = usar bundle CA do sistema (inclui Sectigo)

        $ch = curl_init();
        curl_setopt_array($ch, $opts);
        $response  = curl_exec($ch);
        $httpCode  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            return array('success' => false, 'error' => 'Erro de conexao: ' . $curlError, 'raw' => '');
        }

        return $this->parseResponse((string)$response, $httpCode);
    }

    // --- Parser da resposta --------------------------------------------------

    private function parseResponse($response, $httpCode)
    {
        $result = array(
            'success'      => ($httpCode >= 200 && $httpCode < 300),
            'http_code'    => $httpCode,
            'raw'          => $response,
            'error'        => '',
            'numero_nfse'  => null,
            'chave_acesso' => null,
        );

        if (empty($response)) {
            if (!$result['success']) {
                $result['error'] = 'Resposta vazia (HTTP ' . $httpCode . ')';
            }
            return $result;
        }

        // Tenta descomprimir se gzip
        $body = $response;
        if (strlen($body) > 2 && ord($body[0]) === 0x1f && ord($body[1]) === 0x8b) {
            $dec = @gzdecode($body);
            if ($dec !== false) {
                $body = $dec;
            }
        }

        // Tenta parsear como JSON
        $json = json_decode($body, true);
        if ($json !== null) {
            // Erros da API NFSe Nacional
            if (!empty($json['erros'])) {
                $msgs = array();
                foreach ($json['erros'] as $e) {
                    $cod  = $e['Codigo']    ?? $e['codigo']    ?? '?';
                    $desc = $e['Descricao'] ?? $e['descricao'] ?? '?';
                    $msgs[] = $cod . ': ' . $desc;
                }
                $result['error']   = implode(' | ', $msgs);
                $result['success'] = false;
                return $result;
            }

            // Sucesso HTTP 201: resposta contem nfseXmlGZipB64
            // {"nfseXmlGZipB64":"H4sI...","chaveAcesso":"...","..."}
            if (!empty($json['nfseXmlGZipB64'])) {
                // Descomprime o XML da NFSe retornada
                $nfseGzip = base64_decode($json['nfseXmlGZipB64']);
                $nfseXml  = @gzdecode($nfseGzip);
                if ($nfseXml === false) {
                    $nfseXml = $nfseGzip; // fallback: nao era gzip
                }

                $result['success']  = true;
                $result['nfse_xml'] = $nfseXml;

                // Extrai campos da NFSe
                if (preg_match('/<nNFSe[^>]*>(.*?)<\/nNFSe>/s', $nfseXml, $m)) {
                    $result['numero_nfse'] = trim($m[1]);
                }
                // nDFSe = numero do protocolo de autorizacao (usado no cancelamento como nProt)
                if (preg_match('/<nDFSe[^>]*>(.*?)<\/nDFSe>/s', $nfseXml, $m)) {
                    $result['n_dfse'] = trim($m[1]);
                }
                // Chave de acesso = atributo Id do infNFSe (ex: Id="NFS31062...")
                if (preg_match('/<infNFSe\s[^>]*Id="([^"]+)"/', $nfseXml, $m)) {
                    $result['chave_acesso'] = trim($m[1]);
                }
                // chaveAcesso pode vir direto no JSON da resposta
                if (!empty($json['chaveAcesso'])) {
                    $result['chave_acesso'] = $json['chaveAcesso'];
                }
                return $result;
            }

            // Outros JSON de sucesso (ex: consulta)
            if ($result['success']) {
                return $result;
            }
        }

        // Tenta parsear como XML (resposta legada)
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body);
        libxml_clear_errors();

        if ($xml === false) {
            if (!$result['success']) {
                // Inclui os primeiros 500 chars do body para diagnostico
                $preview = mb_substr($body, 0, 500);
                $result['error'] = 'Resposta nao reconhecida (HTTP ' . $httpCode . '): ' . $preview;
            }
            return $result;
        }

        $xmlStr = $body;

        // cStat 100 = autorizada
        if (preg_match('/<cStat[^>]*>(\d+)</', $xmlStr, $m)) {
            if ($m[1] !== '100') {
                $result['success'] = false;
                if (preg_match('/<xMotivo[^>]*>(.*?)<\/xMotivo>/s', $xmlStr, $mx)) {
                    $result['error'] = 'cStat ' . $m[1] . ': ' . trim($mx[1]);
                }
            }
        }

        if (preg_match('/<nNFSe[^>]*>(.*?)<\/nNFSe>/s', $xmlStr, $m)) {
            $result['numero_nfse'] = trim($m[1]);
            $result['success']     = true;
        }

        if (preg_match('/<chNFSe[^>]*>(.*?)<\/chNFSe>/s', $xmlStr, $m)) {
            $result['chave_acesso'] = trim($m[1]);
        }

        if ($httpCode >= 400 && empty($result['error'])) {
            if (preg_match('/<Mensagem[^>]*>(.*?)<\/Mensagem>/s', $xmlStr, $m)) {
                $result['error']   = trim($m[1]);
                $result['success'] = false;
            }
        }

        return $result;
    }
}
