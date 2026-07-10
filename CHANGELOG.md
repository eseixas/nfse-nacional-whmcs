# Changelog

## [1.7.3] — 2026-07-10

### Correções

- **Botão Emitir na fatura (WHMCS 9)** — o widget da fatura usava links relativos (`addonmodules.php?...`). Nas URLs amigáveis do WHMCS 9 (ex.: `/gerenciamento/billing/invoice/59`) isso resolvia para um caminho inexistente, resultando em "Page Not Found" ao clicar em Emitir. Os links agora são montados a partir da base absoluta do admin (emitir, ver NFS-e, baixar XML/PDF e configurar certificado).

## [1.7.2] — 2026-07-10

### Correções

- **Emissão manual** — o botão de emitir no dashboard passa a permitir emissão mesmo com a fatura em aberto (`allow_unpaid`), tratando a ação manual como decisão operacional do operador. Antes, exigia fatura `Paid` independentemente do modo do cliente. O hook `InvoicePaid` continua exigindo fatura paga.

## [1.7.1] — 2026-06-15

### Correções

- **Status do certificado** — `getStatus()` removia a senha do `cert_meta.json` antes de descriptografá-la, fazendo o painel exibir "Certificado ilegível" mesmo com PFX e senha válidos; a validação ao vivo do certificado volta a funcionar corretamente.
- **Dashboard após emissão** — corrigida duplicação do cabeçalho e menu de navegação ao emitir NFS-e manualmente; mensagens de sucesso/erro passam a aparecer uma única vez, logo abaixo do menu.

### Infraestrutura

- **Deploy** — `deploy.ps1` publica o addon via SCP/SSH com staging remoto e `sudo` no servidor; `deploy_ftp.ps1` redireciona para o novo fluxo. Pastas `certs/` e `debug/` não são sobrescritas no deploy.

## [1.7.0] — 2026-06-05

### Funcionalidades

- Reintroduzida a geração de PDF DANFSe a partir do XML autorizado da NFS-e, com QR-Code oficial para consulta pública nacional.
- Adicionado download individual de PDF no dashboard, na visualização da NFS-e e no widget da fatura.
- Exportação por período agora permite baixar ZIP de XMLs ou ZIP de PDFs DANFSe.

### Segurança e Robustez

- Parsing XML endurecido com `LIBXML_NONET` nos pontos de leitura de XML da API, banco e assinatura.
- Certificados e arquivos de debug passam a aceitar caminho de armazenamento protegido fora do webroot, mantendo fallback para o diretório legado do addon.
- Novos uploads de certificado agora exigem a criptografia nativa do WHMCS; o formato legado segue disponível apenas para leitura.
- Consultas ViaCEP passam a usar cache por CEP para evitar chamadas síncronas repetidas durante emissão.

## [1.6.8] — 2026-05-19

### Limpeza

- Removido JavaScript defensivo de remoção de botões PDF do `NfseController` e do widget de fatura — markup PHP que gerava esses botões já não existe, então o JS era dead code (eliminava FOUC e dependência de JS habilitado).
- Substituída string legada "Addons -> NFS-e BH" por "Addons -> NFSE Nacional" em `CertManager` (mensagem de exceção quando o certificado não foi carregado).
- Atualizado header do `nfse_nacional.php` (removida referência herdada a "Belo Horizonte") e mensagem de ativação do addon alinhada à versão atual.

## [1.6.7] — 2026-05-06

### Correções

- **Mapeamento do Alerta de Ambiente** — Corrigida a validação nas views do painel (Controller e Hooks) que exibia incorretamente o aviso de "Produção Restrita" mesmo quando configurado para Produção.

## [1.6.6] — 2026-05-06

### Correções

- **Mapeamento de Configurações** — Adicionado suporte robusto para campos do dropdown de configuração (`regime_tributario`, `tp_ret_issqn`, `ambiente` e `emissao_automatica`) em `NfseXmlBuilder`, `NfseController` e hooks, permitindo a transição entre valores numéricos legados e novas chaves textuais limpas.

## [1.6.5] — 2026-05-06

### Melhorias

- **Visualização das Opções do Simples Nacional** — As chaves das opções foram removidas do dropdown de configuração (`optante_simples`) para evitar exibição duplicada/confusa no painel do WHMCS. As opções agora são exibidas puramente como valores legíveis ("Optante (ME/EPP)", "Optante (MEI)", "Nao Optante"). O código do `NfseXmlBuilder.php` foi ajustado para mapear estes textos diretamente para os códigos `opSimpNac` da SefinNacional.
## [1.6.4] — 2026-05-06

### Correções

- **Opção Simples Nacional (E0160)** — corrigido o mapeamento do campo `opSimpNac` enviado à Receita. O valor `1` no WHMCS (que antes era exibido como "Sim") estava sendo enviado como "Não Optante" (`1` no schema), causando erro E0160 por divergência com o cadastro da Receita. Foram introduzidas novas chaves na configuração (`meepp`, `mei`, `nao`) para clarificar a exibição e adicionado tratamento retroativo em `NfseXmlBuilder.php` para mapear corretamente o `1` (antigo "Sim") para `3` (Optante ME/EPP) e o `2` (antigo "Não") para `1` (Não Optante). A tag `<regApTribSN>` agora é corretamente omitida para Não Optantes.
## [1.6.3] — 2026-05-06

### Correções

- **Clock Skew Timezone (E0008)** — corrigido bug onde o servidor PHP em UTC gerava data/hora correta globalmente (`+00:00`), mas a API da SefinNacional comparava o timestamp desconsiderando o offset, gerando erro de data no futuro. Agora o XML sempre força o timezone de Brasília (`America/Sao_Paulo`) antes da formatação, garantindo conformidade estrita com o fuso local brasileiro.

## [1.6.2] — 2026-05-06

### Correções

- **cTribMun (RNG6110)** — valor padrão de `codigo_tributacao_municipio` corrigido de `010800001` (9 dígitos, inválido pelo schema `TCCodTribMun`) para `001` (3 dígitos, desdobramento municipal).
- **Clock Skew (E0008)** — ajuste do offset de tempo de 60s para 600s (10 minutos) para evitar erro de data de emissão posterior ao processamento em servidores com relógio dessincronizado.
- **Deploy FTP** — script de deploy (`deploy_ftp.ps1`) reescrito para utilizar `lftp` via WSL, garantindo maior estabilidade e suporte a mirror incremental. Credenciais de produção atualizadas no `CLAUDE.md`.

## [1.6] — 2026-04-30

### Correções

- **Modo ao criar fatura** — `InvoiceCreation` agora emite mesmo quando a fatura ainda nao esta paga; emissão manual e `InvoicePaid` continuam exigindo fatura `Paid`
- **Schema centralizado** — criação/migração de tabelas consolidada em uma função compartilhada por `activate()` e pela interface do addon
- **Certificado** — senha do `.pfx` passa a usar `encrypt()`/`decrypt()` do WHMCS quando disponível, preservando leitura do formato legado
- **Higiene de release** — arquivos texto normalizados para LF e `.gitattributes` adicionado para evitar diffs ruidosos por CRLF
- **Segurança admin** — validação de filtros de exportação reforçada e escapes HTML adicionados em saídas do dashboard, widget e logs
- **Licença** — manifesto `whmcs.json` alinhado para GPL-3.0

## [1.5] — 2026-04-30

### Correções

- **Race condition DPS (completo)** — toda a seção crítica (reserva de n_dps + build XML + assinatura + validação + upsert) agora ocorre em uma única transação com `SELECT … FOR UPDATE`; emissões concorrentes são impossíveis de pegar o mesmo número
- **n_dps no retry** — reemissão/retry agora atualiza o campo `n_dps` no banco junto com o novo XML assinado; banco e XML não ficam mais divergentes
- **Config XML completo** — adicionados campos globais `codigo_tributacao_nacional`, `codigo_nbs`, `perc_trib_sn` e `tp_ret_issqn` ao config do addon; XmlBuilder corrigido para ler `codigo_tributacao_nacional` e `codigo_nbs` em vez dos aliases inexistentes `c_trib_nac`/`c_nbs`
- **logModuleCall** — chamadas à API SefinNacional agora registradas no Module Log nativo do WHMCS
- **debugDir lazy** — `debugDir()` agora só é invocado quando o debug está ativo; o diretório `debug/` não é mais criado desnecessariamente

## [1.4] — 2026-04-30

### Correções

- **Schema** — `activate()` agora cria `n_dps` e `n_dfse` na tabela principal; emissão automática antes da primeira visita ao addon não falha mais com *unknown column*
- **Config XML** — `NfseXmlBuilder` corrigido para ler as chaves corretas do config do addon: `optante_simples` (era `op_simples`) e `regime_tributario` (era `reg_ap_trib_sn`) e `codigo_tributacao_municipio` (era `c_trib_mun`); valores do admin agora são efetivamente usados no XML
- **CSRF** — formulário de Serviços/Produtos agora inclui e valida token CSRF
- **Race condition DPS** — `nextDpsNumber()` usa `SELECT … FOR UPDATE` dentro de transação; emissões simultâneas não geram mais o mesmo número de DPS
- **Debug seguro** — arquivos de debug movidos para subdiretório `debug/` protegido com `.htaccess`; não ficam mais expostos na raiz do addon
- **WHMCS guard** — `NfseController`, `CertManager` e `NfseDiagnostico` agora recusam acesso direto via HTTP
- **Migration** — `ALTER TABLE MODIFY COLUMN` removido da execução em toda requisição; schema já é criado corretamente no `activate()` e nas migrations iniciais
- **ViaCEP** — timeout reduzido de 5s para 3s

## [1.3] — 2026-04-27

### Remoções

- Exportação de **PDF** removida em definitivo do addon; exportação mantida apenas como ZIP de XMLs
- Removidas as últimas referências de release e pacote que ainda indicavam download de PDF

### Correções

- Versão do addon alinhada para `1.3` nos metadados e na documentação

## [1.2] — 2026-04-22

### Novidades

- Widget de emissão na fatura agora posicionado entre **Summary** e **Invoice Items** no WHMCS 9 (estratégia de inserção multi-layout aprimorada)

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
