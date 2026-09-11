# NFSe Nacional — Addon WHMCS v1.7.4

Addon para emissão de **NFS-e Padrão Nacional** (SefinNacional SPED v1.00) diretamente pelo WHMCS, via API REST com autenticação mTLS (certificado digital A1 ICP-Brasil).

---

## Funcionalidades

- **Emissão automática** ao criar ou pagar uma fatura, ou **manual** pelo dashboard
- **Dashboard** com estatísticas (emitidas, pendentes, erros) e listagem das últimas notas
- **Widget na fatura** — botões de emitir, ver e baixar XML diretamente na tela de edição de fatura no admin
- **Configuração por produto** — código de serviço (LC 116), tributação municipal/nacional e NBS individualmente por produto WHMCS
- **Diagnóstico de conectividade** — testa DNS, TCP 443 e HTTPS para os endpoints da Receita Federal
- **PDF DANFSe** — gera PDF oficial com QR-Code para consulta pública nacional
- **Exportar XML/PDF** — baixa ZIP com XMLs ou PDFs de todas as notas de um período
- **Debug configurável** — salva arquivos `debug_*.xml/txt` apenas quando ativado, preferencialmente em armazenamento protegido fora do webroot (desative em produção)
- **Idempotência** — retry, timeout e clique duplo reutilizam a mesma DPS; não geram segunda nota na mesma fatura

---

## Requisitos

- WHMCS 8.x ou superior
- PHP 8.1+ com extensões: `openssl`, `curl`, `dom`, `zlib`, `mbstring`, `zip`
- TCPDF disponível no WHMCS para geração do DANFSe em PDF
- Certificado digital **A1 ICP-Brasil** (arquivo `.pfx` / PKCS#12) do prestador de serviços
- Município aderente ao **Padrão Nacional NFS-e** (`nfse.gov.br`)

---

## Instalação

1. Copie as pastas `modules/` e `includes/` para a raiz do seu WHMCS, respeitando a estrutura:

```
modules/addons/nfse_nacional/
includes/hooks/nfse_nacional_hooks.php
```

2. No admin do WHMCS, acesse **Configurações → Módulos de Addon → NFSE Nacional** e clique em **Ativar**.

3. Preencha as configurações (CNPJ, IM, regime tributário, ambiente etc.) e clique em **Salvar**.

4. Acesse **Addons → NFSE Nacional → Certificado Digital** e faça upload do arquivo `.pfx` com a senha.

5. Use **Addons → NFSE Nacional → Diagnóstico** para verificar a conectividade com a API.

6. Confira **Caminho de Armazenamento Protegido**. O padrão é `{ROOTDIR}/../nfse_nacional_data` (fora do webroot). Caminho dentro da pasta do WHMCS é recusado.

---

## Atualização (1.7.3 → 1.7.4)

1. Substitua `modules/addons/nfse_nacional/` e `includes/hooks/nfse_nacional_hooks.php` (não apague `certs/` legado no servidor).
2. No admin, abra o addon uma vez para o `ensure_schema` criar diretórios de storage.
3. Em **Configurações do Addon**, preencha **Caminho de Armazenamento Protegido** com um path fora do webroot, ou deixe o padrão `{ROOTDIR}/../nfse_nacional_data`.
4. Reenvie o certificado se quiser migrá-lo do `certs/` legado para o storage protegido (a leitura do PFX antigo continua funcionando).
5. Revise **Servicos/Produtos**: a config por produto agora usa `tblhosting.packageid`. Mapeamentos que “funcionavam” porque o `relid` coincidia com o ID do produto precisam ser conferidos.
6. No WHMCS, **Utilidades → Atualizar** (ou reabra o addon) para registrar a versão 1.7.4.

---

## Testes

Na raiz do repositório (PHP 8.1+, sem WHMCS):

```bash
php tests/run.php
```

Cobre política de emissão (idempotência), storage fora do webroot, resolução `packageid`, `totTrib`, endereço estrangeiro e `xDescServ`.

---

## Configurações

| Campo | Descrição |
|-------|-----------|
| CNPJ do Prestador | Somente números |
| Inscrição Municipal | Cadastro na prefeitura |
| Razão Social | Nome da empresa |
| Regime Tributário | Simples Nacional, Excesso ou Normal |
| Alíquota ISS (%) | Alíquota do ISS (ex: `2.00`) |
| Ambiente | `Producao Restrita (Testes)` ou `Producao` |
| Modo de Emissão | `Manual`, `Ao emitir a fatura` ou `Ao pagar a fatura` |
| Debug | Ativa/desativa geração de arquivos de debug |
| Caminho de Armazenamento Protegido | Fora do webroot. Padrão `{ROOTDIR}/../nfse_nacional_data`. Aceita `{ROOTDIR}` |
| Número DPS Inicial | Evitar conflito com outro sistema emissor |

---

## Modos de Emissão

| Modo | Comportamento |
|------|--------------|
| **Manual** | A nota só é emitida pelo botão no Dashboard ou no widget da fatura |
| **Ao emitir a fatura** | A nota é emitida automaticamente quando uma fatura é criada no WHMCS, mesmo antes do pagamento |
| **Ao pagar a fatura** | A nota é emitida automaticamente quando o pagamento da fatura é confirmado |

---

## Estrutura do Projeto

```
modules/addons/nfse_nacional/
├── nfse_nacional.php          ← Config, activate, output, migrations
└── lib/
    ├── NfseApiClient.php      ← Cliente REST SefinNacional (mTLS direto)
    ├── NfsePdfGenerator.php   ← Geração do DANFSe PDF com QR-Code oficial
    ├── NfseXmlBuilder.php     ← Geração do XML DPS (SPED v1.00)
    ├── NfseSigner.php         ← Assinatura RSA-SHA256 + C14N Exclusive WithComments
    ├── NfseService.php        ← Orquestra emissão, consulta e lógica de negócio
    ├── NfseController.php     ← Interface admin (dashboard, produtos, exportar etc.)
    ├── CertManager.php        ← Upload e armazenamento do certificado A1
    ├── NfseStorage.php        ← Caminhos de cert/debug/cache fora do webroot
    ├── NfseEmissionPolicy.php ← Idempotência de retry (mesmo DPS)
    └── NfseDiagnostico.php    ← Testa DNS, TCP e HTTPS para a API

includes/hooks/
└── nfse_nacional_hooks.php    ← Hooks InvoiceCreation, InvoicePaid e widget admin

tests/
└── run.php                    ← Testes de política, storage e XML (sem WHMCS)
```

---

## Banco de Dados

Tabelas criadas automaticamente na primeira carga:

| Tabela | Conteúdo |
|--------|----------|
| `mod_nfse_nacional` | Registro de cada NFS-e emitida (número, chave, XML, status) |
| `mod_nfse_nacional_produtos` | Configuração de serviço por produto WHMCS |
| `mod_nfse_nacional_log` | Log de todas as operações |

---

## Observações

- O cancelamento de NFS-e via API (`POST /nfse/{chave}/eventos`) retorna HTTP 500 no servidor da Receita Federal para determinados cenários (bug confirmado). Use o [Emissor Nacional](https://www.nfse.gov.br/EmissorNacional) para cancelar manualmente quando necessário.
- A emissão pelo hook de pagamento (`InvoicePaid`) exige fatura paga. A **emissão manual** (botão no dashboard) e o modo **Ao emitir a fatura** permitem emissão antes do pagamento, por decisão operacional do usuário.
- Apenas municípios aderentes ao Padrão Nacional são suportados. Verifique em [nfse.gov.br](https://www.nfse.gov.br).
- O certificado digital **não deve ser versionado**. Novos uploads gravam fora do webroot (`{ROOTDIR}/../nfse_nacional_data` por padrão). O diretório legado `certs/` continua só para leitura.
- A senha do certificado é gravada com `encrypt()` / `decrypt()` do WHMCS. O formato legado continua apenas para leitura/migração.
- Configuração por produto aplica-se a itens de fatura do tipo **Hosting** (`tblhosting.packageid`). Não use o `relid` da fatura como ID de produto.
- Simples Nacional envia `pTotTribSN`. Não optante envia o grupo `pTotTrib` (sem `pTotTribSN`). Tomador no exterior usa `endExt`.
- A discriminação da nota (`xDescServ`) vem dos **itens da fatura**, não do campo Notes.
- Retry de emissão reutiliza o mesmo número de DPS quando a nota ainda não está `emitida` (exceto após cancelamento).
- O QR-Code do DANFSe usa a URL oficial `https://www.nfse.gov.br/ConsultaPublica/?tpc=1&chave={chave}`.

---

## Licença

GPL 3.0 — Use por sua conta e risco. Sem garantias de qualquer tipo.
