<?php
if (!defined("WHMCS")) { die("This file cannot be accessed directly"); }

class NfseSigner
{
    private $certs;

    public function __construct($certs)
    {
        $this->certs = $certs;
    }

    public function sign($xmlDps, $refUri)
    {
        return $this->doSign($xmlDps, $refUri, 'infDPS');
    }

    public function signCancelamento($xmlCancel)
    {
        if (!preg_match('/Id="([^"]+)"/', $xmlCancel, $m)) {
            throw new Exception('Id nao encontrado no XML de cancelamento.');
        }
        // NFSe Nacional usa RSA-SHA256 em toda assinatura (DPS e eventos)
        // diferente da NF-e que usa SHA1 para eventos
        return $this->doSign($xmlCancel, '#' . $m[1], 'infEvento', 'sha256');
    }

    private function doSign($xmlString, $refUri, $signedElemName, $algo = 'sha256')
    {
        $ns = 'http://www.sped.fazenda.gov.br/nfse';

        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->preserveWhiteSpace = true;
        $doc->formatOutput       = false;

        libxml_use_internal_errors(true);
        if (!$doc->loadXML($xmlString)) {
            $errs = libxml_get_errors();
            $msgs = array();
            foreach ($errs as $e) {
                $msgs[] = trim($e->message);
            }
            libxml_clear_errors();
            throw new Exception('XML invalido: ' . implode('; ', $msgs));
        }
        libxml_clear_errors();

        $refId = ltrim($refUri, '#');
        $xpath = new DOMXPath($doc);
        $xpath->registerNamespace('n', $ns);

        $nodes = $xpath->query('//*[@Id="' . $refId . '"]');
        if ($nodes->length === 0) {
            $nodes = $doc->getElementsByTagName($signedElemName);
        }
        if ($nodes->length === 0) {
            throw new Exception('Elemento com Id="' . $refId . '" nao encontrado.');
        }
        $nodeToSign = $nodes->item(0);

        $c14nElem    = $nodeToSign->C14N(true, true);

        if ($algo === 'sha1') {
            $digestValue  = base64_encode(hash('sha1', $c14nElem, true));
            $algoC14N     = 'http://www.w3.org/2001/10/xml-exc-c14n#WithComments';
            $algoSig      = 'http://www.w3.org/2000/09/xmldsig#rsa-sha1';
            $algoEnv      = 'http://www.w3.org/2000/09/xmldsig#enveloped-signature';
            $algoDig      = 'http://www.w3.org/2000/09/xmldsig#sha1';
            $opensslAlgo  = OPENSSL_ALGO_SHA1;
        } else {
            $digestValue  = base64_encode(hash('sha256', $c14nElem, true));
            $algoC14N     = 'http://www.w3.org/2001/10/xml-exc-c14n#WithComments';
            $algoSig      = 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256';
            $algoEnv      = 'http://www.w3.org/2000/09/xmldsig#enveloped-signature';
            $algoDig      = 'http://www.w3.org/2001/04/xmlenc#sha256';
            $opensslAlgo  = OPENSSL_ALGO_SHA256;
        }

        $signedInfoXml = '<SignedInfo xmlns="http://www.w3.org/2000/09/xmldsig#">'
            . '<CanonicalizationMethod Algorithm="' . $algoC14N . '"/>'
            . '<SignatureMethod Algorithm="' . $algoSig . '"/>'
            . '<Reference URI="' . $refUri . '">'
            . '<Transforms>'
            . '<Transform Algorithm="' . $algoEnv . '"/>'
            . '<Transform Algorithm="' . $algoC14N . '"/>'
            . '</Transforms>'
            . '<DigestMethod Algorithm="' . $algoDig . '"/>'
            . '<DigestValue>' . $digestValue . '</DigestValue>'
            . '</Reference>'
            . '</SignedInfo>';

        $siDoc = new DOMDocument('1.0', 'UTF-8');
        $siDoc->loadXML($signedInfoXml);
        $c14nSI = $siDoc->C14N(true, true);

        $pkey = openssl_pkey_get_private($this->certs['pkey']);
        if (!$pkey) {
            throw new Exception('Chave privada invalida: ' . openssl_error_string());
        }
        $rawSig = '';
        if (!openssl_sign($c14nSI, $rawSig, $pkey, $opensslAlgo)) {
            throw new Exception('Falha ao assinar: ' . openssl_error_string());
        }
        $signatureValue = base64_encode($rawSig);

        $certBody = preg_replace('/-----[^-]+-----|[\r\n\s]/', '', $this->certs['cert']);

        $dsNs = 'http://www.w3.org/2000/09/xmldsig#';

        $sigNode  = $doc->createElementNS($dsNs, 'Signature');
        $siNode   = $doc->createElementNS($dsNs, 'SignedInfo');
        $c14nMeth = $doc->createElementNS($dsNs, 'CanonicalizationMethod');
        $c14nMeth->setAttribute('Algorithm', $algoC14N);
        $sigMeth  = $doc->createElementNS($dsNs, 'SignatureMethod');
        $sigMeth->setAttribute('Algorithm', $algoSig);
        $refNode  = $doc->createElementNS($dsNs, 'Reference');
        $refNode->setAttribute('URI', $refUri);
        $transfs  = $doc->createElementNS($dsNs, 'Transforms');
        $t1       = $doc->createElementNS($dsNs, 'Transform');
        $t1->setAttribute('Algorithm', $algoEnv);
        $t2       = $doc->createElementNS($dsNs, 'Transform');
        $t2->setAttribute('Algorithm', $algoC14N);
        $transfs->appendChild($t1);
        $transfs->appendChild($t2);
        $digMeth  = $doc->createElementNS($dsNs, 'DigestMethod');
        $digMeth->setAttribute('Algorithm', $algoDig);
        $digVal   = $doc->createElementNS($dsNs, 'DigestValue');
        $digVal->appendChild($doc->createTextNode($digestValue));
        $refNode->appendChild($transfs);
        $refNode->appendChild($digMeth);
        $refNode->appendChild($digVal);
        $siNode->appendChild($c14nMeth);
        $siNode->appendChild($sigMeth);
        $siNode->appendChild($refNode);
        $sigVal  = $doc->createElementNS($dsNs, 'SignatureValue');
        $sigVal->appendChild($doc->createTextNode($signatureValue));
        $keyInfo = $doc->createElementNS($dsNs, 'KeyInfo');
        $x509d   = $doc->createElementNS($dsNs, 'X509Data');
        $x509c   = $doc->createElementNS($dsNs, 'X509Certificate');
        $x509c->appendChild($doc->createTextNode($certBody));
        $x509d->appendChild($x509c);
        $keyInfo->appendChild($x509d);
        $sigNode->appendChild($siNode);
        $sigNode->appendChild($sigVal);
        $sigNode->appendChild($keyInfo);
        $doc->documentElement->appendChild($sigNode);

        $result = $doc->saveXML();
        $result = ltrim($result, "\xEF\xBB\xBF");

        $lf = "\n";
        $result = str_replace('</infDPS>'    . $lf . '<Signature',    '</infDPS><Signature',       $result);
        $result = str_replace('</Signature>' . $lf . '</DPS>',        '</Signature></DPS>',        $result);
        $result = str_replace('</infEvento>' . $lf . '<Signature',    '</infEvento><Signature',    $result);
        $result = str_replace('</Signature>' . $lf . '</evCancNFSe>', '</Signature></evCancNFSe>', $result);
        $result = rtrim($result);

        return $result;
    }
}
