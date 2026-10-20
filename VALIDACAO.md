# Validação — Portal Push 0.3.0

Data: 20/10/2026. Ambiente: WordPress 6.8.3, PHP 8.3.6 e banco local pelo plugin oficial SQLite Database Integration.

## Conjuntos de verificação

| Conjunto | Verificações | Evidência |
|---|---:|---|
| Integração do núcleo | 53 | Contas WordPress, múltiplos papéis, inscrições, propriedade dos dispositivos, públicos, fila, horários, novas tentativas, logout e privacidade. |
| Papéis e migração | 17 | Papéis padrão/personalizados, mudança de papel, rejeição de públicos inválidos, cancelamento de pendências legadas e preservação de contas, papéis e chaves. |
| Painel e endpoints HTTP | 38 | Sessões/cookies reais, seis telas, público por papel, preferências de recebimento, CSRF, autorização REST, convite nas páginas e ausência de promoção de usuários por campos forjados. |
| Eventos do worker | 12 | `showNotification`, destino seguro do clique, token, confirmação ao cliente, integração sem assumir instalação/cache e isolamento de mensagens de outros serviços. |
| Ativação no cliente | 10 | Permissão somente por clique, registro existente do PWA, bloqueio de worker sem integração, chave VAPID, pausa e orientação para iPhone. |
| Fontes reais dos PWAs | 10 | Geração de templates e arquivos estáticos com SuperPWA 2.2.49 e PWA for WP 1.7.88.2, manutenção do código do PWA e ausência de integração duplicada. |
| Criptografia independente | 2 | Decifragem RFC 8291/AES-GCM e verificação VAPID ES256 com Python/cryptography. |
| Atualizador GitHub | 37 | Manifesto, cache, falhas de rede, origem do ZIP, SHA-256, rejeição de pacote adulterado e instalação pelo `Plugin_Upgrader`. |

Os testes de envio usam respostas HTTP simuladas; assinatura e criptografia são reais. Os testes do atualizador usam rede simulada e ZIP real. A atualização instala uma versão futura simulada somente na cópia descartável e preserva chaves, papéis e dispositivos. Essa versão futura não é publicada.

Os testes JavaScript executam os módulos em ambientes controlados, sem navegador gráfico. Os templates dos dois PWAs também passam pela análise sintática do Node. O PHP e o JavaScript do plugin passam pela validação sintática. Não foram enviados avisos a pessoas reais.

Testes reproduzíveis no repositório:

```bash
node tests/service-worker.cjs
node tests/frontend.cjs
# Somente em WordPress descartável em localhost:
wp eval-file tests/roles.php
# Altera os arquivos da cópia instalada; execute somente em ambiente descartável:
wp eval-file tests/updater.php
```

O teste de atualização deve ser chamado a partir do repositório de desenvolvimento, separado da cópia instalada no WordPress, com `dist/` e `updates/` presentes.

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

## Limites

- A entrega ainda não foi validada em dispositivos físicos nem no domínio HTTPS do usuário.
- Não houve inspeção visual em navegador gráfico, teste de carga ou homologação com todos os temas/plugins.
- O banco local usou SQLite; MySQL/MariaDB precisa de homologação na hospedagem.
- PWA for WP e SuperPWA tiveram templates/arquivos testados, sem validar instalação e entrega em um telefone real. Outros PWAs exigem integração específica do módulo ao seu worker.
- Imagem, ícone e apresentação do aviso dependem do navegador/sistema. Aceitação pelo serviço não comprova exibição ou leitura.
- Gatilhos automáticos de publicação, campos personalizados, caixa de mensagens internas e e-mail não estão incluídos.
