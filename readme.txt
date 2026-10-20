=== Portal Push — Pessoas e Perfis ===
Contributors: paulomaeda
Tags: push, notifications, portal, web-push
Requires at least: 6.4
Tested up to: 6.8.3
Requires PHP: 8.2
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Notificações Web Push com processamento no próprio WordPress, pessoas e perfis do portal.

== Description ==

Primeira versão para portais com pessoas autenticadas. Permite cadastrar contas, criar perfis de destinatários e enviar campanhas individuais, por vários perfis ou para todas as pessoas habilitadas.

O cadastro, as chaves VAPID, as campanhas, a fila e os relatórios ficam na sua hospedagem. Não requer conta no pushnotifications.io, OneSignal nem projeto Firebase. A entrega utiliza os serviços Web Push dos navegadores. O transporte criptográfico é executado localmente pela biblioteca aberta minishlink/web-push, incluída no pacote com suas dependências e licenças.

* Contas WordPress existentes ou novas pessoas com acesso básico.
* Vários perfis por pessoa, independentes das permissões do WordPress.
* Vários dispositivos por pessoa, com autorização individual (até 50 ativos).
* Envio por pessoa, vários perfis (OU) ou todas as pessoas habilitadas.
* Agendamento pelo fuso do WordPress e cancelamento de envios pendentes.
* Mensagem com título, texto, link do portal e imagem opcional.
* Fila persistente, bloqueio de concorrência e até três tentativas para falhas transitórias.
* Desativação automática de inscrições expiradas (HTTP 404/410).
* Histórico de campanhas, resultados por dispositivo e cliques únicos por dispositivo.
* Página de inscrição com [portal_push] e gerenciamento dos dispositivos.
* Pausa neste navegador ao sair da conta.
* Integração com as ferramentas de exportação e apagamento de dados do WordPress.
* API PHP portal_push_send() para integrações próprias.

== Installation ==

1. Acesse Plugins > Adicionar plugin > Enviar plugin.
2. Envie portal-push-0.2.0.zip e ative.
3. Abra Portal Push > Configurações e gere as chaves de envio.
4. Crie a página de notificações pelo botão ou adicione [portal_push] a uma página comum do WordPress.
5. Crie os perfis e cadastre/vincule as pessoas.
6. Cada pessoa entra na página e ativa o recebimento em seu dispositivo.
7. Crie uma campanha e selecione pessoas, perfis ou todos.

Requer HTTPS, PHP 8.2+, OpenSSL, cURL e mbstring. GMP ou BCMath são opcionais para melhorar o desempenho. O ZIP já inclui as dependências; não é necessário executar Composer na hospedagem.

== Frequently Asked Questions ==

= Cadastrar alguém já permite enviar push? =
Não. A pessoa precisa entrar no portal e autorizar as notificações em um navegador compatível. Criar a conta ou conhecer o e-mail não concede autorização de push.

= Os perfis alteram o acesso às páginas? =
Não. São grupos de destinatários. Os papéis e as permissões de acesso do WordPress continuam sendo administrados separadamente.

= Uma pessoa em dois perfis recebe duas vezes? =
Cada dispositivo recebe uma notificação por campanha, mesmo quando a pessoa participa de vários perfis selecionados. Se tiver celular e computador ativos, poderá receber em ambos.

= Funciona no iPhone? =
O fluxo padrão exige iOS/iPadOS 16.4 ou posterior, portal adicionado à Tela de Início, aberto pelo ícone e autorização pelo usuário. É necessário validar a compatibilidade nos dispositivos de destino.

= Por que uma campanha demora? =
A fila depende do agendador WordPress. Em portais com poucas visitas, configure wp-cron.php a cada minuto na hospedagem. O botão Processar fila agora executa uma rodada manual.

= Aceita pelo serviço significa que foi lida? =
Não. O serviço do navegador aceitou a requisição. O dispositivo pode estar offline ou com notificações bloqueadas pelo sistema. Cliques são medidos separadamente.

= Posso usar com um PWA já existente? =
Esta versão detecta um service worker existente e evita substituí-lo. Nesse caso é necessária uma integração específica antes de ativar o recebimento. O filtro portal_push_disable_manifest permite desabilitar o manifesto incluído.

== Changelog ==

= 0.2.0 =
* Atualizações nativas pelo WordPress a partir do GitHub.
* Controle da instalação automática pela preferência nativa do WordPress.
* Verificação SHA-256 e preservação do diretório instalado.
* Pacote versionado e manifesto gerados por tools/build-release.py.

= 0.1.0 =
* Primeira versão: núcleo Web Push próprio, pessoas, múltiplos perfis, dispositivos e campanhas segmentadas.

== Credits ==

Biblioteca Web Push: minishlink/web-push 10.1.0 (MIT), https://github.com/web-push-libs/web-push-php.
Outras dependências e licenças constam em vendor/composer/installed.json e nos diretórios de cada pacote.
Dependências isoladas no namespace PortalPushVendor.

Consulte LEIA-ME.md para configuração, integração e validações desta versão.
