# Portal Push — Pessoas e Perfis

Versão **0.2.0**, desenvolvida para um portal WordPress com contas autenticadas. O pacote contém o código-fonte e as bibliotecas necessárias para instalar o plugin.

## O que está pronto

| Recurso | Comportamento |
|---|---|
| Pessoas | Utiliza as contas existentes do WordPress e permite cadastrar novas contas com acesso básico. |
| Perfis | Grupos de destinatários próprios do portal; cada pessoa pode pertencer a vários. |
| Dispositivos | Inscrição individual com autorização do navegador; até 50 dispositivos ativos por pessoa. |
| Campanhas | Envio para pessoas específicas, para qualquer um dos perfis selecionados ou para todos os habilitados. |
| Agendamento | Datas no fuso do WordPress, armazenamento em UTC, validade para dispositivos offline e cancelamento de pendências. |
| Relatórios | Histórico de campanhas, aceitação pelo serviço, falhas, tentativas e cliques únicos por dispositivo. |
| Portal | Página com login, perfis da pessoa, botão de ativação, teste e pausa de dispositivos. |
| Integrações | Função PHP para outros plugins colocarem avisos na fila. |

O processamento fica na sua hospedagem. Não exige conta em plataforma comercial, projeto Firebase ou assinatura de API de notificações. As bibliotecas abertas incluídas executam localmente a assinatura VAPID e a criptografia Web Push. A entrega usa o serviço de push do navegador da pessoa (Google, Mozilla, Apple ou Microsoft).

## Instalação e configuração

1. No WordPress, vá a **Plugins → Adicionar plugin → Enviar plugin** e envie o ZIP.
2. Ative **Portal Push — Pessoas e Perfis**.
3. Abra **Portal Push → Configurações** e confira o e-mail técnico. Ele identifica o responsável pelo envio; não dispara e-mails.
4. Clique em **Gerar chaves no servidor**. As chaves são geradas uma vez e preservadas.
5. Clique em **Criar página de notificações**. Também é possível inserir `[portal_push]` em uma página comum do WordPress e selecioná-la nas configurações.
6. Em **Perfis**, crie os grupos, por exemplo Equipe, Clientes e Gestores.
7. Em **Pessoas**, use **Editar perfis** nas contas existentes ou cadastre uma nova pessoa. No cadastro novo, informe nome, e-mail e senha inicial com pelo menos 12 caracteres. O plugin não envia credenciais por e-mail.
8. Compartilhe o endereço da página pelos seus canais habituais. Cada pessoa deve entrar com a conta WordPress e clicar em **Ativar neste dispositivo**.
9. Em **Nova notificação**, escolha o público, escreva a mensagem, informe um link do próprio portal e crie a campanha.

Instale inicialmente em homologação. O envio real precisa ser validado no domínio HTTPS do portal e nos navegadores usados pelas pessoas.

## Requisitos da hospedagem

- WordPress 6.4 ou posterior.
- PHP 8.2 ou posterior, com OpenSSL, cURL e mbstring.
- Site publicado por HTTPS e conexões HTTPS de saída liberadas para os serviços de push dos navegadores.
- GMP ou BCMath são opcionais e ajudam no desempenho criptográfico.
- Banco de dados e arquivo `wp-config.php` incluídos na rotina de backup; preserve também as chaves de envio.

O ZIP já inclui `vendor/`. Não é necessário executar Composer na hospedagem. As dependências foram isoladas no namespace `PortalPushVendor` para reduzir conflitos com outros plugins.

## Atualizações pelo WordPress

A partir da versão 0.2.0, o plugin consulta o manifesto público do repositório [paulommaeda/push-notifications](https://github.com/paulommaeda/push-notifications) e apresenta as próximas versões na tela **Plugins**. O ZIP baixado é verificado pelo SHA-256 antes da instalação.

Em **Portal Push → Configurações → Atualizações pelo WordPress**, você pode habilitar a instalação automática ou solicitar uma consulta imediata. A mesma preferência pode ser alterada na tela nativa de Plugins. O agendador, as permissões e as políticas da hospedagem continuam valendo.

Se estiver na versão 0.1.0, envie o ZIP 0.2.0 pelo painel uma vez, substituindo a instalação atual. As próximas atualizações poderão usar o mecanismo novo. Pessoas, perfis, dispositivos e chaves são preservados.

## Pessoas, perfis e autorização

- Criar uma conta não autoriza o recebimento. É preciso ativar cada navegador/dispositivo.
- O papel **Pessoa do portal** criado para novas contas possui apenas a capacidade básica `read`.
- Os perfis organizam os destinatários e não concedem acesso a páginas, cursos ou áreas restritas. Essa autorização permanece com o portal e com os papéis do WordPress.
- Os perfis são atribuídos pela administração; as pessoas não podem se incluir em outros grupos.
- Campanhas por perfis combinam os grupos por **OU**. Quem participa de vários grupos recebe uma vez em cada dispositivo ativo naquela campanha.
- A composição do público é calculada quando a campanha fica disponível para envio. Antes de cada disparo, o plugin verifica novamente pessoa, perfil e propriedade do dispositivo.
- A desativação do recebimento de uma pessoa bloqueia os disparos e novas inscrições. A conta continua existindo.
- Ao sair pelo logout do WordPress, o navegador identificado é pausado; os demais dispositivos permanecem ativos. A troca de conta no mesmo navegador requer ativação explícita e prova local do dispositivo.

## Fila, horários e relatórios

Confira **Configurações → Geral → Fuso horário** no WordPress. Para o Brasil, pode utilizar uma cidade como `America/Sao_Paulo`.

A fila é persistida em tabelas próprias e executada por WP-Cron a cada minuto. Cada rodada trabalha por até aproximadamente 20 segundos, com no máximo 100 inscrições e até 6 segundos de espera por requisição. Esses limites protegem o tempo de execução; o volume efetivo depende da hospedagem e da latência de rede. Esta versão ainda não foi submetida a teste de carga.

Para horários previsíveis em um portal com pouco tráfego, configure a hospedagem para chamar `wp-cron.php` a cada minuto. Se houver WP-CLI disponível:

```bash
wp cron event run --due-now --path=/caminho/do/wordpress
```

O botão **Processar fila agora** executa uma rodada manual. Falhas transitórias, como HTTP 429 ou 5xx, têm até três tentativas, com espera crescente e respeito ao `Retry-After`. HTTP 404/410 pausa uma inscrição expirada.

O estado **Aceita pelo serviço** indica que o provedor de push aceitou a requisição. Isso não comprova exibição nem leitura. O relatório de cliques conta uma vez por dispositivo. Uma queda entre o envio e a gravação do resultado pode exigir nova tentativa; a identificação estável da notificação reduz sua duplicação visual, mas a entrega não oferece garantia de “exatamente uma vez”.

## Celulares e PWA

No iPhone/iPad, o fluxo Web Push padrão requer iOS/iPadOS 16.4 ou posterior, portal adicionado à Tela de Início e aberto pelo ícone. A autorização deve partir de um toque da pessoa. O plugin apresenta essa orientação na página.

Esta versão fornece um manifesto básico e um service worker para notificações; não implementa navegação offline. Se o portal já utiliza outro service worker/PWA, o plugin detecta a situação e não o substitui. A integração com esse PWA precisa ser feita especificamente para o portal. O manifesto pode ser desabilitado por:

```php
add_filter('portal_push_disable_manifest', '__return_true');
```

Utilize o shortcode no conteúdo de uma página comum. Widgets ou templates que não armazenem o shortcode em `post_content` precisam apontar essa página em Configurações para o carregamento dos recursos.

## Integração com outros plugins

O código de um plugin confiável pode chamar `portal_push_send()` depois de o Portal Push ter sido carregado. A função cria uma campanha; o disparo acontece pela fila. Ela retorna o ID da campanha ou `WP_Error`.

### Enviar para uma pessoa

```php
$campaign_id = portal_push_send([
    'title' => 'Uma tarefa foi atribuída a você',
    'message' => 'Acesse o portal para consultar os detalhes.',
    'url' => home_url('/minhas-tarefas/'),
    'audience_type' => 'people',
    'audience_ids' => [123], // ID da conta WordPress, não o e-mail.
]);
```

### Enviar para um ou mais perfis

```php
$campaign_id = portal_push_send([
    'title' => 'Aviso para a equipe',
    'message' => 'Confira a atualização no portal.',
    'url' => home_url('/avisos/'),
    'audience_type' => 'profiles',
    'audience_ids' => [2, 5], // IDs dos perfis próprios do Portal Push.
    'ttl' => 86400,
    // Opcional: 'scheduled_at' => '2026-12-01 15:00:00', em UTC.
]);

if (is_wp_error($campaign_id)) {
    // Registre ou apresente o erro de acordo com o fluxo do seu plugin.
}
```

Para envio geral, use `audience_type => 'all'`. Um público de pessoas/perfis vazio é rejeitado, sem ser convertido em envio geral. O título aceita até 80 caracteres e a mensagem, 300; o payload total também é validado para respeitar os limites Web Push. O destino deve pertencer à mesma origem do portal.

Gatilhos de publicação de posts, integrações específicas com WooCommerce/ERP e painel centralizado entre sites não fazem parte desta primeira versão. Podem utilizar a API PHP acima ou ser acrescentados depois.

## Privacidade e manutenção

O plugin armazena as associações a perfis, inscrições técnicas dos dispositivos, campanhas e resultados de disparos/cliques. Não coleta localização e não grava IPs. Os endpoints e os segredos dos dispositivos não são expostos na listagem da API.

As ferramentas nativas **Exportar dados pessoais** e **Apagar dados pessoais** incluem os dados do plugin. O apagamento preserva a conta WordPress e desativa o recebimento. Ao excluir uma conta, seus vínculos, inscrições e registros individuais são removidos.

Por padrão, excluir o plugin preserva os dados. Para removê-los, desmarque a opção correspondente nas configurações antes da exclusão. As contas WordPress e o papel básico das pessoas são preservados.

O teste local e os limites conhecidos estão em `VALIDACAO.md`. As licenças das bibliotecas acompanham os respectivos diretórios em `vendor/`.
