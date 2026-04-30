# NFSE Nacional — Addon WHMCS v1.5

Addon para emissão de **NFS-e Padrão Nacional** (SefinNacional SPED v1.00) diretamente pelo WHMCS, via API REST com autenticação mTLS (certificado digital A1 ICP-Brasil).

---

## Funcionalidades

- **Emissão automática** ao criar ou pagar uma fatura, ou **manual** pelo dashboard
- **Dashboard** com estatísticas (emitidas, pendentes, erros) e listagem das últimas notas
- **Widget na fatura** — botões de emitir, ver e baixar XML diretamente na tela de edição de fatura no admin
- **Configuração por produto** — código de serviço (LC 116), tributação municipal/nacional e NBS individualmente por produto WHMCS
- **Diagnóstico de conectividade** — testa DNS, TCP 443 e HTTPS para os endpoints da Receita Federal
- **Exportar XML** — baixa ZIP com XMLs de todas as notas de um período
- **Debug configurável** — salva arquivos `debug_*.xml/txt` apenas quando ativado (desative em produção)

---

## Requisitos

- WHMCS 8.x ou superior
- PHP 7.4+ com extensões: `openssl`, `curl`, `dom`, `zlib`, `mbstring`
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
| Número DPS Inicial | Evitar conflito com outro sistema emissor |

---

## Modos de Emissão

| Modo | Comportamento |
|------|--------------|
| **Manual** | A nota só é emitida pelo botão no Dashboard ou no widget da fatura |
| **Ao emitir a fatura** | A nota é emitida automaticamente quando uma fatura é criada no WHMCS |
| **Ao pagar a fatura** | A nota é emitida automaticamente quando o pagamento da fatura é confirmado |

---

## Estrutura do Projeto

```
modules/addons/nfse_nacional/
├── nfse_nacional.php          ← Config, activate, output, migrations
└── lib/
    ├── NfseApiClient.php      ← Cliente REST SefinNacional (mTLS direto)
    ├── NfseXmlBuilder.php     ← Geração do XML DPS (SPED v1.00)
    ├── NfseSigner.php         ← Assinatura RSA-SHA256 + C14N Exclusive WithComments
    ├── NfseService.php        ← Orquestra emissão, consulta e lógica de negócio
    ├── NfseController.php     ← Interface admin (dashboard, produtos, exportar etc.)
    ├── CertManager.php        ← Upload e armazenamento do certificado A1
    └── NfseDiagnostico.php    ← Testa DNS, TCP e HTTPS para a API

includes/hooks/
└── nfse_nacional_hooks.php    ← Hooks InvoiceCreation, InvoicePaid e widget admin
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
- Apenas municípios aderentes ao Padrão Nacional são suportados. Verifique em [nfse.gov.br](https://www.nfse.gov.br).
- O certificado digital **não deve ser versionado**. O arquivo `.pfx` é armazenado na pasta `certs/` que está no `.gitignore`.

---

## Licença

GPL 3.0 — Use por sua conta e risco. Sem garantias de qualquer tipo.
