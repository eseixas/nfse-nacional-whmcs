# Changelog

## [1.1] — 2026-03-30

### Novidades

- Campo **Série da NFS-e** adicionado nas configurações do addon (antes era fixo em 2)
- XML agora é extraído diretamente do **Emissor Nacional** (API SefinNacional) em visualização, download individual e exportação em lote
- Widget de emissão na fatura com posicionamento mais robusto (compatível com WHMCS 7/8/9)

### Correções

- Removido arquivo de índice CSV (`_indice.csv`) da exportação ZIP — agora contém apenas os XMLs
- Campo **NFS-e No** agora exibe o valor do **nDPS** (número da DPS) em vez do numero_nfse
- Método `consultarPorChave` agora processa corretamente respostas gzip e JSON com `nfseXmlGZipB64`
- Fallback para XML local quando a API do Emissor Nacional não estiver disponível

## [1.0] — 2026-03-16

### Lançamento inicial

- Emissão de NFS-e Padrão Nacional via API REST SefinNacional (SPED v1.00)
- Autenticação mTLS com certificado A1 ICP-Brasil
- Três modos de emissão: Manual, Ao emitir a fatura, Ao pagar a fatura
- Dashboard com estatísticas e listagem de notas
- Widget na tela de fatura do admin WHMCS
- Configuração de serviço por produto WHMCS (LC 116, NBS, tributação)
- Exportação de XMLs em ZIP por período com índice CSV
- Diagnóstico de conectividade com a API
- Debug configurável (ativa/desativa arquivos de log)
- Migration automática das tabelas na primeira carga
- Assinatura RSA-SHA256 com C14N Exclusive WithComments
