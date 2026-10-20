=== Portal Push — Pessoas e Perfis ===
Contributors: paulomaeda
Tags: push, notifications, portal, web-push
Requires at least: 6.4
Tested up to: 6.8.3
Requires PHP: 8.2
Stable tag: 0.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Push no navegador e no PWA instalado, por pessoa ou perfil dos usuários do WordPress.

== Description ==

Envie notificações na área de avisos do navegador e do smartphone, inclusive pelo PWA instalado. Use contas e papéis existentes do WordPress para selecionar uma pessoa, vários perfis ou todos os habilitados. A página de dispositivos é opcional e não é uma caixa de mensagens.

O cadastro, as chaves VAPID, as campanhas, a fila e os relatórios ficam na sua hospedagem. Não requer conta no pushnotifications.io, OneSignal nem projeto Firebase. A entrega utiliza os serviços Web Push dos navegadores. O transporte criptográfico é executado localmente pela biblioteca aberta minishlink/web-push, incluída no pacote com suas dependências e licenças.

* Contas existentes; cadastro e edição de usuários pela tela nativa do WordPress.
* Perfis são os papéis do WordPress, inclusive os personalizados de outros plugins.
* Vários dispositivos por pessoa, com autorização individual (até 50 ativos).
* Envio por pessoa, vários perfis (OU) ou todas as pessoas habilitadas.
* Agendamento pelo fuso do WordPress e cancelamento de envios pendentes.
* Mensagem com título, texto, link do portal e imagem opcional.
* Fila persistente, bloqueio de concorrência e até três tentativas para falhas transitórias.
* Desativação automática de inscrições expiradas (HTTP 404/410).
* Histórico de campanhas, resultados por dispositivo e cliques únicos por dispositivo.
* Botão de ativação nas páginas para usuários conectados e [portal_push] opcional para gerenciar dispositivos.
* Integração ao worker de PWA for WP e SuperPWA, preservando o aplicativo existente.
* Pausa neste navegador ao sair da conta.
* Integração com as ferramentas de exportação e apagamento de dados do WordPress.
* API PHP portal_push_send() para integrações próprias.

== Installation ==

1. Acesse Plugins > Adicionar plugin > Enviar plugin.
2. Envie portal-push-0.3.0.zip e ative.
3. Abra Portal Push > Configurações e gere as chaves de envio.
4. Confira as contas e os papéis em Usuários no WordPress.
5. Cada pessoa entra no portal e clica em Ativar notificações no próprio dispositivo.
6. Opcional: crie uma página com [portal_push] para testar e gerenciar os dispositivos.
7. Crie uma campanha e selecione pessoas, perfis ou todos.

Requer HTTPS, PHP 8.2+, OpenSSL, cURL e mbstring. GMP ou BCMath são opcionais para melhorar o desempenho. O ZIP já inclui as dependências; não é necessário executar Composer na hospedagem.

== Frequently Asked Questions ==

= Cadastrar alguém já permite enviar push? =
Não. A pessoa precisa entrar no portal e autorizar as notificações em um navegador compatível. Criar a conta ou conhecer o e-mail não concede autorização de push.

= O que são os perfis? =
São os papéis dos usuários do WordPress: Assinante, Editor e os personalizados existentes no portal. Selecioná-los no envio não altera permissões nem cria outros grupos.

= Uma pessoa em dois perfis recebe duas vezes? =
Cada dispositivo recebe uma notificação por campanha, mesmo quando a pessoa participa de vários perfis selecionados. Se tiver celular e computador ativos, poderá receber em ambos.

= Funciona no iPhone? =
O fluxo padrão exige iOS/iPadOS 16.4 ou posterior, portal adicionado à Tela de Início, aberto pelo ícone e autorização pelo usuário. É necessário validar a compatibilidade nos dispositivos de destino.

= Por que uma campanha demora? =
A fila depende do agendador WordPress. Em portais com poucas visitas, configure wp-cron.php a cada minuto na hospedagem. O botão Processar fila agora executa uma rodada manual.

= Aceita pelo serviço significa que foi lida? =
Não. O serviço do navegador aceitou a requisição. O dispositivo pode estar offline ou com notificações bloqueadas pelo sistema. Cliques são medidos separadamente.

= Posso usar com um PWA já existente? =
PWA for WP e SuperPWA recebem os eventos de push em seu próprio worker. Feche e reabra o aplicativo depois da atualização. Outros PWAs precisam incorporar o módulo documentado no guia; um worker sem integração é preservado. O filtro portal_push_disable_manifest permite desabilitar o manifesto básico.

== Changelog ==

= 0.3.0 =
* Segmentação pelos papéis nativos e personalizados dos usuários do WordPress.
* Cadastro e edição de contas nas telas nativas; remoção dos grupos próprios da interface.
* Botão de ativação nas páginas para usuários conectados; página de dispositivos opcional.
* Integração dos eventos push ao service worker de PWA for WP e SuperPWA.
* Verificação de suporte antes de usar um worker existente, sem substituí-lo.
* Migração preserva contas, papéis, chaves e dispositivos; cancela pendências dos antigos grupos.

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
