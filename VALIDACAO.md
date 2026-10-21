# Validação — Portal Push 0.4.0

Data: 21/10/2026. Ambiente: WordPress 6.8.3, PHP 8.3.6 e banco local pelo plugin oficial SQLite Database Integration.

## Conjuntos de verificação

| Conjunto executado na 0.4.0 | Verificações | Evidência |
|---|---:|---|
| Modelos e API PHP | 45 | Cadastro, variáveis, links seguros, públicos por pessoa/papel, chamadas por função/gancho, erros, modelo pausado, compatibilidade da API anterior e deduplicação, incluindo colisão no índice único. |
| Papéis e migração | 17 | Papéis padrão/personalizados, mudança de papel, rejeição de públicos inválidos, cancelamento de pendências legadas e preservação de contas, papéis e chaves. |
| Painel e endpoints HTTP | 53 | Sessões/cookies reais, sete telas, cadastro/edição/pausa/exclusão de modelos, disparo pela API, histórico preservado, públicos, CSRF e autorização. |
| Eventos do worker | 14 | `showNotification`, destino seguro do clique, token, confirmação ao cliente, integração sem assumir instalação/cache e isolamento de mensagens de outros serviços. |
| Ativação no cliente | 15 | Permissão somente por clique, registro existente do PWA, bloqueio de worker sem integração, chave VAPID, pausa e orientação para iPhone. |
| Atualizador GitHub | 39 | Manifesto, cache, falhas de rede, origem do ZIP, SHA-256, rejeição de pacote adulterado e instalação pelo `Plugin_Upgrader`, preservando também modelos e chaves de eventos. |

Na base 0.3.1 também foram aprovadas 53 verificações do núcleo (inscrições, fila, horários, novas tentativas, logout e privacidade), 10 verificações com fontes reais do SuperPWA 2.2.49 e PWA for WP 1.7.88.2, 12 de reparo com SuperPWA e duas verificações criptográficas independentes (RFC 8291/AES-GCM e VAPID ES256). Esses conjuntos completos não foram repetidos na 0.4.0; os testes HTTP desta versão voltam a verificar o reparo do SuperPWA.

Os testes anteriores de envio usam respostas HTTP simuladas; assinatura e criptografia são reais. Os testes do atualizador usam rede simulada e ZIP real. A atualização instala uma versão futura simulada somente na cópia descartável e preserva chaves, papéis, dispositivos, modelos e identificadores de eventos. Essa versão futura não é publicada.

Os testes JavaScript executam os módulos em ambientes controlados, sem navegador gráfico. Os templates dos dois PWAs também passam pela análise sintática do Node. O PHP e o JavaScript do plugin passam pela validação sintática. Não foram enviados avisos a pessoas reais.

Testes reproduzíveis no repositório:

```bash
node tests/service-worker.cjs
node tests/frontend.cjs
# Somente em WordPress descartável em localhost:
wp eval-file tests/roles.php
wp eval-file tests/api.php
# Com SuperPWA ativo na instalação descartável:
wp eval-file tests/superpwa.php
# Altera os arquivos da cópia instalada; execute somente em ambiente descartável:
wp eval-file tests/updater.php
```

O teste de atualização deve ser chamado a partir do repositório de desenvolvimento, separado da cópia instalada no WordPress, com `dist/` e `updates/` presentes.

## Correção 0.3.1

O fluxo anterior verificava o worker ativo logo após `registration.update()` e podia recusar a inscrição enquanto a versão nova ainda estava instalando ou aguardando ativação. Foram reproduzidos e verificados os casos de instalação demorada, worker integrado aguardando ativação, troca do worker durante a confirmação inicial, worker sem integração e sincronização sem gesto do usuário.

A correção aguarda a instalação e solicita a ativação integrada após o clique. O painel oferece reparo com verificação do arquivo/template local e revisão na URL do SuperPWA. Os testes HTTP também verificam o botão, o resultado da operação, o nonce e a restrição de acesso. O usuário relatou que a entrega no PWA do celular funcionou após essa versão. O teste local não identifica se a hospedagem/CDN serve uma cópia antiga.

## Integrações 0.4.0

Os modelos ficam em uma opção do WordPress. Cada chamada gera uma campanha com uma cópia do conteúdo e do público, enviada pela fila existente. A migração acrescenta uma coluna anulável com índice único às campanhas; campanhas antigas continuam sem chave. O índice evita a criação duplicada quando duas chamadas usam a mesma chave de evento.

Os testes executam a função e o gancho em código PHP confiável, sem criar endpoint público de disparo. O teste de atualização usa um modelo e uma campanha com chave presentes no banco. O adaptador para um plugin de terceiros específico depende do nome real do seu gancho e dos argumentos disponíveis; os ganchos do guia são exemplos.

## Homologação no portal

1. Instalar em ambiente HTTPS com a mesma configuração de PHP, MySQL/MariaDB, tema, plugins e cache da hospedagem.
2. Gerar as chaves e usar duas contas com papéis existentes do WordPress.
3. Autorizar o push em computador e celular. No iPhone, abrir pelo ícone adicionado à Tela de Início.
4. Enviar para uma pessoa e conferir que a outra não recebe; depois testar um papel e a combinação de papéis sem duplicação.
5. Conferir aviso com página/PWA fechado, toque abrindo o destino, dispositivo offline e modo de concentração.
6. Conferir a integração com o plugin/worker PWA efetivamente usado no portal, incluindo atualização dos arquivos e exclusões de cache para sessões/endpoints autenticados.
7. Testar mudança de papel, agendamento, pausa de dispositivo, logout e troca de conta.
8. Validar o acesso do WordPress ao manifesto/ZIP públicos do GitHub e a atualização em homologação. Neste executor, o DNS usado pelo PHP não resolve o domínio raw do GitHub; por isso, o teste nativo usa a rede simulada sem enfraquecer a validação HTTP do plugin.
9. Configurar o agendador e dimensionar a hospedagem para o volume esperado.
10. Cadastrar um modelo e conectar o gancho real do plugin escolhido. Conferir variáveis, destinatário, ID da campanha e repetição do mesmo `event_id` antes de ativar o evento em produção.

## Limites

- A versão 0.4.0 foi verificada localmente; o relato de entrega no PWA do celular refere-se à base 0.3.1. O executor não realizou teste em dispositivo físico ou no domínio HTTPS do usuário.
- Não houve inspeção visual em navegador gráfico, teste de carga ou homologação com todos os temas/plugins.
- O banco local usou SQLite; MySQL/MariaDB precisa de homologação na hospedagem.
- PWA for WP e SuperPWA tiveram templates/arquivos testados, sem validar instalação e entrega em um telefone real. Outros PWAs exigem integração específica do módulo ao seu worker.
- Imagem, ícone e apresentação do aviso dependem do navegador/sistema. Aceitação pelo serviço não comprova exibição ou leitura.
- O guia oferece adaptadores de exemplo; gatilhos específicos de terceiros não são conectados automaticamente. Caixa de mensagens internas e e-mail não estão incluídos.
