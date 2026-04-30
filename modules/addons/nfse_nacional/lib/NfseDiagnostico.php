<?php
if (!defined("WHMCS")) { die("This file cannot be accessed directly"); }

/**
 * NfseDiagnostico - Verifica conectividade com a API NFSe Nacional
 */
class NfseDiagnostico
{
    public function executar(): array
    {
        $r = [];

        $r['servidor'] = [
            'PHP'            => PHP_VERSION,
            'cURL'           => curl_version()['version'] ?? 'N/A',
            'SSL/TLS'        => curl_version()['ssl_version'] ?? 'N/A',
            'OpenSSL'        => OPENSSL_VERSION_TEXT,
            'IP do servidor' => @gethostbyname(@gethostname()) ?: 'N/A',
            'OS'             => php_uname('s') . ' ' . php_uname('r'),
        ];

        $r['dns_google']      = $this->dns('google.com');
        $r['dns_producao']    = $this->dns('sefin.nfse.gov.br');
        $r['dns_homologacao'] = $this->dns('sefin.producaorestrita.nfse.gov.br');

        foreach ([
            'dns_producao'    => 'sefin.nfse.gov.br',
            'dns_homologacao' => 'sefin.producaorestrita.nfse.gov.br',
        ] as $dk => $host) {
            $key = str_replace('dns_', 'tcp_', $dk);
            $r[$key] = $r[$dk]['ok']
                ? $this->tcp($host, 443)
                : ['ok' => false, 'msg' => 'DNS falhou - teste TCP ignorado'];
        }

        $r['https_producao']    = $this->https('https://sefin.nfse.gov.br/SefinNacional/', false);
        $r['https_homologacao'] = $this->https('https://sefin.producaorestrita.nfse.gov.br/SefinNacional/', false);

        $r['ssl_producao']    = $this->https('https://sefin.nfse.gov.br/SefinNacional/', true);
        $r['ssl_homologacao'] = $this->https('https://sefin.producaorestrita.nfse.gov.br/SefinNacional/', true);

        return $r;
    }

    private function dns(string $host): array
    {
        $ip = @gethostbyname($host);
        $ok = ($ip !== $host && filter_var($ip, FILTER_VALIDATE_IP));
        return [
            'ok'   => $ok,
            'host' => $host,
            'ip'   => $ok ? $ip : null,
            'msg'  => $ok ? "Resolvido -> $ip" : "Falha: host nao resolvido",
        ];
    }

    private function tcp(string $host, int $porta): array
    {
        $errno = 0; $errstr = '';
        $conn  = @fsockopen("ssl://$host", $porta, $errno, $errstr, 6);
        if ($conn) { fclose($conn); return ['ok' => true, 'msg' => "Porta $porta/ssl acessivel"]; }
        $conn = @fsockopen($host, $porta, $errno, $errstr, 6);
        if ($conn) { fclose($conn); return ['ok' => true, 'msg' => "Porta $porta acessivel"]; }
        return ['ok' => false, 'msg' => "Porta $porta inacessivel: $errstr ($errno)"];
    }

    private function https(string $url, bool $verifySSL): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_SSL_VERIFYPEER => $verifySSL,
            CURLOPT_SSL_VERIFYHOST => $verifySSL ? 2 : 0,
            CURLOPT_NOBODY         => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSLVERSION     => CURL_SSLVERSION_TLSv1_2,
        ]);
        curl_exec($ch);
        $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err   = curl_error($ch);
        $errno = curl_errno($ch);
        curl_close($ch);

        if (!$code && $errno) {
            $ch2 = curl_init($url);
            curl_setopt_array($ch2, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_CONNECTTIMEOUT => 6,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_NOBODY         => true,
            ]);
            curl_exec($ch2);
            $code2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
            $err2  = curl_error($ch2);
            curl_close($ch2);

            if ($code2 > 0) {
                return [
                    'ok'        => false,
                    'http_code' => $code2,
                    'errno'     => $errno,
                    'msg'       => "Sem TLS forcado: HTTP $code2 | Com TLS 1.2: Falha (errno $errno): $err",
                    'ssl_error' => true,
                    'tls_issue' => true,
                ];
            }
        }

        $ok = ($code > 0);
        return [
            'ok'        => $ok,
            'http_code' => $code,
            'errno'     => $errno,
            'msg'       => $ok ? "HTTP $code (TLS 1.2)" : "Falha (errno $errno): $err",
            'ssl_error' => (!$ok && $verifySSL && in_array($errno, [35, 51, 56, 60, 77, 83])),
            'tls_issue' => (!$ok && in_array($errno, [35, 56])),
        ];
    }
}
